<?php

namespace App\Http\Controllers\Api\V1\Smtp;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\SenderIdentity;
use App\Models\SesConnection;
use App\Models\SmtpConnection;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Platform\Brand;
use App\Services\Sending\Senders;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\LimitWatch;
use App\Services\Smtp\SmtpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sending through the workspace's own SMTP server: the connection, which method is active (SES or SMTP), and the
 * senders proven for SMTP (a domain by TXT record, or one address by a code emailed through that server).
 */
class SmtpController extends Controller
{
    use WorkspaceAccess;

    public const CODE_TTL_MINUTES = 30;

    public function __construct(private SmtpClient $smtp, private DnsLookup $dns) {}

    public function show(Workspace $workspace): JsonResponse
    {
        return response()->json($this->state($workspace) + ['alerts' => LimitWatch::active($workspace->id)]);
    }

    /** Who this workspace can send from right now, for From pickers: verified domains and single addresses. */
    public function senders(Workspace $workspace): JsonResponse
    {
        return response()->json(['method' => Senders::method($workspace)] + Senders::verified($workspace) + ['default' => self::defaultFrom($workspace)]);
    }

    /** The address new campaigns, journeys and forms start from: {email, name}, or null to clear it. */
    public function setDefault(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'choose the default sender');
        $d = $request->validate(['email' => ['nullable', 'email', 'max:254'], 'name' => ['nullable', 'string', 'max:100']]);
        $email = isset($d['email']) ? mb_strtolower(trim($d['email'])) : null;
        if ($email !== null && ! Senders::allows($workspace, $email)) {
            throw new ApiException('validation_failed', Senders::method($workspace) === null ? 'Connect Amazon SES or an SMTP server first, then verify this address’s domain.' : 'That address isn’t on a verified domain or a verified address yet.', 422, ['email' => 'Not verified.']);
        }
        $workspace->settings = array_merge($workspace->settings ?? [], ['default_from' => $email ? ['email' => $email, 'name' => isset($d['name']) && trim($d['name']) !== '' ? trim($d['name']) : null] : null]);
        $workspace->save();
        Audit::log('sending.default_sender', $this->currentUser($request), $workspace->id, ['email' => $email]);

