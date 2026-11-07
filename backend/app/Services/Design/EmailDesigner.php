<?php

namespace App\Services\Design;

use App\Exceptions\ApiException;
use App\Models\EmailTemplate;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Turns a section list (from the agent, or "Design with AI") into a normal builder design (never raw HTML): brand kit applied, a compliant
 * footer always added, every text escaped, images only from MailVesper's hosted photos or the workspace's media.
 * The result is validated and rendered like any design, and saved as an editable template.
 */
class EmailDesigner
{
    /** Hosted photos (<FRONTEND_URL>/app/img/<name>.jpg); "-sq" square versions exist for columns. */
    public const PHOTOS = [
        'alley' => 'narrow old-town street', 'aurora' => 'northern lights', 'beans' => 'coffee beans', 'blossom' => 'cherry blossom', 'book' => 'open book', 'bowl' => 'healthy food bowl',
        'bridge' => 'bridge at dusk', 'camera' => 'vintage camera', 'city' => 'city skyline', 'coast' => 'rocky coast', 'coffee' => 'cup of coffee', 'concert' => 'concert crowd',
        'cookies' => 'cookies on a plate', 'couple' => 'couple outdoors', 'cozy' => 'cozy home interior', 'desk' => 'tidy work desk', 'desk2' => 'desk with laptop', 'fashion' => 'fashion portrait',
        'fjord' => 'fjord landscape', 'flowers' => 'flower bouquet', 'kitchen' => 'restaurant kitchen', 'laptop' => 'laptop on a table', 'lighthouse' => 'lighthouse', 'lights' => 'string lights',
        'mountains' => 'mountains', 'phone' => 'smartphone in hand', 'photographer' => 'photographer', 'pier' => 'wooden pier', 'pug' => 'pug dog', 'raspberries' => 'raspberries',
        'scarf' => 'knitted scarf', 'sea' => 'calm sea', 'shoes' => 'sneakers', 'sparkler' => 'sparkler celebration', 'stars' => 'starry sky', 'store' => 'shop front', 'suit' => 'tailored suit',
        'waterfall' => 'waterfall', 'work' => 'team at work', 'work2' => 'meeting room',
    ];

    public const SQUARE = ['beans', 'book', 'bowl', 'camera', 'coffee', 'cookies', 'cozy', 'fashion', 'flowers', 'photographer', 'pug', 'raspberries', 'scarf', 'shoes', 'suit'];

    public const SECTIONS = ['header', 'hero', 'text', 'button', 'image', 'columns', 'products', 'coupon', 'quote', 'divider'];

    private int $seq = 0;

    public function __construct(private DocValidator $validator, private EmailRenderer $renderer) {}

