<?php

use App\Services\Design\EmailRenderer;

/** Node on this machine, if any: only developers have it; the app never needs it. */
function parityNode(): ?string
{
    foreach ([trim((string) shell_exec('command -v node 2>/dev/null')), '/usr/local/bin/node', '/opt/homebrew/bin/node'] as $n) {
        if ($n !== '' && is_executable($n)) {
            return $n;
        }
    }

    return null;
}

/** @return array{html: string, kb: float, checks: array<int, array<string, string>>} */
function nodeRender(string $node, array $doc, string $subject, string $assetBase): array
{
    $p = proc_open([$node, resource_path('render.cjs')], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], (string) json_encode(['doc' => $doc, 'subject' => $subject, 'assetBase' => $assetBase], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);
    expect($err)->toBe('');

    return json_decode((string) $out, true);
}

it('renders every gallery design byte-for-byte like the builder’s JavaScript', function () {
    $node = parityNode();
    if (! $node) {
        $this->markTestSkipped('Node.js isn’t on this machine; the parity check runs on developer machines.');
    }
    $docs = array_merge(
        json_decode(file_get_contents(base_path('tests/fixtures/gallery-docs.json')), true),
        json_decode(file_get_contents(base_path('tests/fixtures/edge-docs.json')), true),
        [['name' => 'design.json', 'doc' => json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true)]],
    );
    $base = rtrim((string) config('app.frontend_url'), '/').'/app';
    foreach ($docs as $i => ['name' => $name, 'doc' => $doc]) {
        $subject = $i % 2 ? 'Hello & “welcome”, {{ first_name | there }}' : '';
        $js = nodeRender($node, $doc, $subject, $base);
        $php = app(EmailRenderer::class)->render($doc, $subject);
        expect($php['html'])->toBe($js['html'], "HTML differs for “{$name}”")
            ->and($php['kb'])->toEqual($js['kb'], "Size differs for “{$name}”")
            ->and($php['checks'])->toBe($js['checks'], "Checks differ for “{$name}”");
    }
    expect(count($docs))->toBeGreaterThan(35);
});
