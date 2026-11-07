<?php

namespace App\Services\Contacts;

use App\Exceptions\ApiException;

/**
 * The visual form builder's rules: field types, the look (colours, font, image, layout, steps) and how a form shows
 * on a site (inline, popup, slide-in or bar, with display rules). One place checks what the editor saves and what a
 * visitor submits, so the hosted page, the embed and plain HTML forms all follow the same rules.
 */
class FormBuilder
{
    public const TYPES = ['text', 'textarea', 'number', 'phone', 'date', 'dropdown', 'radio', 'checkboxes', 'checkbox', 'hidden', 'consent'];

    /** Types with a list of choices. */
    public const CHOICE_TYPES = ['dropdown', 'radio', 'checkboxes'];

    public const MAX_STEPS = 3;

    /** Fonts the renderer can load (Google Fonts, or the visitor's system font). */
    public const FONTS = ['Inter', 'System', 'Poppins', 'DM Sans', 'Montserrat', 'Lora', 'Playfair Display', 'Georgia'];

    public const LAYOUTS = ['card', 'image_left', 'image_top', 'background'];

    public const MODES = ['inline', 'popup', 'slidein', 'bar'];

    public const DESIGN = ['accent' => '#5B5BF7', 'background' => '#F4F4F5', 'card' => '#FFFFFF', 'text' => '#18181B', 'font' => 'Inter', 'radius' => 14, 'layout' => 'card',
        'image' => null, 'button_full' => true, 'steps' => [], 'show_mark' => true];

    public const DISPLAY = ['mode' => 'inline', 'delay' => 5, 'scroll' => 0, 'exit_intent' => false, 'frequency' => 'days', 'days' => 7, 'position' => 'right'];

