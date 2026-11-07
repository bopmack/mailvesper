<?php

namespace App\Http\Controllers\Api\V1\Sending;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Response;

/** "View in browser": the email exactly as this recipient got it. Signed link; scripts can't run. */
class WebViewController extends Controller
{
    public static function signature(string $messageId): string
    {
        return substr(hash_hmac('sha256', 'view:'.$messageId, (string) config('app.key')), 0, 20);
    }

    public static function url(string $messageId): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/api/v1/view/'.$messageId.'/'.self::signature($messageId);
    }

    public function __invoke(string $id, string $sig): Response
    {
        $m = hash_equals(self::signature($id), $sig) ? Message::find($id) : null;
        $html = null;
        if ($m) {
            $html = $m->html ?? ($m->text !== null ? '<pre style="white-space:pre-wrap;font:15px/1.6 system-ui,sans-serif;max-width:640px;margin:40px auto">'.e($m->text).'</pre>' : null);
        }
        if ($html === null) {
            return response('<!doctype html><meta charset="utf-8"><title>Not available</title><p style="font:16px system-ui;margin:60px auto;max-width:480px;text-align:center">This email is no longer available.</p>', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex',
            'Content-Security-Policy' => "sandbox allow-popups allow-popups-to-escape-sandbox; default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:",
        ]);
    }
}
