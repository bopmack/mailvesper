<?php

namespace Tests\Support;

use App\Services\Migration\Providers\Provider;
use App\Services\Migration\Providers\ProviderError;

/** A scripted provider: pages per stage, optional failures. */
class FakeProvider implements Provider
{
    /** @var array<string, list<list<array<string, mixed>>>> stage → pages */
    public static array $pages = [];

    /** @var list<string> stages fetched, in order */
    public static array $log = [];

    public static ?ProviderError $failOnce = null;

    public static bool $badKey = false;

    /** @param array<string, mixed> $credentials */
    public function __construct(public array $credentials = []) {}

    public function account(): array
    {
        if (self::$badKey) {
            throw new ProviderError('Mailchimp rejected the API key.');
        }

        return ['name' => 'Acme Shoes (fake)'];
    }

    public function discover(): array
    {
        return ['contacts' => 4, 'suppressions' => 2, 'lists' => [['ext_id' => 'L1', 'name' => 'Newsletter', 'count' => 4]], 'templates' => 1, 'notes' => ['Segments arrive as tags.']];
    }

    public function stages(): array
    {
        return ['suppressions', 'lists', 'contacts', 'templates'];
    }

    public function fetch(string $stage, ?array $cursor, array $options): array
    {
        if (self::$failOnce) {
            $e = self::$failOnce;
            self::$failOnce = null;
            throw $e;
        }
        self::$log[] = $stage;
        $i = (int) ($cursor['page'] ?? 0);
        $pages = self::$pages[$stage] ?? [[]];

        return ['items' => $pages[$i] ?? [], 'cursor' => $i + 1 < count($pages) ? ['page' => $i + 1] : null, 'total' => array_sum(array_map('count', $pages))];
    }

    public function convertMergeTags(string $html): string
    {
        return str_replace(['*|FNAME|*', '*|UNSUB|*'], ['{{ first_name }}', '{{unsubscribe_url}}'], $html);
    }
}
