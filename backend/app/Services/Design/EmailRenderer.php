<?php

namespace App\Services\Design;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a builder design into the email HTML we send, in plain PHP (EmailHtml, a port of app/email-render.js),
 * so the HTML sent is byte-for-byte the HTML the builder previews and the server needs no Node.js.
 */
class EmailRenderer
{
    /**
     * @param  array<string, mixed>  $doc  validated builder document
     * @return array{html: string, kb: float, checks: list<array{name: string, status: string, detail: string}>}
     */
    public function render(array $doc, string $subject = ''): array
    {
        try {
            $r = new EmailHtml(rtrim((string) config('app.frontend_url'), '/').'/app');
            $html = $r->html($doc, $subject);
            $c = $r->checks($doc);
        } catch (Throwable $e) {
            Log::warning('Email render failed', ['error' => mb_substr($e->getMessage(), 0, 500)]);
            throw new ApiException('render_failed', 'This design couldn’t be turned into an email. Undo the last change, or remove the block you just edited.', 422);
        }

        return ['html' => $html, 'kb' => floor($c['kb'] * 10 + .5) / 10, 'checks' => $c['list']];
    }
}
