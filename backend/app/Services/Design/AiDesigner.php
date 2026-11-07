<?php

namespace App\Services\Design;

use App\Exceptions\ApiException;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiClient;
use App\Services\Platform\Settings;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Design with AI" (Community): a written brief becomes an editable template. The model only chooses sections and
 * writes the words; EmailDesigner builds the real design from the brand kit (escaped text, hosted photos or the
 * workspace's own media, a compliant footer), so the result is a normal builder design, never raw HTML.
 */
class AiDesigner
{
    public const TONES = ['friendly' => 'warm and friendly', 'professional' => 'clear and professional', 'bold' => 'bold and energetic', 'minimal' => 'short and minimal', 'playful' => 'light and playful'];

    public function __construct(private AiClient $ai, private EmailDesigner $designer) {}

    public function design(Workspace $ws, User $user, string $brief, string $link, string $tone): EmailTemplate
    {
        $key = 'ai-design:'.$ws->id;
        $limit = max(1, (int) Settings::get('ai.designs_per_hour'));
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new ApiException('rate_limited', 'You’ve designed a lot with AI this hour. Try again in a few minutes.', 429, retryAfter: RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 3600);

        $messages = [['role' => 'system', 'content' => $this->prompt($ws, $link, $tone)], ['role' => 'user', 'content' => mb_substr(trim($brief), 0, 1500)]];
        $problem = 'The reply wasn’t valid JSON.';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $r = $this->ai->chat('template_design', $messages, ['workspace_id' => $ws->id, 'user_id' => $user->id, 'json' => true, 'max_tokens' => 2500, 'temperature' => 0.6]);
            $spec = $this->parse($r['text'], $link);
            if ($spec !== null) {
                try {
                    return $this->designer->create($ws, $user, $spec, 'AI');
                } catch (ApiException $e) {
                    if ($e->errorCode !== 'invalid_design' && $e->errorCode !== 'validation_failed') {
                        throw $e;
                    }
                    $problem = $e->getMessage();
                }
            }
            $messages[] = ['role' => 'assistant', 'content' => mb_substr($r['text'], 0, 6000)];
            $messages[] = ['role' => 'user', 'content' => "That design couldn’t be used: $problem Reply again with the corrected JSON object only."];
        }

        throw new ApiException('ai_design_failed', 'The AI couldn’t produce a usable design this time. Try again, or describe the email a little differently.', 502);
    }

    private function prompt(Workspace $ws, string $link, string $tone): string
    {
        $b = $ws->brand ?? [];
        $company = (string) ($b['company'] ?? $ws->name);
        $photos = implode(', ', array_map(fn ($k, $v) => "$k ($v)", array_keys(EmailDesigner::PHOTOS), EmailDesigner::PHOTOS));

        return "You design marketing emails for \"$company\". Tone: ".(self::TONES[$tone] ?? self::TONES['friendly']).".\n"
            ."Reply with ONE JSON object and nothing else: {\"name\": short template name, \"subject\": subject line under 60 characters, \"preheader\": inbox preview under 110 characters, \"sections\": [...]}.\n"
            ."Sections, in order, 3 to 7 of them (a header with the brand is added automatically, and so is a legal footer with the unsubscribe link — don't add either):\n"
            ."- hero {image, heading, text, button_label, button_url}\n- text {heading, text}\n- button {label, url}\n- image {image, alt}\n"
            ."- columns {items: 2-3 × {image, heading, text, button_label, button_url}}\n- products {items: 2-3 × {name, price, image, url, button_label}}\n"
            ."- coupon {code, note}\n- quote {text, author}\n- divider {}\n"
            ."Rules: text is plain (use **bold** and line breaks, no HTML, no markdown headings). Every button and link goes to $link. "
            .'Only state facts from the brief: when a detail is missing (a price, a date, a discount code, a quote), write a clear placeholder in square brackets, like [price] or [date], for the sender to fill in. '
            ."Personalise with {{ first_name | there }} at most once. image must be one of these photo names: $photos.";
    }

    /**
     * The model's JSON as a designer spec, with every link pointed at the sender's link; null when unusable.
     *
     * @return array{name: string, subject: string, preheader: string|null, sections: list<array<string, mixed>>}|null
     */
    private function parse(string $text, string $link): ?array
    {
        $text = trim($text);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0]; // tolerate ```json fences or a sentence around the object
        }
        $j = json_decode($text, true);
        if (! is_array($j) || ! is_array($j['sections'] ?? null) || ! $j['sections']) {
            return null;
        }
        $sections = [];
        foreach ($j['sections'] as $s) {
            if (! is_array($s) || ($s['type'] ?? '') === 'header') {
                continue;
            }
            // Links are never the model's to choose: every button goes where the sender said.
            $type = (string) ($s['type'] ?? '');
            if ($type === 'hero') {
                $s['button_url'] = $link;
            } elseif ($type === 'button' || ($type === 'image' && isset($s['url']))) {
                $s['url'] = $link;
            } elseif (($type === 'columns' || $type === 'products') && is_array($s['items'] ?? null)) {
                $field = $type === 'products' ? 'url' : 'button_url';
                $s['items'] = array_values(array_map(fn ($it) => is_array($it) ? [$field => $link] + $it : $it, $s['items']));
            }
            $sections[] = $s;
        }

        return ['name' => mb_substr(trim((string) ($j['name'] ?? '')) ?: 'AI design', 0, 120), 'subject' => mb_substr(trim((string) ($j['subject'] ?? '')), 0, 255),
            'preheader' => isset($j['preheader']) ? mb_substr(trim((string) $j['preheader']), 0, 300) : null, 'sections' => $sections];
    }
}
