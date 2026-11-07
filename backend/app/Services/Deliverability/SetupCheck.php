<?php

namespace App\Services\Deliverability;

use App\Exceptions\ApiException;
use App\Models\DeliverabilityCheck;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Mailbox\ImapClient;
use App\Services\Mailbox\MailboxError;
use App\Services\Mailbox\PlatformMailbox;
use App\Services\Platform\Brand;
use App\Services\Platform\Settings;
use App\Services\Sending\Mailer;
use App\Services\Sending\Senders;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Str;

/**
 * "Check my setup": sends a real email from the workspace's sender through its own route (SES or SMTP) to the
 * installation's check mailbox, then reads it back and analyses what a receiving provider sees. The answer is
 * the same SPF / DKIM / DMARC verdict Gmail would reach, from the exact path the customer's email takes.
 */
class SetupCheck
{
    /** Minutes to wait for the test email before giving up. */
    public const WAIT_MINUTES = 15;

    public function __construct(private Mailer $mailer, private Analyzer $analyzer) {}

    public static function configured(): bool
    {
        return PlatformMailbox::configured('checks');
    }

    public function start(Workspace $ws, string $from): DeliverabilityCheck
    {
        if (! self::configured()) {
            throw new ApiException('checks_off', 'Setup checks aren’t set up on this installation yet (the installation admin sets a check mailbox in the Setup checks settings).', 422);
        }
        $from = mb_strtolower(trim($from));
        if (! Senders::allows($ws, $from)) {
            throw new ApiException('validation_failed', 'Choose a verified sender address first.', 422, ['from' => 'Not verified.']);
        }
        $token = Str::lower(Str::random(24));
        $check = DeliverabilityCheck::create(['workspace_id' => $ws->id, 'domain' => substr((string) strrchr($from, '@'), 1), 'from_email' => $from,
            'token' => $token, 'status' => 'waiting', 'created_at' => now()]);
        $p = $this->mailer->validate($ws, ['from' => $from, 'to' => (string) Settings::get('checks.address'), 'subject' => "MailVesper setup check $token",
            'html' => '<p>This email checks how your domain’s email reaches inboxes: the route it takes, SPF, DKIM and DMARC.</p><p>It was sent by '.Brand::name().'’s “Check my setup” and only goes to '.Brand::name().'’s check mailbox.</p>',
            'text' => "This email checks how your domain’s email reaches inboxes: the route it takes, SPF, DKIM and DMARC.\nIt was sent by ".Brand::name().'’s “Check my setup” and only goes to '.Brand::name().'’s check mailbox.',
            'type' => 'transactional']);
        try {
            $m = $this->mailer->submit($ws, [$p], 'test')[0];
        } catch (ApiException $e) {
            $check->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()])->save();
            throw $e;
        }
        $check->forceFill(['message_id' => $m->id])->save();

        return $check;
    }

    /** Reads the check mailbox for every waiting check. Returns how many finished. */
    public function collect(): int
    {
        $waiting = DeliverabilityCheck::where('status', 'waiting')->orderBy('created_at')->limit(50)->get();
        if ($waiting->isEmpty() || ! self::configured()) {
            return 0;
        }
        $done = 0;
        try {
            $conn = ImapClient::connect(PlatformMailbox::make('checks'));
        } catch (MailboxError $e) {
            report($e);

            return 0;
        }
        try {
            $conn->select('INBOX');
            foreach ($waiting as $c) {
                $uid = $conn->searchSubject($c->token)[0] ?? null;
                $raw = $uid ? $conn->fetch($uid) : null;
                if ($raw !== null) {
                    $c->forceFill(['status' => 'done', 'result' => $this->sameServer($this->analyzer->analyze($raw)), 'finished_at' => now()])->save();
                    $done++;
                } elseif ($c->created_at->lt(now()->subMinutes(self::WAIT_MINUTES))) {
                    $c->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => $this->whyMissing($c)])->save();
                }
            }
        } finally {
            $conn->close();
        }

        return $done;
    }

    /**
     * When the customer's mail server also hosts the check mailbox, the test never crossed the internet: many
     * servers only DKIM-sign mail that leaves, so a missing signature here says nothing about mail to Gmail.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function sameServer(array $r): array
    {
        $ips = app(DnsLookup::class)->lookup('A', (string) Settings::get('checks.imap_host'));
        $r['same_server'] = $r['sending_ip'] && in_array($r['sending_ip'], $ips, true);
        if ($r['same_server'] && ! array_filter($r['dkim'], fn ($s) => $s['result'] === 'pass')) {
            $r['verdict'] = ['level' => 'warn', 'title' => 'This test stayed on one mail server, so DKIM couldn’t be judged'];
        }

        return $r;
    }

    private function whyMissing(DeliverabilityCheck $c): string
    {
        $m = $c->message_id ? Message::find($c->message_id) : null;
        if ($m && in_array($m->status, ['failed', 'rejected', 'bounced', 'suppressed'], true)) {
            return 'The test email wasn’t delivered: '.mb_substr((string) ($m->error ?: $m->status), 0, 200);
        }

        return 'The test email didn’t arrive within '.self::WAIT_MINUTES.' minutes. Your server may be holding it, or rejecting mail to other domains. Try again, or check your provider’s sending log.';
    }
}
