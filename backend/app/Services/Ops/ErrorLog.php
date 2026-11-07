<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Unexpected errors, grouped by where they happen (class + file + line), so the admin sees "this broke 40 times
 * since Tuesday" instead of 40 log lines. Expected, user-facing errors are not recorded. Never throws.
 */
class ErrorLog
{
    private static bool $busy = false;

    public static function capture(Throwable $e): void
    {
        if (self::$busy || $e instanceof ApiException || $e instanceof ValidationException || $e instanceof AuthenticationException
            || $e instanceof ModelNotFoundException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500)) {
            return;
        }
        self::$busy = true;
        try {
            [$file, $line] = self::origin($e);
            $fp = sha1(get_class($e).'|'.$file.'|'.$line);
            $now = now();
            $updated = DB::table('ops_errors')->where('fingerprint', $fp)->update(['count' => DB::raw('count + 1'), 'last_seen_at' => $now, 'resolved_at' => null,
                'message' => mb_substr($e->getMessage(), 0, 1000)]);
            if (! $updated) {
                DB::table('ops_errors')->insertOrIgnore(['fingerprint' => $fp, 'class' => mb_substr(get_class($e), 0, 255), 'message' => mb_substr($e->getMessage(), 0, 1000),
                    'file' => mb_substr($file, 0, 255), 'line' => $line, 'url' => request()->route() ? mb_substr(request()->method().' '.request()->path(), 0, 500) : 'console: '.mb_substr(implode(' ', (array) ($_SERVER['argv'] ?? [])), 0, 480),
                    'trace' => json_encode(array_slice(array_map(fn ($f) => ($f['class'] ?? '').($f['type'] ?? '').$f['function'].' '.self::short($f['file'] ?? '').':'.($f['line'] ?? ''), $e->getTrace()), 0, 12)),
                    'count' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now]);
            }
        } catch (Throwable) {
            // The database may be the thing that's broken; the normal log still has the error.
        } finally {
            self::$busy = false;
        }
    }

    /** @return array{0: string, 1: int} the first frame in our own code, else the throw site */
    private static function origin(Throwable $e): array
    {
        foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $f) {
            $file = (string) ($f['file'] ?? '');
            if ($file !== '' && ! str_contains($file, '/vendor/')) {
                return [self::short($file), (int) ($f['line'] ?? 0)];
            }
        }

        return [self::short($e->getFile()), $e->getLine()];
    }

    private static function short(string $file): string
    {
        return str_replace(base_path().'/', '', $file);
    }
}
