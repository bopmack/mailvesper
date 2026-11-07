<?php

namespace App\Services\Migration\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Shared HTTP handling: one place that turns provider errors into plain words, and rate limits into retries. */
abstract class BaseProvider implements Provider
{
    public const NAME = 'The provider';

    /** @param array<string, mixed> $credentials */
    public function __construct(protected array $credentials = []) {}

    abstract protected function http(): PendingRequest;

    /** The API host the key may be sent to (next-page links elsewhere are refused). */
    abstract protected function host(): string;

    protected function key(): string
    {
        $k = trim((string) ($this->credentials['api_key'] ?? ''));
        if ($k === '') {
            throw new ProviderError(static::NAME.' needs an API key.');
        }

        return $k;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    protected function get(string $url, array $query = []): array
    {
        if (preg_match('#^https?://#i', $url) && strtolower((string) parse_url($url, PHP_URL_HOST)) !== strtolower($this->host())) {
            throw new ProviderError(static::NAME.' returned a link to another host, so we stopped rather than send your key there.');
        }

        // An empty query array would make Guzzle strip the query already on a next-page link (…?page=next&p=…).
        return $this->check($this->send(fn () => $query === [] ? $this->http()->get($url) : $this->http()->get($url, $query)))->json() ?? [];
    }

    /** @param array<string, mixed> $body */
    protected function post(string $url, array $body = []): Response
    {
        return $this->check($this->send(fn () => $this->http()->post($url, $body)));
    }

    /** @param callable(): Response $call */
    protected function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            throw new ProviderError(static::NAME.' didn’t answer. We’ll try again shortly.', true, 60);
        }
    }

    protected function check(Response $r): Response
    {
        if ($r->successful()) {
            return $r;
        }
        $status = $r->status();
        if ($status === 401 || $status === 403) {
            throw new ProviderError(static::NAME.' rejected the API key, or it doesn’t have read access to this data.');
        }
        if ($status === 429) {
            throw new ProviderError(static::NAME.' is rate limiting us. We’ll continue shortly.', true, max(10, (int) ($r->header('Retry-After') ?: 60)));
        }
        if ($status >= 500) {
            throw new ProviderError(static::NAME.' had a temporary problem ('.$status.'). We’ll try again shortly.', true, 60);
        }
        $detail = $r->json('detail') ?? $r->json('message') ?? $r->json('error') ?? $r->json('errors.0.message') ?? $r->json('errors.0.detail');

        throw new ProviderError(static::NAME.' said: '.mb_substr(is_string($detail) ? $detail : 'HTTP '.$status, 0, 300));
    }
}