    /**
     * @param  array{name: string, subject: string, preheader?: string|null, sections: list<array<string, mixed>>}  $spec
     */
    public function create(Workspace $ws, ?User $user, array $spec, string $category = 'Agent'): EmailTemplate
    {
        $doc = $this->validator->validate($this->doc($ws, $spec));
        $r = $this->renderer->render($doc, (string) $spec['subject']);

        return EmailTemplate::create(['workspace_id' => $ws->id, 'name' => mb_substr(trim($spec['name']) ?: $category.' design', 0, 120), 'subject' => mb_substr($spec['subject'], 0, 255), 'category' => $category,
            'doc' => $doc, 'html' => $r['html'], 'size_kb' => $r['kb'], 'checks' => $r['checks'], 'created_by' => $user?->id, 'updated_by' => $user?->id]);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function doc(Workspace $ws, array $spec): array
    {
        $b = $ws->brand ?? [];
        $c = (array) ($b['colors'] ?? []);
        $settings = ['width' => 600, 'bodyBg' => $c['background'] ?? '#F4F4F5', 'contentBg' => $c['content'] ?? '#FFFFFF', 'font' => $b['font'] ?? 'Helvetica, Arial, sans-serif',
            'text' => $c['text'] ?? '#3F3F46', 'heading' => $c['heading'] ?? '#18181B', 'link' => $c['link'] ?? '#5B5BF7', 'btn' => $c['button'] ?? '#5B5BF7', 'radius' => 8,
            'preheader' => mb_substr(trim((string) ($spec['preheader'] ?? '')), 0, 300)];
        $sections = array_slice((array) ($spec['sections'] ?? []), 0, 20);
        if (! $sections) {
            throw new ApiException('invalid_design', 'The design needs at least one section.', 422);
        }
        $rows = [];
        if (($sections[0]['type'] ?? '') !== 'header') {
            array_unshift($sections, ['type' => 'header']);
        }
        foreach ($sections as $s) {
            $type = is_array($s) ? (string) ($s['type'] ?? '') : '';
            if (! in_array($type, self::SECTIONS, true)) {
                throw new ApiException('invalid_design', "Unknown section type “{$type}”. Use: ".implode(', ', self::SECTIONS).'.', 422);
            }
            $row = match ($type) {
                'header' => $this->row([[($b['logo_url'] ?? null) ? $this->block('image', ['src' => $b['logo_url'], 'alt' => (string) ($b['company'] ?? $ws->name), 'href' => '', 'width' => 30, 'align' => 'center', 'radius' => 0])
                    : $this->block('heading', ['html' => $this->text((string) ($b['company'] ?? $ws->name)), 'size' => 22, 'align' => 'center', 'weight' => 700])]], [12, 16]),
                'hero' => $this->row([array_values(array_filter([
                    $this->image($ws, $s['image'] ?? null, (string) ($s['heading'] ?? ''), 100, false),
                    $this->heading($s['heading'] ?? null, 30),
                    $this->para($s['text'] ?? null),
                    $this->button($s['button_label'] ?? null, $s['button_url'] ?? null),
                ]))], [8, 16]),
                'text' => $this->row([array_values(array_filter([$this->heading($s['heading'] ?? null, 22), $this->para($s['text'] ?? null)]))], [8, 16]),
                'button' => $this->row([$this->one($this->button($s['label'] ?? null, $s['url'] ?? null, 'center'))], [8, 16]),
                'image' => $this->row([$this->one($this->image($ws, $s['image'] ?? null, (string) ($s['alt'] ?? ''), 100, false, (string) ($s['url'] ?? '')))], [8, 16]),
                'divider' => $this->row([[$this->block('divider', ['thick' => 1, 'color' => '#E4E4E7', 'width' => 100])]], [8, 16]),
                'coupon' => $this->row([[$this->block('coupon', ['code' => mb_substr(strtoupper(trim((string) ($s['code'] ?? 'SAVE10'))), 0, 30), 'note' => mb_substr(trim((string) ($s['note'] ?? '')), 0, 200)])]], [12, 16]),
                'quote' => $this->row([[$this->block('quote', ['html' => $this->text((string) ($s['text'] ?? '')), 'who' => mb_substr(trim((string) ($s['author'] ?? '')), 0, 80), 'src' => 'img/cozy-sq.jpg'])]], [12, 16]),
                'columns' => $this->columns($ws, (array) ($s['items'] ?? [])),
                'products' => $this->products($ws, (array) ($s['items'] ?? [])),
            };
            if ($row['cells'] && array_filter($row['cells'], fn ($cell) => $cell)) {
                $rows[] = $row;
            }
        }
        $socials = array_keys(array_intersect_key((array) ($b['socials'] ?? []), array_flip(['x', 'instagram', 'linkedin', 'youtube', 'facebook', 'tiktok'])));
        $foot = [$this->block('footer', ['company' => (string) ($b['company'] ?? $ws->name), 'address' => (string) ($b['address'] ?? 'Add your postal address in the Brand kit'), 'note' => 'You are receiving this because you signed up with '.((string) ($b['company'] ?? $ws->name)).'.'])];
        if ($socials) {
            array_unshift($foot, $this->block('social', ['nets' => $socials, 'urls' => array_intersect_key((array) $b['socials'], array_flip($socials)), 'align' => 'center', 'size' => 32]));
        }
        $rows[] = $this->row([$foot], [16, 16], '#FAFAFA');

        return ['settings' => $settings, 'rows' => $rows];
    }

    /**
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    private function columns(Workspace $ws, array $items): array
    {
        $items = array_slice(array_values(array_filter($items, 'is_array')), 0, 3);
        if (count($items) < 2) {
            throw new ApiException('invalid_design', 'A columns section needs 2 or 3 items.', 422);
        }

        return $this->row(array_map(fn ($it) => array_values(array_filter([
            $this->image($ws, $it['image'] ?? null, (string) ($it['heading'] ?? ''), 100, true),
            $this->heading($it['heading'] ?? null, 17),
            $this->para($it['text'] ?? null, 14),
            $this->button($it['button_label'] ?? null, $it['button_url'] ?? null),
        ])), $items), [8, 8]);
    }

    /**
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    private function products(Workspace $ws, array $items): array
    {
        $items = array_slice(array_values(array_filter($items, 'is_array')), 0, 3);
        if (count($items) < 2) {
            throw new ApiException('invalid_design', 'A products section needs 2 or 3 items.', 422);
        }

        return $this->row(array_map(fn ($it) => [$this->block('product', ['src' => $this->src($ws, $it['image'] ?? null, true) ?? 'img/store.jpg', 'name' => mb_substr(trim((string) ($it['name'] ?? '')), 0, 80),
            'price' => mb_substr(trim((string) ($it['price'] ?? '')), 0, 40), 'href' => $this->url($it['url'] ?? null) ?? '#', 'btn' => mb_substr(trim((string) ($it['button_label'] ?? 'Shop now')), 0, 30)])], $items), [8, 8]);
    }

    /**
     * @param  list<list<array<string, mixed>>>  $cells
     * @param  array{0: int, 1: int}  $pad
     * @return array<string, mixed>
     */
    private function row(array $cells, array $pad, string $bg = ''): array
    {
        return ['id' => 'r'.$this->id(), 'layout' => array_fill(0, max(1, count($cells)), 1), 'cells' => $cells ?: [[]], 'bg' => $bg, 'pad' => $pad, 'stack' => true, 'hide' => ''];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function block(string $type, array $props): array
    {
        return ['id' => 'b'.$this->id(), 'type' => $type, 'props' => $props + ['pad' => [8, 28, 8, 28]]];
    }

    /** @return array<string, mixed>|null */
    private function heading(mixed $t, int $size): ?array
    {
        return is_string($t) && trim($t) !== '' ? $this->block('heading', ['html' => $this->text($t), 'size' => $size, 'align' => 'left', 'weight' => 700]) : null;
    }

    /** @return array<string, mixed>|null */
    private function para(mixed $t, int $size = 16): ?array
    {
        return is_string($t) && trim($t) !== '' ? $this->block('text', ['html' => $this->text($t), 'size' => $size, 'lh' => 1.6, 'align' => 'left']) : null;
    }

    /** @return array<string, mixed>|null */
    private function button(mixed $label, mixed $url, string $align = 'left'): ?array
    {
        $u = $this->url($url);

        return is_string($label) && trim($label) !== '' && $u ? $this->block('button', ['label' => mb_substr(trim($label), 0, 40), 'href' => $u, 'bg' => '', 'color' => '#FFFFFF', 'radius' => null, 'full' => false, 'align' => $align, 'size' => 16]) : null;
    }

    /** @return array<string, mixed>|null */
    private function image(Workspace $ws, mixed $img, string $alt, int $width, bool $square, string $href = ''): ?array
    {
        $src = $this->src($ws, $img, $square);

        return $src ? $this->block('image', ['src' => $src, 'alt' => mb_substr($alt, 0, 120), 'href' => $this->url($href) ?? '', 'width' => $width, 'align' => 'center', 'radius' => 12]) : null;
    }

    /** A hosted photo by name, or an image from this workspace's media library; anything else is dropped. */
    private function src(Workspace $ws, mixed $img, bool $square): ?string
    {
        if (! is_string($img) || $img === '') {
            return null;
        }
        $name = strtolower(trim($img));
        if (isset(self::PHOTOS[$name])) {
            return 'img/'.$name.($square && in_array($name, self::SQUARE, true) ? '-sq' : '').'.jpg';
        }
        $media = rtrim((string) config('app.frontend_url'), '/').'/media/';
        if (str_starts_with($img, $media) && Media::where('workspace_id', $ws->id)->where('url', $img)->exists()) {
            return $img;
        }

        return null;
    }

    private function url(mixed $u): ?string
    {
        if (! is_string($u) || trim($u) === '') {
            return null;
        }
        $u = trim($u);
        if (str_starts_with($u, '{{') && str_ends_with($u, '}}')) {
            return $u; // merge tag like {{ unsubscribe_url }}
        }

        return preg_match('#^https?://[^\s<>"]+$#i', $u) ? $u : null;
    }

    /** Plain text → safe inline HTML: escaped, **bold** kept, line breaks kept, merge tags untouched. */
    private function text(string $t): string
    {
        $h = htmlspecialchars(mb_substr(trim($t), 0, 2000), ENT_NOQUOTES, 'UTF-8');
        $h = (string) preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $h);

        return nl2br($h, false);
    }

    /**
     * @param  array<string, mixed>|null  $b
     * @return list<array<string, mixed>>
     */
    private function one(?array $b): array
    {
        return $b ? [$b] : [];
    }

    private function id(): string
    {
        return strtolower(Str::random(5)).(++$this->seq);
    }
}
