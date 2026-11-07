<?php

namespace App\Services\Journeys;

use App\Models\Contact;
use App\Services\Contacts\ContactService;

/**
 * The data that flows through a journey, as in n8n: every step sees one object
 *   {contact: {...the contact, fields}, trigger: {event, properties}, steps: {<step id>: <that step's output>}}
 * and adds its own output to "steps". Settings can use it with expressions like {{ trigger.properties.amount }},
 * {{ contact.fields.plan }} or {{ steps.n3k2.body.plan }}; If / else can branch on any value in it.
 */
class JourneyData
{
    /** Largest output kept per step (bytes of JSON). Bigger values are summarised, not stored. */
    public const MAX_OUTPUT = 8000;

    /** Largest data kept on one run; later outputs beyond it are summarised. */
    public const MAX_RUN = 64000;

    /** {{ contact.… }}, {{ trigger.… }}, {{ steps.<id>.… }}. Other merge tags ({{ first_name | there }}) are left alone. */
    public const EXPRESSION = '/\{\{\s*((?:contact|trigger|steps)(?:\.[A-Za-z0-9_-]+)+)\s*\}\}/';

    /**
     * What a step sees.
     *
     * @param  array<string, mixed>|null  $context  the event that started the run
     * @param  array<string, mixed>|null  $runData  the run's data so far (steps)
     * @return array{contact: array<string, mixed>, trigger: array{event: string|null, properties: array<mixed>}, steps: array<string, mixed>}
     */
    public static function input(Contact $contact, ?array $context, ?array $runData): array
    {
        $c = ContactService::present($contact);
        $c['fields'] = (object) (array) $c['fields']; // {} even when empty, never []

        return [
            'contact' => $c,
            'trigger' => ['event' => isset($context['event']) ? (string) $context['event'] : null, 'properties' => (array) ($context['properties'] ?? [])],
            'steps' => (array) ($runData['steps'] ?? []),
        ];
    }

    /**
     * A value by dot path ("steps.n1.body.items.0.name"). Null when it isn't there.
     *
     * @param  array<mixed>  $data
     */
    public static function get(array $data, string $path): mixed
    {
        $v = $data;
        foreach (explode('.', $path) as $key) {
            if (is_object($v)) {
                $v = (array) $v;
            }
            if (! is_array($v) || ! array_key_exists($key, $v)) {
                return null;
            }
            $v = $v[$key];
        }

        return $v;
    }

    /**
     * Fills every expression; lists and objects become JSON, missing values become empty.
     *
     * @param  array<mixed>  $data
     */
    public static function resolve(string $text, array $data): string
    {
        return (string) preg_replace_callback(self::EXPRESSION, function ($m) use ($data) {
            $v = self::get($data, $m[1]);

            return match (true) {
                $v === null => '',
                is_bool($v) => $v ? 'true' : 'false',
                is_scalar($v) => (string) $v,
                default => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
        }, $text);
    }

    public static function uses(string $text): bool
    {
        return (bool) preg_match(self::EXPRESSION, $text);
    }

    /**
     * A step's output, small enough to keep: values that would make it too large are replaced by a note.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function clip(array $output, int $max = self::MAX_OUTPUT): array
    {
        if (strlen(self::json($output)) <= $max) {
            return $output;
        }
        $sizes = array_map(fn ($v) => strlen(self::json($v)), $output);
        arsort($sizes);
        foreach (array_keys($sizes) as $key) {
            $output[$key] = '(too large to keep: '.round($sizes[$key] / 1024, 1).' KB)';
            if (strlen(self::json($output)) <= $max) {
                break;
            }
        }

        return $output;
    }

    /**
     * The run's data with one more step's output.
     *
     * @param  array<string, mixed>|null  $runData
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function add(?array $runData, string $node, array $output): array
    {
        $runData = (array) $runData;
        $runData['steps'] = (array) ($runData['steps'] ?? []);
        $runData['steps'][$node] = $output;
        if (strlen(self::json($runData)) > self::MAX_RUN) {
            $runData['steps'][$node] = ['note' => 'Not kept: this run already holds '.round(self::MAX_RUN / 1024).' KB of step data.'];
        }

        return $runData;
    }

    private static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
