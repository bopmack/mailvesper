<?php

namespace App\Services\Ai;

use App\Exceptions\ApiException;
use App\Services\Platform\Brand;
use App\Services\Platform\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one way MailVesper talks to an LLM. OpenRouter by default, OpenAI as the alternative: both speak the
 * OpenAI chat-completions format, so provider, model and key are just settings (platform admin panel).
 * Every call is logged to ai_usage with tokens and, when the provider reports it, cost.
 */
class AiClient
{
    public const ENDPOINTS = ['openrouter' => 'https://openrouter.ai/api/v1', 'openai' => 'https://api.openai.com/v1'];

    public function configured(): bool
    {
        return Settings::get('ai.enabled') && $this->key() !== null;
    }

    /**
     * The models a journey's AI agent may choose: the default first, then the admin's extra ones.
     *
     * @return list<string>
     */
    public function models(): array
    {
        $extra = array_map('trim', explode(',', (string) Settings::get('ai.models')));

        return array_values(array_unique(array_filter(array_merge([(string) Settings::get('ai.model')], $extra), fn ($m) => (bool) preg_match('#^[A-Za-z0-9._:/-]{2,120}$#', $m))));
    }

    /** @return array{provider: string, model: string, enabled: bool, has_key: bool} */
    public function describe(): array
    {
        return ['provider' => $this->provider(), 'model' => (string) Settings::get('ai.model'), 'enabled' => (bool) Settings::get('ai.enabled'), 'has_key' => $this->key() !== null];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array{workspace_id?: string|null, user_id?: string|null, max_tokens?: int, temperature?: float, json?: bool, tools?: list<array<string, mixed>>, model?: string|null}  $opts
     * @return array{text: string, tool_calls: list<array{id: string, name: string, arguments: string}>, usage: array{prompt: int, completion: int, cost: float|null}, model: string}
     */
    public function chat(string $feature, array $messages, array $opts = []): array
    {
        if (! Settings::get('ai.enabled')) {
            throw new ApiException('ai_disabled', 'AI features are turned off on this platform.', 503);
        }
        $key = $this->key() ?? throw new ApiException('ai_not_configured', 'AI isn’t set up yet: the platform admin needs to add an OpenRouter or OpenAI key.', 503);
        $provider = $this->provider();
        $model = ! empty($opts['model']) && in_array($opts['model'], $this->models(), true) ? (string) $opts['model'] : (string) Settings::get('ai.model');
        $body = ['model' => $model, 'messages' => $messages, 'max_tokens' => $opts['max_tokens'] ?? max(100, Settings::get('ai.max_output_tokens')), 'temperature' => $opts['temperature'] ?? 0.2];
        if ($opts['json'] ?? false) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        if (! empty($opts['tools'])) {
            $body['tools'] = $opts['tools'];
            $body['tool_choice'] = 'auto';
        }
        if ($provider === 'openrouter') {
            $body['usage'] = ['include' => true]; // OpenRouter reports the real cost
        }
        $started = microtime(true);
        $log = function (bool $ok, int $in = 0, int $out = 0, ?float $cost = null, ?string $error = null) use ($feature, $provider, $model, $opts, $started) {
            DB::table('ai_usage')->insert(['workspace_id' => $opts['workspace_id'] ?? null, 'user_id' => $opts['user_id'] ?? null, 'feature' => $feature, 'provider' => $provider, 'model' => mb_substr($model, 0, 120),
                'prompt_tokens' => $in, 'completion_tokens' => $out, 'cost_usd' => $cost, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'ok' => $ok, 'error' => $error ? mb_substr($error, 0, 255) : null, 'created_at' => now()]);
        };
        try {
            $res = Http::withToken($key)->withHeaders($provider === 'openrouter' ? ['HTTP-Referer' => rtrim((string) config('app.frontend_url'), '/'), 'X-Title' => Brand::name()] : [])
                ->acceptJson()->timeout(60)->connectTimeout(10)->retry(2, 800, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post(self::ENDPOINTS[$provider].'/chat/completions', $body);
        } catch (Throwable $e) {
            $log(false, error: 'Connection: '.$e->getMessage());
            throw new ApiException('ai_unavailable', 'The AI service didn’t answer. Try again in a minute.', 503);
        }
        if (! $res->successful()) {
            $msg = (string) ($res->json('error.message') ?? 'HTTP '.$res->status());
            $log(false, error: 'HTTP '.$res->status().': '.$msg);
            throw new ApiException('ai_unavailable', $res->status() === 429 ? 'The AI service is busy. Try again in a minute.' : 'The AI service returned an error. Try again in a minute.', 503);
        }
        $text = (string) ($res->json('choices.0.message.content') ?? '');
        $in = (int) $res->json('usage.prompt_tokens', 0);
        $out = (int) $res->json('usage.completion_tokens', 0);
        $cost = $res->json('usage.cost');
        $log(true, $in, $out, is_numeric($cost) ? (float) $cost : null);
        $calls = [];
        foreach ((array) ($res->json('choices.0.message.tool_calls') ?? []) as $i => $tc) {
            $name = (string) ($tc['function']['name'] ?? '');
            $args = $tc['function']['arguments'] ?? '{}';
            $args = is_string($args) ? $args : (string) json_encode($args);
            $calls[] = ['id' => (string) ($tc['id'] ?? '') ?: 'call_'.$i.'_'.substr(md5($name.$args), 0, 8), 'name' => $name, 'arguments' => $args];
        }

        return ['text' => $text, 'tool_calls' => $calls, 'usage' => ['prompt' => $in, 'completion' => $out, 'cost' => is_numeric($cost) ? (float) $cost : null], 'model' => (string) ($res->json('model') ?? $model)];
    }

    /**
     * Streams a reply that may call tools (OpenAI tool-calling format; OpenRouter passes it to the model).
     * Text deltas go to $onText as they arrive; tool calls are collected and returned when the reply ends.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  array{workspace_id?: string|null, user_id?: string|null, max_tokens?: int}  $opts
     * @param  callable(string): void  $onText
     * @return array{text: string, tool_calls: list<array{id: string, name: string, arguments: string}>, usage: array{prompt: int, completion: int, cost: float|null}}
     */
    public function stream(string $feature, array $messages, array $tools, array $opts, callable $onText): array
    {
        if (! Settings::get('ai.enabled')) {
            throw new ApiException('ai_disabled', 'AI features are turned off on this platform.', 503);
        }
        $key = $this->key() ?? throw new ApiException('ai_not_configured', 'AI isn’t set up yet: the platform admin needs to add an OpenRouter or OpenAI key.', 503);
        $provider = $this->provider();
        $model = (string) Settings::get('ai.model');
        $body = ['model' => $model, 'messages' => $messages, 'stream' => true, 'temperature' => 0.2, 'max_tokens' => $opts['max_tokens'] ?? max(400, Settings::get('ai.max_output_tokens'))];
        if ($tools) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }
        $body += $provider === 'openrouter' ? ['usage' => ['include' => true]] : ['stream_options' => ['include_usage' => true]];
        $started = microtime(true);
        $log = function (bool $ok, int $in = 0, int $out = 0, ?float $cost = null, ?string $error = null) use ($feature, $provider, $model, $opts, $started) {
            DB::table('ai_usage')->insert(['workspace_id' => $opts['workspace_id'] ?? null, 'user_id' => $opts['user_id'] ?? null, 'feature' => $feature, 'provider' => $provider, 'model' => mb_substr($model, 0, 120),
                'prompt_tokens' => $in, 'completion_tokens' => $out, 'cost_usd' => $cost, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'ok' => $ok, 'error' => $error ? mb_substr($error, 0, 255) : null, 'created_at' => now()]);
        };
        try {
            $res = Http::withToken($key)->withHeaders($provider === 'openrouter' ? ['HTTP-Referer' => rtrim((string) config('app.frontend_url'), '/'), 'X-Title' => Brand::name()] : [])
                ->withOptions(['stream' => true])->accept('text/event-stream')->timeout(120)->connectTimeout(10)
                ->post(self::ENDPOINTS[$provider].'/chat/completions', $body);
        } catch (Throwable $e) {
            $log(false, error: 'Connection: '.$e->getMessage());
            throw new ApiException('ai_unavailable', 'The AI service didn’t answer. Try again in a minute.', 503);
        }
        if (! $res->successful()) {
            $msg = (string) ($res->json('error.message') ?? 'HTTP '.$res->status());
            $log(false, error: 'HTTP '.$res->status().': '.$msg);
            throw new ApiException('ai_unavailable', $res->status() === 429 ? 'The AI service is busy. Try again in a minute.' : 'The AI service returned an error. Try again in a minute.', 503);
        }
        $stream = $res->toPsrResponse()->getBody();
        $buffer = '';
        $text = '';
        $calls = [];
        $usage = ['prompt' => 0, 'completion' => 0, 'cost' => null];
        $handle = function (string $line) use (&$text, &$calls, &$usage, $onText) {
            if (! str_starts_with($line, 'data:')) {
                return; // comments (": OPENROUTER PROCESSING") and blank lines
            }
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                return;
            }
            $j = json_decode($data, true);
            if (! is_array($j)) {
                return;
            }
            if (isset($j['error'])) {
                throw new ApiException('ai_unavailable', 'The AI service stopped with an error. Try again.', 503);
            }
            $d = $j['choices'][0]['delta'] ?? [];
            if (is_string($d['content'] ?? null) && $d['content'] !== '') {
                $text .= $d['content'];
                $onText($d['content']);
            }
            foreach ((array) ($d['tool_calls'] ?? []) as $tc) {
                $i = (int) ($tc['index'] ?? 0);
                $calls[$i] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                $calls[$i]['id'] .= (string) ($tc['id'] ?? '');
                $calls[$i]['name'] .= (string) ($tc['function']['name'] ?? '');
                $calls[$i]['arguments'] .= (string) ($tc['function']['arguments'] ?? '');
            }
            if (isset($j['usage'])) {
                $usage = ['prompt' => (int) ($j['usage']['prompt_tokens'] ?? 0), 'completion' => (int) ($j['usage']['completion_tokens'] ?? 0), 'cost' => is_numeric($j['usage']['cost'] ?? null) ? (float) $j['usage']['cost'] : null];
            }
        };
        try {
            while (! $stream->eof()) {
                $buffer .= $stream->read(4096);
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $handle(rtrim(substr($buffer, 0, $pos), "\r"));
                    $buffer = substr($buffer, $pos + 1);
                }
            }
            if ($buffer !== '') {
                $handle(rtrim($buffer, "\r"));
            }
        } catch (ApiException $e) {
            $log(false, $usage['prompt'], $usage['completion'], $usage['cost'], $e->getMessage());
            throw $e;
        }
        $log(true, $usage['prompt'], $usage['completion'], $usage['cost']);
        ksort($calls);
        foreach ($calls as $i => $c) {
            $calls[$i]['id'] = $c['id'] !== '' ? $c['id'] : 'call_'.$i.'_'.substr(md5($c['name'].$c['arguments']), 0, 8);
        }

        return ['text' => $text, 'tool_calls' => array_values($calls), 'usage' => $usage];
    }

    private function provider(): string
    {
        $p = (string) Settings::get('ai.provider');

        return isset(self::ENDPOINTS[$p]) ? $p : 'openrouter';
    }

    private function key(): ?string
    {
        return Settings::get($this->provider() === 'openai' ? 'ai.openai_key' : 'ai.openrouter_key');
    }
}
