<?php

// Runs Pest and fails unless it printed a clean summary. A fatal error inside Pest can
// otherwise end the run with exit code 0, which would let a broken build deploy.
// Extra arguments go to Pest (the Pro suite passes its test folder).
$cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../vendor/bin/pest').' --colors=never '.implode(' ', array_map('escapeshellarg', array_slice($argv, 1))).' 2>&1';
$output = [];
exec($cmd, $output, $code);
$text = implode("\n", $output);
echo $text, "\n";

$ok = $code === 0
    && preg_match('/Tests:\s+(\d+) passed/', $text)
    && ! preg_match('/Tests:.*\b(failed|errors?)\b/i', $text);

if (! $ok) {
    fwrite(STDERR, "\n✗ Test run failed or did not complete (exit {$code}).\n");
    exit(1);
}
