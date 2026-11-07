<?php

namespace App\Services\Design;

use App\Exceptions\ApiException;

/**
 * Checks a builder document's shape before it is rendered or stored. Values that end up inside CSS
 * (colours, fonts, sizes) are validated strictly so a design can't break out of its style attributes.
 */
class DocValidator
{
    public const BLOCK_TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'social', 'video', 'product', 'coupon', 'quote', 'order', 'menu', 'html', 'footer'];

    public const FONTS = ['Helvetica, Arial, sans-serif', 'Georgia, Times, serif', 'Verdana, Geneva, sans-serif', 'Trebuchet MS, sans-serif', '"Courier New", monospace'];

    public const MAX_BYTES = 1_500_000;

    /**
     * @param  mixed  $doc
     * @return array<string, mixed>
     */
    public function validate($doc): array
    {
        if (! is_array($doc) || ! is_array($doc['settings'] ?? null) || ! is_array($doc['rows'] ?? null) || ! array_is_list($doc['rows'])) {
            $this->fail('The design is missing its settings or rows.');
        }
        if (strlen((string) json_encode($doc)) > self::MAX_BYTES) {
            $this->fail('This design is too large. Remove some blocks or custom HTML.');
        }
        $s = $doc['settings'];
        $width = $s['width'] ?? 600;
        if (! is_int($width) || $width < 320 || $width > 900) {
            $this->fail('Email width must be between 320 and 900 pixels.');
        }
        foreach (['bodyBg', 'contentBg', 'text', 'heading', 'link', 'btn'] as $k) {
            if (isset($s[$k]) && ! self::color($s[$k])) {
                $this->fail("The $k colour isn’t a valid colour.");
            }
        }
        if (isset($s['font']) && ! in_array($s['font'], self::FONTS, true)) {
            $this->fail('Pick one of the email-safe fonts.');
        }
        if (isset($s['radius']) && ! self::num($s['radius'], 0, 60)) {
            $this->fail('Corner radius must be between 0 and 60.');
        }
        if (isset($s['preheader']) && (! is_string($s['preheader']) || mb_strlen($s['preheader']) > 300)) {
            $this->fail('Preview text can be up to 300 characters.');
        }
        if (count($doc['rows']) > 80) {
            $this->fail('A design can have up to 80 rows.');
        }
        foreach ($doc['rows'] as $i => $r) {
            $n = $i + 1;
            if (! is_array($r) || ! is_array($r['layout'] ?? null) || ! is_array($r['cells'] ?? null) || count($r['layout']) < 1 || count($r['layout']) > 4 || count($r['layout']) !== count($r['cells'])) {
                $this->fail("Row $n has a broken column layout.");
            }
            foreach ($r['layout'] as $w) {
                if (! self::num($w, 1, 4)) {
                    $this->fail("Row $n has a broken column layout.");
                }
            }
            if (! empty($r['bg']) && ! self::color($r['bg'])) {
                $this->fail("Row $n has an invalid background colour.");
            }
            if (! is_array($r['pad'] ?? null) || count($r['pad']) !== 2 || ! self::num($r['pad'][0], 0, 200) || ! self::num($r['pad'][1], 0, 200)) {
                $this->fail("Row $n has invalid spacing.");
            }
            if (! in_array($r['hide'] ?? '', ['', 'mobile', 'desktop'], true)) {
                $this->fail("Row $n has an invalid visibility setting.");
            }
            foreach ($r['cells'] as $cell) {
                if (! is_array($cell) || count($cell) > 40) {
                    $this->fail("Row $n has too many blocks in a column.");
                }
                foreach ($cell as $b) {
                    $this->block($b, $n);
                }
            }
        }

        return $doc;
    }

    private function block(mixed $b, int $row): void
    {
        if (! is_array($b) || ! in_array($b['type'] ?? null, self::BLOCK_TYPES, true) || ! is_array($b['props'] ?? null)) {
            $this->fail("Row $row has a block of an unknown type.");
        }
        $p = $b['props'];
        if (isset($p['pad']) && (! is_array($p['pad']) || count($p['pad']) !== 4 || array_filter($p['pad'], fn ($x) => ! self::num($x, 0, 200)))) {
            $this->fail("A block in row $row has invalid spacing.");
        }
        foreach (['size' => [8, 96], 'width' => [1, 100], 'radius' => [0, 100], 'h' => [0, 400], 'thick' => [0, 20], 'weight' => [100, 900], 'lh' => [0.8, 3]] as $k => [$min, $max]) {
            if (isset($p[$k]) && ! self::num($p[$k], $min, $max)) {
                $this->fail("A block in row $row has an invalid $k.");
            }
        }
        foreach (['color', 'bg'] as $k) {
            if (! empty($p[$k]) && ! self::color($p[$k])) {
                $this->fail("A block in row $row has an invalid colour.");
            }
        }
        if (isset($p['align']) && ! in_array($p['align'], ['left', 'center', 'right'], true)) {
            $this->fail("A block in row $row has an invalid alignment.");
        }
        foreach (['href', 'src'] as $k) {
            if (isset($p[$k]) && (! is_string($p[$k]) || preg_match('/^\s*(javascript|data:text|vbscript):/i', $p[$k]))) {
                $this->fail("A link or image in row $row isn’t allowed.");
            }
        }
        $needs = ['menu' => 'items', 'order' => 'items', 'social' => 'nets'];
        if (isset($needs[$b['type']])) {
            $k = $needs[$b['type']];
            $ok = $b['type'] === 'menu' ? is_string($p[$k] ?? null) : is_array($p[$k] ?? null);
            if (! $ok) {
                $this->fail("A {$b['type']} block in row $row is incomplete.");
            }
        }
    }

    public static function color(mixed $v): bool
    {
        return is_string($v) && (bool) preg_match('/^(#[0-9a-f]{3,8}|transparent|rgba?\(\s*[\d.\s,%]+\))$/i', trim($v));
    }

    private static function num(mixed $v, float $min, float $max): bool
    {
        return (is_int($v) || is_float($v)) && $v >= $min && $v <= $max;
    }

    private function fail(string $message): never
    {
        throw new ApiException('invalid_design', $message, 422);
    }
}
