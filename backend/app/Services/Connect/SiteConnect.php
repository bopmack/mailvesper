<?php

namespace App\Services\Connect;

use App\Exceptions\ApiException;
use App\Models\ApiKey;
use App\Models\SiteConnection;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Sending\Senders;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Connect to MailVesper" for websites, an OAuth-style flow with PKCE (RFC 7636) and no client registration:
 * the site sends its owner to this installation's connect page; the owner signs in, picks a workspace that can
 * send, and approves the site (shown by its address). MailVesper returns to the site with a one-time code; the
 * site exchanges it, with the verifier only it knows, for its own full-access API key. Any MailVesper (hosted or
 * self-hosted) works the same way; the site finds the connect page from GET /v1/meta.
 */
class SiteConnect
{
    public const CODE_MINUTES = 5;

    /**
     * The address a site wants to be sent back to: HTTPS (plain http only for localhost), a host, no
     * credentials or fragment. Returns [redirect_uri, site_url].
     *
     * @return array{0: string, 1: string}
     */
    public static function redirect(string $uri): array
    {
        $bad = new ApiException('invalid_redirect', 'The site’s return address isn’t valid. Start connecting again from the site.', 422);
        $p = parse_url(trim($uri));
        if (! $p || strlen($uri) > 500 || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
            throw $bad;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower((string) $p['host']);
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $local)) {
            throw $bad;
        }
        if (! preg_match('/^[a-z0-9.-]{1,253}$/', $host)) {
            throw $bad;
        }
        $site = $scheme.'://'.$host.(isset($p['port']) ? ':'.$p['port'] : '');

        return [trim($uri), $site];
    }

    /**
     * Can this workspace send email? A provider (SES or a tested SMTP server) and a verified domain or sender.
     *
     * @return array{ready: bool, method: string|null, domains: list<string>, emails: list<string>, reason: string|null}
     */
    public static function readiness(Workspace $ws): array
    {
        $method = Senders::method($ws);
        $v = Senders::verified($ws);
        $reason = match (true) {
            $method === null => 'Connect a sending provider first: your own Amazon SES or any SMTP server.',
            ! $v['domains'] && ! $v['emails'] => 'Verify the domain or address you’ll send from first.',
            default => null,
        };

        return ['ready' => $reason === null, 'method' => $method, 'domains' => $v['domains'], 'emails' => $v['emails'], 'reason' => $reason];
    }

    /** Can this workspace send from this address? */
    public static function canSendFrom(Workspace $ws, string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && Senders::method($ws) !== null && Senders::allows($ws, $email);
    }

    /**
     * The owner approved the site: a one-time code for it, and the address to send the browser back to.
     */
    public function approve(Workspace $ws, User $user, string $redirectUri, string $state, string $challenge, string $name): string
    {
        [$redirectUri, $site] = self::redirect($redirectUri);
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) { // base64url(SHA-256) of the site's verifier
            throw new ApiException('invalid_request', 'The site’s request is missing its security check. Start connecting again from the site.', 422);
        }
        if (! preg_match('/^[\x21-\x7e]{8,200}$/', $state)) {
            throw new ApiException('invalid_request', 'The site’s request is missing its state. Start connecting again from the site.', 422);
        }
        $ready = self::readiness($ws);
        if (! $ready['ready']) {
            throw new ApiException('not_ready', (string) $ready['reason'], 422);
        }
        $code = Str::random(48);
        SiteConnection::create(['workspace_id' => $ws->id, 'site_url' => $site, 'name' => mb_substr(trim($name) ?: $site, 0, 120), 'redirect_uri' => $redirectUri,
            'code_hash' => hash('sha256', $code), 'code_challenge' => $challenge, 'code_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'created_by' => $user->id]);
        Audit::log('site.approved', $user, $ws->id, ['site' => $site]);

        return self::back($redirectUri, ['code' => $code, 'state' => $state]);
    }

    /**
     * The redirect address with query values added.
     *
     * @param  array<string, string>  $query
     */
    public static function back(string $redirectUri, array $query): string
    {
        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * The site exchanges its code (once, within minutes, with the verifier) for its API key. Connecting the same site
     * to the same workspace again replaces its earlier key.
     *
     * @return array{api_key: string, connection_id: string, workspace: array{id: string, name: string}, site_url: string}
     */
    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $bad = fn () => new ApiException('invalid_grant', 'This connection code isn’t valid any more. Start connecting again.', 400);
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $code) || ! preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            throw $bad();
        }

        return DB::transaction(function () use ($code, $verifier, $redirectUri, $bad) {
            $c = SiteConnection::where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if (! $c || ! $c->code_expires_at || $c->code_expires_at->isPast() || $c->revoked_at || ! hash_equals($c->redirect_uri, $redirectUri)) {
                throw $bad();
            }
            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (! hash_equals((string) $c->code_challenge, $expected)) {
                throw $bad();
            }
            $ws = Workspace::find($c->workspace_id) ?? throw $bad();
            // An earlier connection of this site to this workspace is replaced.
            foreach (SiteConnection::where('workspace_id', $ws->id)->where('site_url', $c->site_url)->whereNull('revoked_at')->whereNotNull('connected_at')->get() as $old) {
                $this->revokeRow($old);
            }
            [$key, $plain] = ApiKey::generate($ws->id, mb_substr('Site: '.parse_url($c->site_url, PHP_URL_HOST), 0, 80), 'full', $c->created_by);
            $c->forceFill(['api_key_id' => $key->id, 'connected_at' => now(), 'code_hash' => null, 'code_challenge' => null, 'code_expires_at' => null])->save();
            Audit::log('site.connected', null, $ws->id, ['site' => $c->site_url, 'key' => $key->prefix]);

            return ['api_key' => $plain, 'connection_id' => $c->id, 'workspace' => ['id' => $ws->id, 'name' => $ws->name], 'site_url' => $c->site_url];
        });
    }

    public function revoke(SiteConnection $c, ?User $user): void
    {
        $this->revokeRow($c);
        Audit::log('site.disconnected', $user, $c->workspace_id, ['site' => $c->site_url]);
    }

    private function revokeRow(SiteConnection $c): void
    {
        $c->forceFill(['revoked_at' => now(), 'code_hash' => null])->save();
        if ($c->api_key_id) {
            ApiKey::whereKey($c->api_key_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
    }

    /** The connection an API key belongs to, if it was made by "Connect to MailVesper". */
    public static function forKey(ApiKey $key): ?SiteConnection
    {
        return SiteConnection::where('api_key_id', $key->id)->whereNull('revoked_at')->first();
    }

    /**
     * The address MailVesper reads the site's users from (live, read-only). It must be public HTTPS on the host the site
     * connected from. Returns the secret that signs MailVesper's requests to it.
     */
    public static function setAudienceHook(SiteConnection $c, string $url): string
    {
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host !== strtolower((string) parse_url($c->site_url, PHP_URL_HOST))) {
            throw new ApiException('validation_failed', 'The audience address must be on '.parse_url($c->site_url, PHP_URL_HOST).'.', 422, ['url' => 'Wrong host.']);
        }
        if (strlen($url) > 500 || ($problem = UrlGuard::problem($url)) !== null) {
            throw new ApiException('validation_failed', $problem ?? 'That address is too long.', 422, ['url' => $problem ?? 'Too long.']);
        }
        $secret = WebhookEndpoint::newSecret();
        $c->forceFill(['audience_url' => $url, 'audience_secret' => $secret, 'audience_error' => null])->save();

        return $secret;
    }
}
