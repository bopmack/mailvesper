<?php

namespace App\Services\Design;

/**
 * The email renderer in PHP: a builder document → the table-based email HTML, plus the pre-send checks.
 * A line-for-line port of app/email-render.js (export mode), so the server needs nothing but PHP. The browser
 * still uses the JS file for previews; tests/Feature/Design/RendererParityTest.php proves the two give
 * byte-identical HTML for every gallery design (when Node is on the developer's machine). Change both together.
 *
 * JS details matter here: `${x}` prints undefined/null/true, numbers print like JS, `a || b` is JS truthiness,
 * Math.round is floor(x + .5), and toFixed rounds the exact binary value.
 */
final class EmailHtml
{
    private const ASSETS = ['logo', 'hero', 'f1', 'f2', 'f3', 'p1', 'p2', 'avatar'];

    private const SOCIAL = ['x' => ['X', '#18181B'], 'instagram' => ['Instagram', '#E1306C'], 'linkedin' => ['LinkedIn', '#0A66C2'],
        'youtube' => ['YouTube', '#FF0000'], 'facebook' => ['Facebook', '#1877F2'], 'tiktok' => ['TikTok', '#000000']];

    /** JS whitespace (\s), for trim and word counts. */
    private const WS = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private static ?object $undefined = null;

    public function __construct(private string $assetBase = '') {}

    /** @param array<string, mixed> $doc */
    public function html(array $doc, string $subject = ''): string
    {
        $raw = self::fullDocument($doc);
        if ($raw !== null) {
            return $raw;
        }
        $s = $doc['settings'];
        $W = self::get($s, 'width');
        $rows = [];
        foreach ($doc['rows'] as $r) {
            $tot = array_sum($r['layout']);
            $cols = '';
            foreach ($r['layout'] as $i => $w) {
                $blocks = '';
                foreach ($r['cells'][$i] as $b) {
                    $blocks .= $this->block($b, $s);
                }
                $cols .= '<td class="'.(self::truthy(self::get($r, 'stack')) ? 'col' : '').'" width="'.self::round($W * $w / $tot).'" valign="top" style="width:'
                    .self::toFixed($w / $tot * 100, 2).'%;vertical-align:top">'.$blocks.'</td>';
            }
            $hide = self::get($r, 'hide');
            $pad = is_array($p = self::get($r, 'pad')) ? $p : [];
            $rows[] = '<tr class="'.(self::truthy($hide) ? 'hide-'.self::str($hide) : '').'"><td style="padding:'.self::str($pad[0] ?? self::undefined()).'px 0 '.self::str($pad[1] ?? self::undefined()).'px;background:'
                .self::str(self::or(self::get($r, 'bg'), self::get($s, 'contentBg'))).'"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'.$cols.'</tr></table></td></tr>';
        }
        $w = self::str($W);

        return '<!doctype html><html lang="en" xmlns:v="urn:schemas-microsoft-com:vml"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>'.self::esc($subject).'</title>'."\n"
            .'<style>body{margin:0;padding:0}img{-ms-interpolation-mode:bicubic}a{color:'.self::str(self::get($s, 'link')).'}@media (max-width:'.self::str($W + 20).'px){.wrap{width:100%!important}.col{display:block!important;width:100%!important}.hide-mobile{display:none!important}}@media (min-width:'.self::str($W + 21).'px){.hide-desktop{display:none!important}}</style></head>'."\n"
            .'<body style="margin:0;background:'.self::str(self::get($s, 'bodyBg')).'"><div style="display:none;max-height:0;overflow:hidden;opacity:0">'.self::esc(self::or(self::get($s, 'preheader'), '')).'&#847;&zwnj;&nbsp;</div>'."\n"
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:'.self::str(self::get($s, 'bodyBg')).'"><tr><td align="center" style="padding:24px 8px">'."\n"
            .'<!--[if mso]><table role="presentation" width="'.$w.'"><tr><td><![endif]-->'."\n"
            .'<table role="presentation" class="wrap" width="'.$w.'" cellpadding="0" cellspacing="0" border="0" style="width:'.$w.'px;max-width:100%;background:'.self::str(self::get($s, 'contentBg')).';border-radius:12px;overflow:hidden">'."\n"
            .implode("\n", $rows)."\n"
            .'</table>'."\n"
            .'<!--[if mso]></td></tr></table><![endif]-->'."\n"
            .'</td></tr></table></body></html>';
    }

