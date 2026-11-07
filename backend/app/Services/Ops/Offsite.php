<?php

namespace App\Services\Ops;

use App\Services\Platform\Brand;
use App\Services\Platform\Settings;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Closure;
use Throwable;

/**
 * Off-site copies of the daily backups, in any S3-compatible bucket (AWS S3, Backblaze B2, Cloudflare R2, Wasabi,
 * MinIO). Set up in Platform → Settings → Off-site backups. Old copies are removed by the bucket's own lifecycle
 * rule, so this server never needs permission to delete.
 */
class Offsite
{
    /** @var (Closure(array<string, mixed>): S3Client)|null tests swap in a client with a mock handler */
    public static ?Closure $factory = null;

    public static function configured(): bool
    {
        return Settings::get('backup.offsite_bucket') !== null && Settings::get('backup.offsite_key') !== null && Settings::get('backup.offsite_secret') !== null;
    }

    /** Where the admin panel says copies go, e.g. "s3://backups/sendline/" (never the keys). */
    public static function target(): ?string
    {
        return self::configured() ? 's3://'.Settings::get('backup.offsite_bucket').'/'.self::prefix() : null;
    }

    /** Uploads one backup file and returns its object key. */
    public static function upload(string $path): string
    {
        $key = self::prefix().basename($path);
        self::client()->putObject(['Bucket' => (string) Settings::get('backup.offsite_bucket'), 'Key' => $key, 'SourceFile' => $path,
            'ContentType' => 'application/gzip', 'Metadata' => ['sha256' => (string) hash_file('sha256', $path)]]);

        return $key;
    }

    /**
     * Writes a small test object and reads it back. Nothing is deleted (the keys may rightly lack that permission);
     * the bucket's lifecycle rule clears it with the backups.
     *
     * @return array{ok: bool, message: string}
     */
    public static function test(): array
    {
        if (! self::configured()) {
            return ['ok' => false, 'message' => 'Fill in the bucket, access key and secret first.'];
        }
        try {
            $key = self::prefix().'sendline-connection-test.txt';
            $body = Brand::name().' wrote this at '.now()->toIso8601ZuluString().' to check off-site backups.';
            $c = self::client();
            $c->putObject(['Bucket' => (string) Settings::get('backup.offsite_bucket'), 'Key' => $key, 'Body' => $body, 'ContentType' => 'text/plain']);
            $back = (string) $c->getObject(['Bucket' => (string) Settings::get('backup.offsite_bucket'), 'Key' => $key])['Body'];

            return $back === $body ? ['ok' => true, 'message' => 'Wrote and read back '.$key.'.']
                : ['ok' => false, 'message' => 'The test file came back different. Check the endpoint.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => self::explain($e)];
        }
    }

    public static function explain(Throwable $e): string
    {
        $m = $e instanceof AwsException ? ($e->getAwsErrorCode() ?: '').': '.($e->getAwsErrorMessage() ?: $e->getMessage()) : $e->getMessage();

        return mb_substr(match (true) {
            str_contains($m, 'NoSuchBucket') => 'That bucket doesn’t exist (check the name and region).',
            str_contains($m, 'InvalidAccessKeyId'), str_contains($m, 'SignatureDoesNotMatch') => 'The access key or secret is wrong.',
            str_contains($m, 'AccessDenied') => 'The key isn’t allowed to write to this bucket (it needs PutObject and GetObject).',
            default => trim($m, ': '),
        }, 0, 300);
    }

    private static function prefix(): string
    {
        $p = trim((string) Settings::get('backup.offsite_prefix'), '/');

        return $p === '' ? '' : $p.'/';
    }

    private static function client(): S3Client
    {
        $endpoint = trim((string) Settings::get('backup.offsite_endpoint'));
        $config = ['version' => '2006-03-01', 'region' => Settings::get('backup.offsite_region') ?: 'us-east-1',
            'credentials' => ['key' => (string) Settings::get('backup.offsite_key'), 'secret' => (string) Settings::get('backup.offsite_secret')],
            // Other S3-compatible services reject the newer default checksums and expect path-style URLs.
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
            'http' => ['connect_timeout' => 10, 'timeout' => 900]];
        if ($endpoint !== '') {
            $config += ['endpoint' => $endpoint, 'use_path_style_endpoint' => true];
        }

        return self::$factory ? (self::$factory)($config) : new S3Client($config);
    }
}