        return $this->senders($workspace);
    }

    /** @return array{email: string, name: string|null}|null still allowed to send, or nothing */
    public static function defaultFrom(Workspace $workspace): ?array
    {
        $d = $workspace->settings['default_from'] ?? null;

        return is_array($d) && ! empty($d['email']) && Senders::allows($workspace, (string) $d['email']) ? ['email' => (string) $d['email'], 'name' => $d['name'] ?? null] : null;
    }

    /** Saves the settings and tests them straight away. Saved either way, so a typo can be fixed. */
    public function save(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'change how this workspace sends');
        $existing = SmtpConnection::where('workspace_id', $workspace->id)->first();
        $d = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'], // any port: providers use their own (the host must still be public)
            'encryption' => ['required', Rule::in(SmtpConnection::ENCRYPTION)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'max_per_second' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'max_per_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
        ], ['host.regex' => 'Enter the server name, like smtp.example.com.', 'port.in' => 'Use port 587, 465, 25 or 2525.']);
        $d['host'] = mb_strtolower($d['host']);
        if ($problem = SmtpClient::vet($d['host'])[0]) {
            throw new ApiException('validation_failed', $problem, 422, ['host' => $problem]);
        }
        if (($d['password'] ?? null) === null || str_starts_with((string) $d['password'], '••••')) {
            unset($d['password']); // keep the saved one
        }
        if (! empty($d['username']) && ! isset($d['password']) && ! $existing?->password) {
            throw new ApiException('validation_failed', 'Enter the password (or API key) for this username.', 422, ['password' => 'Enter the password.']);
        }
        if ($d['encryption'] === 'none' && ! empty($d['username'])) {
            throw new ApiException('validation_failed', 'Use TLS or SSL when signing in, so the password isn’t sent in plain text.', 422, ['encryption' => 'Choose TLS or SSL.']);
        }
        $c = SmtpConnection::updateOrCreate(['workspace_id' => $workspace->id], $d);
        $test = $this->runTest($c);
        Audit::log('smtp.saved', $this->currentUser($request), $workspace->id, ['host' => $c->host, 'port' => $c->port, 'ok' => $test['ok']]);
        if ($test['ok'] && $workspace->sending_method === null && ! SesConnection::where('workspace_id', $workspace->id)->exists()) {
            $workspace->forceFill(['sending_method' => Senders::SMTP])->save(); // nothing else to send with: use it
        }

        return response()->json($this->state($workspace->refresh()) + ['test' => $test]);
    }

    public function test(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'test the SMTP server');
        $c = $this->connection($workspace);

        return response()->json($this->state($workspace) + ['test' => $this->runTest($c)]);
    }

    /** Switches between Amazon SES and SMTP. Only to a method that is set up and working. */
    public function use(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'change how this workspace sends');
        $method = $request->validate(['method' => ['required', Rule::in([Senders::SES, Senders::SMTP])]])['method'];
        if ($method === Senders::SMTP && ! SmtpConnection::where('workspace_id', $workspace->id)->whereNotNull('verified_at')->exists()) {
            throw new ApiException('smtp_not_ready', 'Save SMTP settings that pass the connection test first.', 422);
        }
        if ($method === Senders::SES && ! SesConnection::where('workspace_id', $workspace->id)->exists()) {
            throw new ApiException('ses_not_connected', 'Connect Amazon SES first.', 422);
        }
        $workspace->forceFill(['sending_method' => $method])->save();
        Audit::log('sending.method_changed', $this->currentUser($request), $workspace->id, ['method' => $method]);

        return response()->json($this->state($workspace));
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'change how this workspace sends');
        SmtpConnection::where('workspace_id', $workspace->id)->delete();
        if ($workspace->sending_method === Senders::SMTP) {
            $workspace->forceFill(['sending_method' => null])->save();
        }
        Audit::log('smtp.removed', $this->currentUser($request), $workspace->id);

        return response()->json($this->state($workspace->refresh()));
    }

    /** Adds a domain (verified by a TXT record) or a single address (verified by an emailed code). */
    public function addSender(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'add senders');
        $d = $request->validate(['domain' => ['required_without:email', 'nullable', 'string', 'max:253'], 'email' => ['required_without:domain', 'nullable', 'email', 'max:254']]);
        if (SenderIdentity::where('workspace_id', $workspace->id)->count() >= 50) {
            throw new ApiException('sender_limit', 'You can have up to 50 senders.', 422);
        }
        if (! empty($d['domain'])) {
            // People paste URLs: keep just the host, without www.
            $domain = (string) preg_replace('#^(https?://)?(www\.)?#', '', mb_strtolower(trim((string) $d['domain'])));
            $domain = trim((string) strtok($domain, '/?#'), ' .');
            if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain)) {
                throw new ApiException('validation_failed', 'Enter a domain like acme.com.', 422, ['domain' => 'Enter a domain like acme.com.']);
            }
            $s = SenderIdentity::firstOrCreate(['workspace_id' => $workspace->id, 'kind' => 'domain', 'value' => $domain],
                ['token' => Str::lower(Str::random(32)), 'status' => 'pending', 'code_attempts' => 0, 'created_by' => $this->currentUser($request)->id]);
            Audit::log('sender.added', $this->currentUser($request), $workspace->id, ['domain' => $domain]);

            return response()->json(['sender' => $this->present($s)], 201);
        }
        $email = mb_strtolower(trim((string) $d['email']));
        $s = SenderIdentity::firstOrCreate(['workspace_id' => $workspace->id, 'kind' => 'email', 'value' => $email],
            ['token' => '', 'status' => 'pending', 'code_attempts' => 0, 'created_by' => $this->currentUser($request)->id]);
        if ($s->status !== 'verified') {
            $this->sendCode($workspace, $s);
        }
        Audit::log('sender.added', $this->currentUser($request), $workspace->id, ['email' => $email]);

        return response()->json(['sender' => $this->present($s->refresh())], 201);
    }

    /** Domains: look for the TXT record now. */
    public function checkSender(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'check senders');
        $s = $this->sender($workspace, $id);
        if ($s->kind !== 'domain') {
            throw new ApiException('validation_failed', 'Addresses are verified with the emailed code.', 422);
        }
        $found = collect($this->dns->lookup('TXT', '_sendline.'.$s->value))->contains(fn ($t) => trim((string) $t, '" ') === 'sendline-verify='.$s->token);
        $txt = $this->dns->lookup('TXT', $s->value);
        $s->dns_check = [
            'verification' => $found ? 'found' : 'missing',
            'spf' => collect($txt)->contains(fn ($t) => str_starts_with(strtolower(trim((string) $t, '" ')), 'v=spf1')) ? 'found' : 'missing',
            // The record itself, so the page can tell whether the SMTP provider is included in it.
            'spf_record' => mb_substr((string) collect($txt)->map(fn ($t) => trim((string) $t, '" '))->first(fn ($t) => str_starts_with(strtolower($t), 'v=spf1')), 0, 500) ?: null,
            'dmarc' => collect($this->dns->lookup('TXT', '_dmarc.'.$s->value))->contains(fn ($t) => str_starts_with(strtolower(trim((string) $t, '" ')), 'v=dmarc1')) ? 'found' : 'missing',
        ];
        $s->last_checked_at = now();
        if ($found && $s->status !== 'verified') {
            $s->status = 'verified';
            $s->verified_at = now();
            Audit::log('sender.verified', $this->currentUser($request), $workspace->id, ['domain' => $s->value]);
        }
        $s->save();

        return response()->json(['sender' => $this->present($s)]);
    }

    public function confirmSender(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'verify senders');
        $s = $this->sender($workspace, $id);
        $code = preg_replace('/\D/', '', (string) $request->input('code')) ?? '';
        if ($s->kind !== 'email' || $s->status === 'verified') {
            return response()->json(['sender' => $this->present($s)]);
        }
        if ($s->code_attempts >= 5 || ! $s->code_expires_at || $s->code_expires_at->isPast()) {
            throw new ApiException('code_expired', 'That code has expired. Send a new one.', 422, ['code' => 'Send a new code.']);
        }
        if (strlen($code) !== 6 || ! Hash::check($code, $s->token)) {
            $s->increment('code_attempts');
            throw new ApiException('code_invalid', 'That code didn’t match. Check the newest email.', 422, ['code' => 'That code didn’t match.']);
        }
        $s->forceFill(['status' => 'verified', 'verified_at' => now(), 'token' => '', 'code_expires_at' => null])->save();
        Audit::log('sender.verified', $this->currentUser($request), $workspace->id, ['email' => $s->value]);

        return response()->json(['sender' => $this->present($s)]);
    }

    public function resendCode(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'verify senders');
        $s = $this->sender($workspace, $id);
        if ($s->kind === 'email' && $s->status !== 'verified') {
            $this->sendCode($workspace, $s);
        }

        return response()->json(['sender' => $this->present($s->refresh())]);
    }

    public function removeSender(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'remove senders');
        $s = $this->sender($workspace, $id);
        $s->delete();
        Audit::log('sender.removed', $this->currentUser($request), $workspace->id, [$s->kind => $s->value]);

        return response()->json(['deleted' => true]);
    }

    /**
     * The code goes out through the workspace's own SMTP server, from and to the address itself: it proves the
     * address is theirs and that the server will send it.
     */
    private function sendCode(Workspace $workspace, SenderIdentity $s): void
    {
        $c = SmtpConnection::where('workspace_id', $workspace->id)->whereNotNull('verified_at')->first()
            ?? throw new ApiException('smtp_not_ready', 'Save SMTP settings that pass the connection test first; the code is sent through your server.', 422);
        if ($s->code_expires_at && $s->code_expires_at->copy()->subMinutes(self::CODE_TTL_MINUTES)->gt(now()->subSeconds(30))) {
            throw new ApiException('rate_limited', 'A code was just sent. Wait half a minute before asking for another.', 429, retryAfter: 30);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $email = (new Email)->from(new Address($s->value, Brand::name().' verification'))->to($s->value)
            ->subject($code.' is your '.Brand::name().' verification code') // a subject ending in digits scores as spam
            ->text("Enter $code in ".Brand::name()." to confirm that {$s->value} is yours and that your SMTP server can send for it.\n\nThe code expires in ".self::CODE_TTL_MINUTES.' minutes. If you didn’t ask for it, ignore this email.')
            ->html('<p style="font-family:-apple-system,Helvetica,Arial,sans-serif;font-size:15px;color:#18181B">Enter this code in '.Brand::name().' to confirm that <b>'.e($s->value).'</b> is yours and that your SMTP server can send for it:</p>'
                .'<p style="font-family:Menlo,monospace;font-size:28px;letter-spacing:6px;color:#18181B">'.$code.'</p>'
                .'<p style="font-family:-apple-system,Helvetica,Arial,sans-serif;font-size:13px;color:#71717A">It expires in '.self::CODE_TTL_MINUTES.' minutes. If you didn’t ask for it, ignore this email.</p>');
        // Saved before sending, so a code that arrives always works.
        $s->forceFill(['token' => Hash::make($code), 'code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES), 'code_attempts' => 0])->save();
        try {
            $this->smtp->send($c, $email, new Envelope(new Address($s->value), [new Address($s->value)]));
        } catch (Throwable $e) {
            $s->forceFill(['token' => '', 'code_expires_at' => null])->save();
            throw new ApiException('smtp_send_failed', 'Your SMTP server didn’t send the code: '.SmtpClient::explain($e, $c), 422);
        }
    }

    /** @return array{ok: bool, message: string} */
    private function runTest(SmtpConnection $c): array
    {
        $r = $this->smtp->test($c);
        $c->forceFill(['verified_at' => $r['ok'] ? now() : null, 'last_error' => $r['ok'] ? null : $r['message'], 'last_checked_at' => now()])->save();

        return $r;
    }

    /** @return array<string, mixed> */
    private function state(Workspace $ws): array
    {
        $c = SmtpConnection::where('workspace_id', $ws->id)->first();

        return [
            'method' => Senders::method($ws),
            'ses_connected' => SesConnection::where('workspace_id', $ws->id)->exists(),
            'smtp' => $c ? ['host' => $c->host, 'port' => $c->port, 'encryption' => $c->encryption, 'username' => $c->username,
                'password' => $c->password ? '••••••••' : null, 'max_per_second' => $c->max_per_second, 'max_per_day' => $c->max_per_day,
                'working' => $c->verified_at !== null, 'last_error' => $c->last_error, 'last_checked_at' => $c->last_checked_at?->toIso8601ZuluString()] : null,
            'senders' => SenderIdentity::where('workspace_id', $ws->id)->orderBy('kind')->orderBy('value')->get()->map(fn (SenderIdentity $s) => $this->present($s))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(SenderIdentity $s): array
    {
        $out = ['id' => $s->id, 'kind' => $s->kind, 'value' => $s->value, 'status' => $s->status, 'dns_check' => $s->dns_check,
            'verified_at' => $s->verified_at?->toIso8601ZuluString(), 'last_checked_at' => $s->last_checked_at?->toIso8601ZuluString(),
            'code_sent' => $s->kind === 'email' && $s->code_expires_at !== null && $s->code_expires_at->isFuture()];
        if ($s->kind === 'domain') { // the TXT record to publish (public by nature)
            $out['record'] = ['type' => 'TXT', 'host' => '_sendline.'.$s->value, 'value' => 'sendline-verify='.$s->token];
        }

        return $out;
    }

    private function connection(Workspace $ws): SmtpConnection
    {
        return SmtpConnection::where('workspace_id', $ws->id)->first() ?? throw new ApiException('smtp_not_ready', 'Add your SMTP settings first.', 422);
    }

    private function sender(Workspace $ws, string $id): SenderIdentity
    {
        return SenderIdentity::where('workspace_id', $ws->id)->where('id', $id)->first() ?? throw new ApiException('not_found', 'Not found.', 404);
    }
}