    /**
     * The pre-send checks, as the builder shows them.
     *
     * @param  array<string, mixed>  $doc
     * @return array{kb: float, html: string, list: list<array{name: string, status: string, detail: string}>}
     */
    public function checks(array $doc, string $subject = ''): array
    {
        $html = $this->html($doc);
        $kb = strlen($html) / 1024;
        $blocks = [];
        $imgs = 0;
        foreach ($doc['rows'] as $r) {
            $tot = array_sum($r['layout']);
            foreach ($r['cells'] as $i => $cell) {
                $frac = $r['layout'][$i] / $tot;
                foreach ($cell as $b) {
                    $blocks[] = $b;
                    $t = $b['type'] ?? null;
                    if (($t === 'image' && $frac * self::num(self::get($b['props'], 'width')) / 100 > .5) || (($t === 'video' || $t === 'product') && $frac > .5)) {
                        $imgs++;
                    }
                }
            }
        }
        $noAlt = count(array_filter($blocks, fn ($b) => in_array($b['type'] ?? null, ['image', 'video'], true) && ! self::truthy(self::get($b['props'], 'alt'))
            && ! preg_match('/^f\d$/', self::str(self::get($b['props'], 'src')))));
        $text = implode(' ', array_map(fn ($b) => preg_replace('/<[^>]+>/', ' ', self::str(self::get($b['props'], 'html'))),
            array_filter($blocks, fn ($b) => in_array($b['type'] ?? null, ['text', 'heading', 'quote'], true))));
        $words = count(array_filter(preg_split('/['.self::WS.']+/u', $text) ?: [], fn ($w) => $w !== ''));
        $raw = self::fullDocument($doc);
        $footer = array_filter($blocks, fn ($b) => ($b['type'] ?? null) === 'footer') !== [] || ($raw !== null && preg_match('/\{\{\s*unsubscribe_url\s*\}\}/', $raw));
        $dead = 0;
        foreach ($blocks as $b) {
            $t = $b['type'] ?? null;
            $href = self::get($b['props'], 'href');
            if (in_array($t, ['button', 'image'], true) && $href !== self::undefined() && $href !== '' && ! preg_match('/^https?:\/\/[^\/]+\.[a-z]{2,}/i', self::str($href))) {
                $dead++;
            }
            if ($t === 'button' && ! self::truthy($href)) {
                $dead++;
            }
        }
        $noFallback = preg_match_all('/\{\{\s*first_name\s*\}\}/', (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $ratio = $imgs ? $words / $imgs : $words;
        $pre = self::truthy(self::get($doc['settings'], 'preheader'));
        $plural = fn (int $n, string $one) => $n.' '.$one.($n > 1 ? 's' : '');

        return ['kb' => $kb, 'html' => $html, 'list' => array_map(fn ($c) => ['name' => $c[0], 'status' => $c[1], 'detail' => $c[2]], [
            ['Gmail clipping', $kb < 90 ? 'ok' : ($kb < 102 ? 'warn' : 'bad'), self::toFixed($kb, 1).' KB of HTML · Gmail clips at 102 KB'],
            ['Alt text on images', $noAlt ? 'warn' : 'ok', $noAlt ? $plural($noAlt, 'image').' without alt text' : 'Every image has alt text'],
            ['Text vs images', $ratio < 25 && $imgs > 1 ? 'warn' : 'ok', $words.' words · '.$imgs.' large image'.($imgs === 1 ? '' : 's').($ratio < 25 && $imgs > 1 ? ' · image-heavy, add more text' : '')],
            ['Unsubscribe link & address', $footer ? 'ok' : 'bad', $footer ? ($raw !== null ? 'Has an unsubscribe link' : 'Footer has both') : ($raw !== null ? 'Add an unsubscribe link ({{unsubscribe_url}}) to the HTML' : 'Add a Footer block: required by law and by Gmail/Yahoo')],
            ['Links', $dead ? 'warn' : 'ok', $dead ? $plural($dead, 'link').' not set to a real URL' : 'All links point to real URLs'],
            ['Personalization', $noFallback ? 'warn' : 'ok', $noFallback ? $noFallback.' {{first_name}} without a fallback' : 'Merge tags have fallbacks'],
            ['Preview text', $pre ? 'ok' : 'warn', $pre ? 'Set' : 'Empty: inboxes will show the first line of the email'],
        ])];
    }

    /**
     * A design that is one complete HTML document (an email imported from another provider) is sent exactly as written.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function fullDocument(array $doc): ?string
    {
        $r = $doc['rows'];
        $b = count($r) === 1 && count($r[0]['cells']) === 1 && count($r[0]['cells'][0]) === 1 ? $r[0]['cells'][0][0] : null;
        $code = $b ? self::get($b['props'], 'code') : null;

        return $b && ($b['type'] ?? null) === 'html' && preg_match('/^['.self::WS.']*(<!doctype|<html)/iu', self::or($code, '') === '' ? '' : self::str($code)) ? self::str($code) : null;
    }

    /**
     * @param  array<string, mixed>  $b
     * @param  array<string, mixed>  $s
     */
    private function block(array $b, array $s): string
    {
        $p = $b['props'] ?? [];
        $g = fn (string $k) => self::get($p, $k);
        $v = fn (string $k) => self::str(self::get($p, $k));
        $pad = self::or($g('pad'), [0, 0, 0, 0]);
        $pad = is_array($pad) ? array_map(fn ($i) => $pad[$i] ?? self::undefined(), [0, 1, 2, 3]) : array_fill(0, 4, self::undefined());
        $wrap = fn (string $inner, string $extra = '') => '<div style="padding:'.self::str($pad[0]).'px '.self::str($pad[1]).'px '.self::str($pad[2]).'px '.self::str($pad[3]).'px;'.$extra.'">'.$inner.'</div>';
        $ff = 'font-family:'.self::str(self::get($s, 'font'));
        $sv = fn (string $k) => self::str(self::get($s, $k));

        switch ($b['type'] ?? null) {
            case 'heading':
                return $wrap('<h2 class="eb-edit" style="margin:0;'.$ff.';font-size:'.$v('size').'px;line-height:1.25;font-weight:'.$v('weight').';color:'.self::str(self::or($g('color'), self::get($s, 'heading'))).';text-align:'.$v('align').';letter-spacing:-.01em">'.$v('html').'</h2>');
            case 'text':
                return $wrap('<div class="eb-edit" style="margin:0;'.$ff.';font-size:'.$v('size').'px;line-height:'.$v('lh').';color:'.self::str(self::or($g('color'), self::get($s, 'text'))).';text-align:'.$v('align').'">'.$v('html').'</div>');
            case 'image':
                $ppad = $g('pad');
                $img = '<img src="'.$this->imgSrc($g('src')).'" alt="'.self::esc($g('alt')).'" width="'.self::str(self::round((self::num(self::get($s, 'width')) - self::num($ppad[1] ?? null) - self::num($ppad[3] ?? null)) * self::num($g('width')) / 100)).'" style="display:inline-block;width:'.$v('width').'%;max-width:100%;height:auto;border:0;border-radius:'.$v('radius').'px">';

                return $wrap(self::truthy($g('href')) ? '<a href="'.self::esc($g('href')).'">'.$img.'</a>' : $img, 'text-align:'.$v('align').';line-height:0');
            case 'button':
                $bg = self::str(self::or($g('bg'), self::get($s, 'btn')));
                $r = $g('radius') === null || $g('radius') === self::undefined() ? self::get($s, 'radius') : $g('radius');
                $a = '<a href="'.self::esc($g('href')).'" class="" style="display:'.(self::truthy($g('full')) ? 'block' : 'inline-block').';background:'.$bg.';color:'.$v('color').';'.$ff.';font-size:'.$v('size').'px;font-weight:700;text-decoration:none;padding:13px 26px;border-radius:'.self::str($r).'px;text-align:center">'.self::esc($g('label')).'</a>';

                return $wrap('<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="'.self::esc($g('href')).'" style="height:46px;v-text-anchor:middle;width:220px" arcsize="'.self::str(self::round(self::num($r) / 46 * 100)).'%" fillcolor="'.$bg.'" stroke="f"><center style="color:'.$v('color').';font-family:Arial;font-size:'.$v('size').'px;font-weight:bold">'.self::esc($g('label')).'</center></v:roundrect><![endif]--><!--[if !mso]><!-->'.$a.'<!--<![endif]-->', 'text-align:'.$v('align'));
            case 'divider':
                return $wrap('<div style="border-top:'.$v('thick').'px solid '.$v('color').';width:'.$v('width').'%;margin:0 auto;font-size:0;line-height:0">&nbsp;</div>');
            case 'spacer':
                return '<div style="height:'.$v('h').'px;line-height:'.$v('h').'px;font-size:0">&nbsp;</div>';
            case 'social':
                $urls = $g('urls');
                $out = '';
                foreach ((array) $g('nets') as $k) {
                    $url = is_array($urls) ? self::get($urls, (string) $k) : self::undefined();
                    $out .= '<a href="'.self::esc(self::or($url, 'https://'.self::str($k).'.com/acme')).'" style="text-decoration:none">'.self::socialIcon((string) $k, $g('size')).'</a>';
                }

                return $wrap($out, 'text-align:'.$v('align'));
            case 'video':
                $ppad = $g('pad');

                return $wrap('<a href="'.self::esc($g('href')).'" style="display:block;position:relative;text-decoration:none"><img src="'.$this->imgSrc($g('src')).'" alt="'.self::esc($g('alt')).'" width="'.self::str(self::num(self::get($s, 'width')) - self::num($ppad[1] ?? null) - self::num($ppad[3] ?? null)).'" style="display:block;width:100%;height:auto;border-radius:12px;border:0"><span style="position:absolute;left:50%;top:50%;width:68px;height:68px;margin:-34px 0 0 -34px;border-radius:50%;background:rgba(24,24,27,.85);color:#fff;font:28px/68px Arial;text-align:center">▶</span></a>');
            case 'product':
                return $wrap('<div style="border:1px solid #E4E4E7;border-radius:14px;overflow:hidden;background:#fff"><img src="'.$this->imgSrc($g('src')).'" alt="'.self::esc($g('name')).'" width="260" style="display:block;width:100%;height:auto;border:0"><div style="padding:14px 16px 16px;'.$ff.'"><div style="font-size:15px;font-weight:700;color:'.$sv('heading').'">'.self::esc($g('name')).'</div><div style="font-size:14px;color:'.$sv('text').';margin:2px 0 12px">'.self::esc($g('price')).'</div><a href="'.self::esc($g('href')).'" style="display:block;text-align:center;background:'.$sv('btn').';color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:10px;border-radius:'.$sv('radius').'px">'.self::esc($g('btn')).'</a></div></div>');
            case 'coupon':
                return $wrap('<div style="border:2px dashed '.$sv('btn').';border-radius:14px;padding:18px;text-align:center;'.$ff.'"><div style="font:700 26px/1.2 \'Courier New\',monospace;letter-spacing:.12em;color:'.$sv('heading').'">'.self::esc($g('code')).'</div><div style="font-size:13px;color:'.$sv('text').';margin-top:6px">'.self::esc($g('note')).'</div></div>');
            case 'quote':
                return $wrap('<div style="'.$ff.';border-left:3px solid '.$sv('btn').';padding-left:18px"><div class="eb-edit" style="font-size:18px;line-height:1.5;color:'.$sv('heading').';font-style:italic">'.$v('html').'</div><div style="display:flex;align-items:center;gap:10px;margin-top:12px"><img src="'.$this->imgSrc($g('src')).'" alt="" width="36" height="36" style="border-radius:50%;width:36px;height:36px"><span style="font-size:13px;color:'.$sv('text').'">'.self::esc($g('who')).'</span></div></div>');
            case 'order':
                $rows = '';
                foreach ((array) $g('items') as $it) {
                    [$n, $vr, $q, $pr, $im] = array_map(fn ($i) => array_key_exists($i, (array) $it) ? $it[$i] : self::undefined(), [0, 1, 2, 3, 4]);
                    $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #E4E4E7;width:64px">'.(self::truthy($im) ? '<img src="'.$this->imgSrc($im).'" width="56" height="56" alt="" style="display:block;width:56px;height:56px;border-radius:10px;object-fit:cover">' : '').'</td><td style="padding:10px 12px;border-bottom:1px solid #E4E4E7;font-size:14px;color:'.$sv('heading').'"><b>'.self::esc($n).'</b><div style="font-size:12.5px;color:#71717A">'.self::esc($vr).' · Qty '.self::str($q).'</div></td><td align="right" style="padding:10px 0;border-bottom:1px solid #E4E4E7;font-size:14px;color:'.$sv('heading').';white-space:nowrap">'.self::esc($pr).'</td></tr>';
                }

                return $wrap('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="'.$ff.';border-collapse:collapse">'.$rows.'<tr><td></td><td style="padding:12px 12px 2px;font-size:13px;color:#71717A">Shipping</td><td align="right" style="padding:12px 0 2px;font-size:13px;color:#71717A">'.self::esc($g('shipping')).'</td></tr><tr><td></td><td style="padding:4px 12px;font-size:16px;font-weight:700;color:'.$sv('heading').'">Total</td><td align="right" style="padding:4px 0;font-size:16px;font-weight:700;color:'.$sv('heading').'">'.self::esc($g('total')).'</td></tr></table>');
            case 'menu':
                $out = '';
                foreach (explode(',', $v('items')) as $x) {
                    $x = self::trim($x);
                    $out .= '<a href="https://acme-corp.io/'.self::esc(mb_strtolower($x)).'" style="'.$ff.';font-size:14px;font-weight:600;color:'.$sv('heading').';text-decoration:none;margin-left:18px">'.self::esc($x).'</a>';
                }

                return $wrap($out, 'text-align:'.$v('align'));
            case 'html':
                return $wrap($v('code'));
            case 'footer':
                return $wrap('<div style="'.$ff.';font-size:12px;line-height:1.6;color:#71717A;text-align:center"><div>'.self::esc($g('note')).'</div><div style="margin-top:6px"><b style="color:#52525B">'.self::esc($g('company')).'</b> · '.self::esc($g('address')).'</div><div style="margin-top:8px"><a href="{{unsubscribe_url}}" style="color:#71717A">Unsubscribe</a> · <a href="{{preferences_url}}" style="color:#71717A">Email preferences</a> · <a href="{{web_version_url}}" style="color:#71717A">View in browser</a></div></div>');
        }

        return '';
    }

    private function imgSrc(mixed $k): string
    {
        $k = self::str($k);
        if (in_array($k, self::ASSETS, true)) {
            return $this->assetBase.'/img/eb/'.$k.'.png';
        }

        return str_starts_with($k, 'img/') ? $this->assetBase.'/'.$k : $k;
    }

    private static function socialIcon(string $k, mixed $size): string
    {
        if (! isset(self::SOCIAL[$k])) {
            return '';
        }
        [$n, $c] = self::SOCIAL[$k];
        $size = $size === self::undefined() ? 32 : $size;
        $z = self::str($size);
        $glyph = match ($n) {
            'LinkedIn' => 'in', 'Instagram' => 'IG', 'YouTube' => '▶', 'Facebook' => 'f', 'TikTok' => '♪', default => 'X'
        };

        return '<span title="'.$n.'" style="display:inline-block;width:'.$z.'px;height:'.$z.'px;border-radius:50%;background:'.$c.';color:#fff;font:700 '.self::str(self::round(self::num($size) * .38)).'px/'.$z.'px Helvetica,Arial,sans-serif;text-align:center;margin:0 4px">'.$glyph.'</span>';
    }

    // ─── JS semantics ───

    private static function undefined(): object
    {
        return self::$undefined ??= new \stdClass;
    }

    /** $a[$k], or JS undefined when the key is missing. */
    private static function get(mixed $a, string $k): mixed
    {
        return is_array($a) && array_key_exists($k, $a) ? $a[$k] : self::undefined();
    }

    private static function truthy(mixed $v): bool
    {
        return ! ($v === self::undefined() || $v === null || $v === false || $v === 0 || $v === '' || (is_float($v) && ($v == 0 || is_nan($v))));
    }

    private static function or(mixed $a, mixed $b): mixed
    {
        return self::truthy($a) ? $a : $b;
    }

    /** String(v) as JS prints it inside a template literal. */
    private static function str(mixed $v): string
    {
        return match (true) {
            $v === self::undefined() => 'undefined',
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v) => (string) $v,
            is_float($v) => self::number($v),
            is_array($v) => array_is_list($v) ? implode(',', array_map(fn ($x) => $x === null || $x === self::undefined() ? '' : self::str($x), $v)) : '[object Object]',
            default => (string) $v,
        };
    }

    private static function number(float $v): string
    {
        if (is_nan($v)) {
            return 'NaN';
        }
        if (is_infinite($v)) {
            return $v > 0 ? 'Infinity' : '-Infinity';
        }
        if ($v == floor($v) && abs($v) < 1e21) {
            return sprintf('%.0f', $v === -0.0 ? 0.0 : $v);
        }
        $s = (string) $v; // shortest round-trip, like JS; only the exponent style differs

        return str_contains($s, 'E') ? strtolower(str_replace(['E+', 'E'], ['e+', 'e'], $s)) : $s;
    }

    /** Number(v) for arithmetic. */
    private static function num(mixed $v): float
    {
        return match (true) {
            is_int($v), is_float($v) => (float) $v,
            is_bool($v) => $v ? 1.0 : 0.0,
            $v === null => 0.0,
            is_string($v) && is_numeric(trim($v)) => (float) trim($v),
            is_string($v) && trim($v) === '' => 0.0,
            default => NAN,
        };
    }

    /** Math.round: floor(x + .5). */
    private static function round(float $x): float
    {
        return floor($x + .5);
    }

    /** Number.prototype.toFixed: the exact binary value, ties to the larger digit. */
    private static function toFixed(float $x, int $digits): string
    {
        $exact = rtrim(rtrim(sprintf('%.40F', $x), '0'), '.'); // the double's exact decimal value (enough digits for our sizes)
        $neg = str_starts_with($exact, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($exact, '-')), 2, '');
        $frac = str_pad($frac, $digits + 1, '0');
        $keep = $int.substr($frac, 0, $digits);
        $rest = substr($frac, $digits);
        $up = $rest[0] >= '5'; // at or past the half: the larger n, as the spec says
        if ($up) {
            $i = strlen($keep) - 1;
            while ($i >= 0 && $keep[$i] === '9') {
                $keep[$i] = '0';
                $i--;
            }
            $keep = $i < 0 ? '1'.$keep : substr_replace($keep, (string) ((int) $keep[$i] + 1), $i, 1);
        }
        $out = $digits ? substr($keep, 0, -$digits).'.'.substr($keep, -$digits) : $keep;

        return ($neg && trim($keep, '0') !== '' ? '-' : '').$out;
    }

    private static function trim(string $s): string
    {
        return (string) preg_replace('/^['.self::WS.']+|['.self::WS.']+$/u', '', $s);
    }

    private static function esc(mixed $s): string
    {
        $s = $s === null || $s === self::undefined() ? '' : self::str($s);

        return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }
}
