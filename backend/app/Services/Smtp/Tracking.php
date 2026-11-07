<?php

namespace App\Services\Smtp;

use App\Models\Message;
use App\Models\TrackingDomain;

/**
 * MailVesper's own open and click tracking: an invisible image and signed redirects, recorded through the same
 * pipeline as SES events. Used for SMTP sending, and for SES sending once a workspace has its own tracking domain
 * (SES's tracking can't use the customer's domain over HTTPS without CloudFront).
 */
class Tracking
{
    /** @var array<string, array{0: string|null, 1: bool, 2: float}> workspace => [active tracking host, SES ready, until] */
    private static array $hosts = [];

    /** The workspace's own tracking host while it's active, else null (cached for a minute: workers run long). */
    public static function host(string $workspaceId): ?string
    {
        return self::domain($workspaceId)[0];
    }

    /** Whether SES emails of this workspace are tracked by MailVesper on its own domain instead of by SES. */
    public static function ownForSes(string $workspaceId): bool
    {
        [$host, $ses] = self::domain($workspaceId);

        return $host !== null && $ses;
    }

    /** @return array{0: string|null, 1: bool, 2: float} */
    private static function domain(string $workspaceId): array
    {
        $hit = self::$hosts[$workspaceId] ?? null;
        if ($hit && $hit[2] > microtime(true)) {
            return $hit;
        }
        $d = TrackingDomain::where('workspace_id', $workspaceId)->where('status', 'active')->first(['host', 'ses_ready']);

        return self::$hosts[$workspaceId] = [$d?->host, (bool) $d?->ses_ready, microtime(true) + 60];
    }

    public static function flush(): void
    {
        self::$hosts = [];
    }

    /** What /v1/t/ping answers, so a tracking domain can be checked end to end. */
    public static function pingToken(): string
    {
        return 'sendline-tracking:'.substr(hash_hmac('sha256', 'tracking-ping', (string) config('app.key')), 0, 16);
    }

    /** The tracking address, on $host instead of MailVesper's own host when given (same path: the API's). */
    public static function baseFor(?string $host): string
    {
        $base = self::base();

        return $host ? (string) preg_replace('#^https?://[^/]+#i', 'https://'.$host, $base) : $base;
    }

    public static function sign(string $messageId, string $payload = ''): string
    {
        return substr(hash_hmac('sha256', $messageId.'|'.$payload, (string) config('app.key')), 0, 24);
    }

    public static function base(): string
    {
        return rtrim((string) config('app.url'), '/').'/v1/t';
    }

    public static function pixel(Message $m): string
    {
        return self::baseFor(self::host($m->workspace_id)).'/o/'.$m->id.'/'.self::sign($m->id).'.gif';
    }

    public static function link(Message $m, string $url): string
    {
        return self::baseFor(self::host($m->workspace_id)).'/c/'.$m->id.'/'.self::sign($m->id, $url).'?u='.rawurlencode($url);
    }

    /**
     * Rewrites http(s) links (not mailto:, tel:, anchors, unsubscribe links or links already tracked) and adds the
     * open pixel just before </body>.
     */
    public static function apply(Message $m, string $html): string
    {
        $base = self::baseFor(self::host($m->workspace_id));
        $own = self::base();
        $html = (string) preg_replace_callback('/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/i', function ($x) use ($m, $base, $own) {
            $url = html_entity_decode($x[3], ENT_QUOTES | ENT_HTML5);
            if (str_starts_with($url, $base) || str_starts_with($url, $own) || str_contains($url, '/u/?t=') || str_contains($url, '/a/?t=') || str_contains($url, '/v1/unsubscribe/') || preg_match('/\{\{.*\}\}/', $url)) {
                return $x[0];
            }

            return $x[1].$x[2].htmlspecialchars(self::link($m, $url), ENT_QUOTES).$x[2];
        }, $html);
        $img = '<img src="'.htmlspecialchars(self::pixel($m), ENT_QUOTES).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0" />';

        return stripos($html, '</body>') !== false ? (string) preg_replace('/<\/body>/i', $img.'</body>', $html, 1) : $html.$img;
    }
}
