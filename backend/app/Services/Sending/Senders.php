<?php

namespace App\Services\Sending;

use App\Models\Domain;
use App\Models\SenderIdentity;
use App\Models\SesConnection;
use App\Models\SmtpConnection;
use App\Models\Workspace;

/**
 * How a workspace sends (Amazon SES or its own SMTP server) and which From addresses it may use. Everything that
 * asks "can this workspace send?" or "is this sender allowed?" goes through here, so both methods follow one rule.
 */
class Senders
{
    public const SES = 'ses';

    public const SMTP = 'smtp';

    /** The active method, or null when nothing is connected. An SMTP server counts once its connection test passed. */
    public static function method(Workspace|string $ws): ?string
    {
        $ws = $ws instanceof Workspace ? $ws : Workspace::find($ws);
        if (! $ws) {
            return null;
        }
        $ses = SesConnection::where('workspace_id', $ws->id)->exists();
        $smtp = SmtpConnection::where('workspace_id', $ws->id)->whereNotNull('verified_at')->exists();

        return match (true) {
            $ws->sending_method === self::SMTP && $smtp => self::SMTP,
            $ws->sending_method === self::SES && $ses => self::SES,
            $ses => self::SES,     // installations from before SMTP existed: SES, exactly as before
            $smtp => self::SMTP,
            default => null,
        };
    }

    /**
     * Verified senders for the active method: domains (any address on them or their subdomains) and single addresses.
     *
     * @return array{domains: list<string>, emails: list<string>}
     */
    public static function verified(Workspace|string $ws): array
    {
        $id = $ws instanceof Workspace ? $ws->id : $ws;
        if (self::method($ws) === self::SMTP) {
            $rows = SenderIdentity::where('workspace_id', $id)->where('status', 'verified')->get(['kind', 'value']);

            return ['domains' => $rows->where('kind', 'domain')->pluck('value')->values()->all(), 'emails' => $rows->where('kind', 'email')->pluck('value')->values()->all()];
        }

        return ['domains' => Domain::where('workspace_id', $id)->where('status', 'verified')->pluck('name')->all(), 'emails' => []];
    }

    public static function allows(Workspace|string $ws, string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $domain = substr((string) strrchr($email, '@'), 1);
        $v = self::verified($ws);
        if (in_array($email, $v['emails'], true)) {
            return true;
        }
        foreach ($v['domains'] as $d) {
            if ($domain === $d || str_ends_with($domain, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    /** Human list for error messages, e.g. "acme.com, hello@shop.ng". */
    public static function describe(Workspace|string $ws): string
    {
        $v = self::verified($ws);

        return implode(', ', array_merge($v['domains'], $v['emails']));
    }

    /** Where the customer verifies senders for the active method (named in error messages). */
    public static function where(Workspace|string $ws): string
    {
        return self::method($ws) === self::SMTP ? 'Sending → Senders' : 'Domains';
    }
}