    /**
     * @param  mixed  $in  the editor's field list
     * @return list<array<string, mixed>>
     */
    public static function fields(mixed $in, int $max): array
    {
        $bad = fn (string $m) => new ApiException('validation_failed', $m, 422, ['fields' => $m]);
        $fields = [];
        foreach (array_slice(array_values(array_filter((array) $in, 'is_array')), 0, $max + 1) as $i => $f) {
            $n = $i + 1;
            $key = (string) ($f['key'] ?? '');
            $type = in_array($f['type'] ?? 'text', self::TYPES, true) ? (string) ($f['type'] ?? 'text') : throw $bad("Field $n has an unknown type.");
            $named = in_array($key, ['first_name', 'last_name'], true);
            if (! $named && ! ContactService::validKey($key)) {
                throw $bad('Field keys use lowercase letters, digits and _ (like company), and can’t be a built-in name like email.');
            }
            if ($named && $type !== 'text') {
                throw $bad('First and last name are text fields.');
            }
            if (isset($fields[$key])) {
                throw $bad("Two fields save to “{$key}”. Each needs its own.");
            }
            $label = trim((string) ($f['label'] ?? ''));
            $o = ['key' => $key, 'type' => $type, 'label' => mb_substr($label, 0, $type === 'consent' ? 500 : 80) ?: ($type === 'consent' ? 'I agree to receive emails.' : ucfirst(str_replace('_', ' ', $key))),
                'required' => $type === 'hidden' ? false : (bool) ($f['required'] ?? ($type === 'consent')), 'placeholder' => mb_substr(trim((string) ($f['placeholder'] ?? '')), 0, 80) ?: null,
                'width' => ($f['width'] ?? 'full') === 'half' ? 'half' : 'full', 'step' => max(1, min(self::MAX_STEPS, (int) ($f['step'] ?? 1)))];
            if (in_array($type, self::CHOICE_TYPES, true)) {
                $opts = array_values(array_unique(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 80), array_filter((array) ($f['options'] ?? []), 'is_scalar')), fn ($x) => $x !== '')));
                if (! $opts || count($opts) > 20) {
                    throw $bad("“{$o['label']}” needs 1 to 20 choices.");
                }
                $o['options'] = $opts;
            }
            if ($type === 'hidden') {
                $param = trim((string) ($f['param'] ?? ''));
                if ($param !== '' && ! preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $param)) {
                    throw $bad("“{$o['label']}”: the link parameter uses letters, digits, _ . and - (like utm_source).");
                }
                $o['param'] = $param ?: null;
                $o['value'] = mb_substr(trim((string) ($f['value'] ?? '')), 0, 255) ?: null; // used when the parameter is missing, or always without one
                if (! $o['param'] && ! $o['value']) {
                    throw $bad("“{$o['label']}”: a hidden field needs a link parameter (like utm_source) or a fixed value.");
                }
                $o['width'] = 'full';
            }
            $fields[$key] = $o;
        }
        if (count($fields) > $max) {
            throw $bad("A form can ask for up to $max fields besides the email.");
        }

        return array_values($fields);
    }

    /**
     * @return array<string, mixed>
     */
    public static function design(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $bad = fn (string $m) => new ApiException('validation_failed', $m, 422, ['design' => $m]);
        $d = self::DESIGN;
        foreach (['accent', 'background', 'card', 'text'] as $k) {
            if (isset($in[$k])) {
                $d[$k] = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $in[$k]) ? strtoupper((string) $in[$k]) : throw $bad('Colours are like #5B5BF7.');
            }
        }
        $d['font'] = in_array($in['font'] ?? null, self::FONTS, true) ? $in['font'] : $d['font'];
        $d['radius'] = isset($in['radius']) ? max(0, min(28, (int) $in['radius'])) : $d['radius'];
        $d['layout'] = in_array($in['layout'] ?? null, self::LAYOUTS, true) ? $in['layout'] : $d['layout'];
        $img = trim((string) ($in['image'] ?? ''));
        if ($img !== '' && (! filter_var($img, FILTER_VALIDATE_URL) || ! str_starts_with($img, 'https://') || strlen($img) > 1000)) {
            throw $bad('The image needs an https:// address.');
        }
        $d['image'] = $img ?: null;
        if ($d['layout'] !== 'card' && ! $d['image']) {
            $d['layout'] = 'card'; // an image layout without an image is a card
        }
        $d['button_full'] = (bool) ($in['button_full'] ?? $d['button_full']);
        $d['show_mark'] = (bool) ($in['show_mark'] ?? $d['show_mark']);
        $d['steps'] = array_slice(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 60), array_filter((array) ($in['steps'] ?? []), 'is_scalar')), 0, self::MAX_STEPS);

        return $d;
    }

    /**
     * @return array<string, mixed>
     */
    public static function display(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $d = self::DISPLAY;
        $d['mode'] = in_array($in['mode'] ?? null, self::MODES, true) ? $in['mode'] : 'inline';
        $d['delay'] = max(0, min(300, (int) ($in['delay'] ?? $d['delay'])));
        $d['scroll'] = max(0, min(100, (int) ($in['scroll'] ?? $d['scroll'])));
        $d['exit_intent'] = (bool) ($in['exit_intent'] ?? false);
        $d['frequency'] = in_array($in['frequency'] ?? null, ['every', 'session', 'days', 'once'], true) ? $in['frequency'] : $d['frequency'];
        $d['days'] = max(1, min(365, (int) ($in['days'] ?? $d['days'])));
        $pos = $in['position'] ?? null;
        $d['position'] = $d['mode'] === 'bar' ? ($pos === 'bottom' ? 'bottom' : 'top') : ($pos === 'left' ? 'left' : 'right');

        return $d;
    }

    /**
     * One submitted value, checked against its field. Returns [value to store or null, error or null].
     *
     * @param  array<string, mixed>  $f
     * @return array{0: string|null, 1: string|null}
     */
    public static function value(array $f, mixed $raw): array
    {
        $type = (string) ($f['type'] ?? 'text');
        $label = mb_strtolower((string) $f['label']);
        if ($type === 'hidden') {
            $v = $f['param'] && is_scalar($raw) ? mb_substr(trim((string) $raw), 0, 255) : '';

            return [$v !== '' ? $v : ($f['value'] ?? null), null]; // a fixed value can't be changed by the visitor
        }
        if ($type === 'checkboxes') {
            $picked = array_values(array_intersect((array) $f['options'], array_map('strval', array_filter(is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? explode(',', $raw) : []), 'is_scalar'))));
            $picked = $picked ?: array_values(array_intersect((array) $f['options'], array_map('trim', is_string($raw) ? explode(',', $raw) : [])));

            return $picked ? [implode(', ', $picked), null] : [null, $f['required'] ? 'Please choose at least one for '.$label.'.' : null];
        }
        $v = is_scalar($raw) ? trim((string) $raw) : '';
        if ($type === 'checkbox' || $type === 'consent') {
            $on = in_array(mb_strtolower($v), ['1', 'true', 'yes', 'on'], true);
            if (! $on && $f['required']) {
                return [null, $type === 'consent' ? 'Please tick the box to agree.' : 'Please tick '.$label.'.'];
            }

            return [$on ? 'yes' : ($type === 'checkbox' ? 'no' : null), null];
        }
        if ($v === '') {
            return [null, $f['required'] ? 'Please fill in '.$label.'.' : null];
        }
        $v = mb_substr($v, 0, $type === 'textarea' ? 2000 : 255);

        return match ($type) {
            'number' => is_numeric(str_replace([',', ' '], '', $v)) ? [(string) (str_replace([',', ' '], '', $v) + 0), null] : [null, 'Enter a number for '.$label.'.'],
            'phone' => preg_match('/^\+?[0-9 ()\-.]{6,20}$/', $v) ? [$v, null] : [null, 'Enter a valid phone number.'],
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? [$v, null] : [null, 'Enter a date for '.$label.'.'],
            'dropdown', 'radio' => in_array($v, (array) $f['options'], true) ? [$v, null] : [null, 'Choose one of the options for '.$label.'.'],
            default => [$v, null],
        };
    }
}
