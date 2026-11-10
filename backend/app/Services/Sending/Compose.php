<?php

namespace App\Services\Sending;

use App\Services\Platform\Brand;

/** Pure helpers for building an email: addresses, merge tags, plain text, headers. No I/O. */
class Compose
{
    /** Headers callers may not set; we own these. */
    public const RESERVED_HEADERS = ['from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'sender', 'return-path', 'date', 'message-id', 'mime-version',
        'content-type', 'content-transfer-encoding', 'content-disposition', 'list-unsubscribe', 'list-unsubscribe-post', 'dkim-signature', 'received', 'x-ses-configuration-set',
        'x-sendline-tracking'];

    /**
     * "Ada Lovelace <ada@example.com>" or "ada@example.com" → [email, name|null]. Null when invalid.
     *
     * @return array{0: string, 1: string|null}|null
     */
    public static function parseAddress(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^<>\s]+)>\s*$/u', $raw, $m)) {
            [$name, $email] = [trim($m[1]), trim($m[2])];
        } else {
            [$name, $email] = ['', $raw];
        }
        if (preg_match('/[\r\n]/', $raw) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return [$local.'@'.strtolower($domain), $name !== '' ? mb_substr($name, 0, 120) : null];
    }

    /** Formats a sender for the From header, encoding non-ASCII names (RFC 2047). */
    public static function formatAddress(string $email, ?string $name): string
    {
        if ($name === null || $name === '') {
            return $email;
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return '=?UTF-8?B?'.base64_encode($name).'?= <'.$email.'>';
        }

        // Quoted only when the name needs it (commas, dots, brackets…): "Acme <a@b.com>", like a person's mail app.
        return preg_match('/[()<>\[\]:;@\\,."]/', $name) ? '"'.addcslashes($name, '"\\').'" <'.$email.'>' : $name.' <'.$email.'>';
    }

    public static function domainOf(string $email): string
    {
        return strtolower(substr($email, strrpos($email, '@') + 1));
    }

    /**
     * Replaces {{ name }} and {{ name | fallback }}. A fallback can name other tags to try first, then the text:
     * {{ real_first_name | first_name | there }}. Values are HTML-escaped when $html is true.
     *
     * @param  array<string, scalar|null>  $vars
     */
    public static function merge(string $template, array $vars, bool $html): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?:\|\s*([^}]*?))?\s*\}\}/', function ($m) use ($vars, $html) {
            $value = $vars[$m[1]] ?? null;
            if (($value === null || $value === '') && isset($m[2])) {
                $parts = explode('|', $m[2]);
                $last = count($parts) - 1;
                foreach ($parts as $i => $part) {
                    $part = trim($part);
                    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part) && array_key_exists($part, $vars)) {
                        $value = $vars[$part]; // another tag: its value, or on to the next fallback
                    } elseif (count($parts) > 1 && $i < $last && ! preg_match('/^["\']/', $part)) {
                        continue; // a tag this person doesn't have
                    } else {
                        $value = trim($part, " \t\"'");
                    }
                    if ($value !== null && $value !== '') {
                        break;
                    }
                }
            }
            $value ??= '';
            $value = (string) $value;

            return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
        }, $template);
    }

    /**
     * Names typed in capitals or all lower case ("AMARACHI", "mary") read as "Amarachi" and "Mary" in an email; names
     * already in mixed case ("McDonald", "DeShawn") are left alone. Only name placeholders (first_name, last_name,
     * real_first_name…), never other details.
     *
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>
     */
    public static function tidyNames(array $vars): array
    {
        foreach ($vars as $k => $v) {
            if (is_string($v) && preg_match('/(^|_)(first|last)_name$/', (string) $k) && preg_match('/\p{L}{2}/u', $v)
                && ($v === mb_strtoupper($v) || $v === mb_strtolower($v))) {
                $vars[$k] = mb_convert_case(mb_strtolower($v), MB_CASE_TITLE, 'UTF-8');
            }
        }

        return $vars;
    }

    /** Readable plain text from HTML: keeps links as "text (url)", paragraphs and list items. */
    public static function htmlToText(string $html): string
    {
        $s = (string) preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html);
        $s = (string) preg_replace_callback('#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
            $text = trim(strip_tags($m[2]));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $text === '' || $text === $url || str_starts_with($url, 'mailto:') && substr($url, 7) === $text ? ($text ?: $url) : "$text ($url)";
        }, $s);
        $s = (string) preg_replace('#<br\s*/?>#i', "\n", $s);
        $s = (string) preg_replace('#<li\b[^>]*>#i', "\n- ", $s);
        $s = (string) preg_replace('#</(p|div|h[1-6]|tr|table|ul|ol|blockquote)>#i', "\n\n", $s);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\u{00A0}", ' ', $s);
        $lines = array_map(fn ($l) => trim((string) preg_replace('/[ \t]+/', ' ', $l)), explode("\n", $s));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * Gmail's Feedback-ID (Postmaster Tools → Feedback loop): spam rates per campaign:type:workspace, signed for the
     * sender's own DKIM domain. Gmail never says who complained; this is how its totals say which mail. Null when the
     * caller set their own.
     *
     * @param  array<string, mixed>|null  $headers
     */
    public static function feedbackId(?string $campaignId, ?string $journeyId, string $type, string $workspaceId, ?array $headers): ?string
    {
        foreach (array_keys($headers ?? []) as $name) {
            if (strtolower((string) $name) === 'feedback-id') {
                return null;
            }
        }
        $clean = fn (string $v) => (string) preg_replace('/[^A-Za-z0-9]/', '', $v);

        return implode(':', [$clean($campaignId ?? $journeyId ?? 'api') ?: 'api', $clean($type) ?: 'mail', $clean($workspaceId), 'sendline']);
    }

    /**
     * Validates caller headers. Returns an error message, or null when fine.
     *
     * @param  array<mixed>  $headers
     */
    public static function headerError(array $headers): ?string
    {
        if (count($headers) > 20) {
            return 'Send at most 20 custom headers.';
        }
        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,75}$/', $name)) {
                return 'Header names may only use letters, digits and hyphens.';
            }
            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                return Brand::name()." sets the $name header itself.";
            }
            if (! is_scalar($value) || preg_match('/[\r\n]/', (string) $value) || strlen((string) $value) > 870) {
                return "The $name header value must be one line of text.";
            }
        }

        return null;
    }
}
