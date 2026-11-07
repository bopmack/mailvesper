<?php

namespace App\Http\Controllers\Api\V1\Smtp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\Ses\EventProcessor;
use App\Services\Smtp\Tracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Opens and clicks for email sent through a customer's SMTP server (SES tracks its own). Each one becomes the same
 * event SES would send, so machine-open and link-scanner detection, journeys and webhooks behave identically.
 */
class TrackingController extends Controller
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private EventProcessor $events) {}

    public function open(Request $request, string $id, string $sig): Response
    {
        if (hash_equals(Tracking::sign($id), $sig)) {
            $this->record($request, $id, 'Open', ['userAgent' => (string) $request->userAgent(), 'ipAddress' => $request->ip()]);
        }

        return response((string) base64_decode(self::GIF), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, no-cache, must-revalidate, private', 'Pragma' => 'no-cache']);
    }

    public function click(Request $request, string $id, string $sig): RedirectResponse|Response
    {
        $url = (string) $request->query('u', '');
        // Only signed links redirect, so this can never be used to send people to an arbitrary site.
        if (! preg_match('#^https?://#i', $url) || ! hash_equals(Tracking::sign($id, $url), $sig)) {
            return response('This link isn’t valid.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $this->record($request, $id, 'Click', ['userAgent' => (string) $request->userAgent(), 'ipAddress' => $request->ip(), 'link' => $url]);

        return redirect()->away($url, 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /** @param array<string, mixed> $section */
    private function record(Request $request, string $id, string $type, array $section): void
    {
        try {
            $m = Message::find($id);
            if ($m) {
                $this->events->apply($m->workspace_id, ['eventType' => $type, 'mail' => ['messageId' => $m->ses_message_id ?? '', 'tags' => ['sl_message' => [$m->id]]],
                    lcfirst($type) => $section + ['timestamp' => now()->toIso8601ZuluString()]]);
            }
        } catch (Throwable $e) {
            report($e); // tracking must never break the redirect or the image
        }
    }
}
