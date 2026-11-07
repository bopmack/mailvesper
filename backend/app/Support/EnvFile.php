<?php

namespace App\Support;

use RuntimeException;

/** Reads and updates a .env file in place: changed keys keep their line, new keys are appended, comments stay. */
class EnvFile
{
    public function __construct(private string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }
        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $m)) {
                $v = trim($m[1]);
                if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
                    return stripcslashes(substr($v, 1, -1));
                }

                return $v;
            }
        }

        return null;
    }

    /** @param  array<string, string|int|bool|null>  $values */
    public function set(array $values): void
    {
        $lines = $this->exists() ? (file($this->path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new RuntimeException("Invalid .env key: $key");
            }
            $line = $key.'='.self::encode($value);
            $done = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*#?\s*'.preg_quote($key, '/').'\s*=/', $existing)) {
                    $lines[$i] = $line; // also replaces a commented-out example line
                    $done = true;
                    break;
                }
            }
            if (! $done) {
                $lines[] = $line;
            }
        }
        $tmp = $this->path.'.tmp';
        if (file_put_contents($tmp, implode("\n", $lines)."\n") === false || ! rename($tmp, $this->path)) {
            throw new RuntimeException("Couldn’t write {$this->path}");
        }
        @chmod($this->path, 0600);
    }

    public static function encode(string|int|bool|null $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $v = (string) $value;
        if ($v === '' || preg_match('/^[A-Za-z0-9_.:\/@+,-]+$/', $v)) {
            return $v;
        }

        return '"'.addcslashes($v, "\"\\\$\n\r\t").'"';
    }
}
