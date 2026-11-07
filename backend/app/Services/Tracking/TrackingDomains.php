<?php

namespace App\Services\Tracking;

use App\Exceptions\ApiException;
use App\Models\SesConnection;
use App\Models\TrackingDomain;
use App\Services\Platform\Brand;
use App\Services\Platform\Settings;
use App\Services\Ses\AwsFactory;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\SesCatalog;
use App\Services\Smtp\Tracking;
use App\Services\Webhooks\UrlGuard;
use Aws\Exception\AwsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A workspace's own link-tracking domain. It is used only once it works end to end: its CNAME points at this
 * MailVesper, and https://<domain>/…/v1/t/ping answers with this installation's token over a valid certificate
 * (the installation's web server must have a certificate for it: cPanel AutoSSL, certbot, or Cloudflare's proxy).
 * Checked again regularly; if it stops working, links quietly go back to the shared tracking address.
 */
class TrackingDomains
{
    public function __construct(private DnsLookup $dns, private AwsFactory $aws) {}

    /** Where customers point their CNAME: a platform setting, else this installation's own host. */
    public static function target(): string
    {
        return mb_strtolower((string) (Settings::get('tracking.cname_target') ?: parse_url((string) config('app.url'), PHP_URL_HOST)));
    }

    public static function normalise(string $host): string
    {
        $host = (string) preg_replace('#^https?://#i', '', mb_strtolower(trim($host)));
        $host = trim(explode('/', $host, 2)[0], '.'); // a pasted URL: just its host
        if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $host) || substr_count($host, '.') < 2) {
            throw new ApiException('validation_failed', 'Use a subdomain you own, like links.yourcompany.com.', 422, ['host' => 'Invalid host.']);
        }
        if ($host === self::target() || $host === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            throw new ApiException('validation_failed', 'That’s '.Brand::name().'’s own address. Use a subdomain of your domain.', 422, ['host' => 'Invalid host.']);
        }

        return $host;
    }

    /** Runs the checks and switches the domain on or off accordingly. */
    public function check(TrackingDomain $d): TrackingDomain
    {
        $problem = $this->problem($d->host);
        $was = $d->status;
        $d->forceFill(['last_checked_at' => now(), 'last_error' => $problem, 'status' => $problem ? 'pending' : 'active', 'verified_at' => $problem ? $d->verified_at : ($d->verified_at ?? now())])->save();
        if (! $problem && ! $d->ses_ready && ($conn = SesConnection::where('workspace_id', $d->workspace_id)->first())) {
            $d->forceFill(['ses_ready' => $this->prepareSes($conn)])->save();
        }
        if ($was !== $d->status) {
            Tracking::flush();
        }

        return $d;
    }

    /** Why the domain can't be used yet, or null when it works. */
    public function problem(string $host): ?string
    {
        $target = self::target();
        $cname = $this->dns->lookup('CNAME', $host);
        $routed = in_array($target, $cname, true) || (bool) array_intersect($this->dns->lookup('A', $host), $this->dns->lookup('A', $target) ?: ['-']);
        $url = rtrim(Tracking::baseFor($host), '/').'/ping';
        [$bad, $ip] = UrlGuard::vet($url);
        if ($bad) {
            return $cname || $routed ? $bad : "Add a CNAME record: $host → $target.";
        }
        try {
            $r = Http::timeout(8)->connectTimeout(5)->withoutRedirecting()->withOptions(['curl' => [CURLOPT_RESOLVE => ["$host:443:$ip"]]])->get($url);
            if (trim($r->body()) === Tracking::pingToken()) {
                return null;
            }
        } catch (ConnectionException $e) {
            if (preg_match('/SSL|certificate/i', $e->getMessage())) {
                return "$host points here, but its HTTPS certificate isn’t valid yet. The server needs a certificate for $host (cPanel AutoSSL, certbot or Cloudflare), then check again.";
            }
        }

        return $routed ? "$host points here, but it doesn’t answer with ".Brand::name()." yet. The web server must serve $host like ".$target.', with a certificate for it.' : "Add a CNAME record: $host → $target.";
    }

    /** A copy of the events configuration set without SES's own open and click tracking. */
    private function prepareSes(SesConnection $conn): bool
    {
        if (! $conn->sns_topic_arn) {
            return false;
        }
        $ses = $this->aws->ses($conn);
        $name = SesConnection::CONFIG_SET_OWN_TRACKING;
        $dest = ['Enabled' => true, 'MatchingEventTypes' => array_values(array_diff(SesCatalog::EVENT_TYPES, ['OPEN', 'CLICK'])), 'SnsDestination' => ['TopicArn' => $conn->sns_topic_arn]];
        try {
            try {
                $ses->createConfigurationSet(['ConfigurationSetName' => $name, 'ReputationOptions' => ['ReputationMetricsEnabled' => true], 'SendingOptions' => ['SendingEnabled' => true]]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                    throw $e;
                }
            }
            try {
                $ses->createConfigurationSetEventDestination(['ConfigurationSetName' => $name, 'EventDestinationName' => 'sendline', 'EventDestination' => $dest]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                    throw $e;
                }
                $ses->updateConfigurationSetEventDestination(['ConfigurationSetName' => $name, 'EventDestinationName' => 'sendline', 'EventDestination' => $dest]);
            }

            return true;
        } catch (AwsException $e) {
            report($e); // SES keeps its own tracking until this works; the next check tries again

            return false;
        }
    }
}
