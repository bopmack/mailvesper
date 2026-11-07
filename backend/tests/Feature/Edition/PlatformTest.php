<?php

use App\Exceptions\ApiException;
use App\Mail\OpsAlertMail;
use App\Services\Ops\Alerts;
use App\Services\Ops\Backups;
use App\Services\Ops\ErrorLog;
use App\Services\Ops\Health;
use App\Services\Ops\Offsite;
use App\Services\Ops\Retention;
use App\Services\Ops\State;
use App\Services\Platform\Settings;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = verifiedUser(['email' => 'admin@example.com', 'name' => 'Ada Admin']);
    Health::$disk = fn () => [100 * 1024 ** 3, 500 * 1024 ** 3]; // not the test machine's disk
    asProEdition(); // the panel is MailVesper Pro; Community's part is in CommunityPlatformTest
});

afterEach(function () {
    Health::$disk = null;
    Offsite::$factory = null;
});

it('is for the platform admin only', function () {
    $this->actingAs(verifiedUser(['email' => 'other@example.com']));
    foreach (['/v1/platform/settings', '/v1/platform/system'] as $u) {
        $this->getJson($u)->assertForbidden();
    }
    $this->getJson('/v1/me')->assertJsonPath('platform_admin', false);
});

it('tells the app who the platform admin is', function () {
    $this->actingAs($this->admin);
    $this->getJson('/v1/me')->assertJsonPath('platform_admin', true);
});

it('answers a signed-out request with a JSON 401 even when the client doesn’t ask for JSON', function () {
    $this->get('/v1/me', ['Accept' => 'text/html'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('reports low disk space sensibly for small and large disks', function () {
    $gb = 1024 ** 3;
    foreach ([[1.5, 100, false], [4, 245, false], [30, 1000, true], [50, 60, true]] as [$free, $total, $ok]) {
        Health::$disk = fn () => [$free * $gb, $total * $gb];
        expect(Health::snapshot()['disk']['ok'])->toBe($ok);
    }
});

it('edits settings by group, keeps secrets masked, and checks numbers', function () {
    $this->actingAs($this->admin);
    Settings::set('ai.openrouter_key', 'sk-or-secret-9876');
    $g = $this->getJson('/v1/platform/settings')->assertOk()->json('groups');
    expect(array_keys($g))->toContain('AI', 'Operations', 'Data retention')
        ->and(collect($g['AI'])->firstWhere('key', 'ai.openrouter_key'))->toMatchArray(['secret' => true, 'value' => '••••9876', 'label' => 'OpenRouter API key']);
    $this->putJson('/v1/platform/settings', ['settings' => ['ai.openrouter_key' => '••••9876', 'ai.model' => 'openai/gpt-5.6', 'retention.body_days' => '90', 'license.key' => 'sneaky', 'platform.admins' => 'x']])->assertOk();
    expect(Settings::get('ai.openrouter_key'))->toBe('sk-or-secret-9876')->and(Settings::get('ai.model'))->toBe('openai/gpt-5.6')->and(Settings::get('retention.body_days'))->toBe(90)
        ->and(Settings::get('license.key'))->toBeNull()->and(Settings::get('platform.admins'))->toBeNull(); // not editable here
    $this->putJson('/v1/platform/settings', ['settings' => ['ai.chat_per_hour' => 'lots']])->assertStatus(422);
});

it('tests the AI connection with the saved settings', function () {
    $this->actingAs($this->admin);
    $this->postJson('/v1/platform/ai/test')->assertOk()->assertJson(['ok' => false]);
    Settings::set('ai.openrouter_key', 'sk-or-1');
    Http::fake(['openrouter.ai/*' => Http::response(['model' => 'anthropic/claude-sonnet-5.5', 'choices' => [['message' => ['content' => 'ready']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]])]);
    $this->postJson('/v1/platform/ai/test')->assertOk()->assertJson(['ok' => true, 'answer' => 'ready', 'model' => 'anthropic/claude-sonnet-5.5']);
});

it('answers the deep health check with 503 until the scheduler has run', function () {
    $this->getJson('/v1/health?deep=1')->assertStatus(503)->assertJson(['scheduler' => 'stale']);
    $this->artisan('ops:heartbeat')->assertSuccessful();
    $this->getJson('/v1/health?deep=1')->assertOk()->assertJson(['status' => 'ok', 'scheduler' => 'ok', 'queue' => 'ok']);
    $this->getJson('/v1/health')->assertOk()->assertJson(['status' => 'ok']); // the plain check stays cheap
});

it('groups unexpected errors by where they happen, leaves expected ones out, and shows them to the admin', function () {
    Route::get('/v1/_boom', fn () => throw new RuntimeException('Kaboom '.Str::random(4)));
    Route::get('/v1/_expected', fn () => throw new ApiException('nope', 'Expected.', 422));
    $this->getJson('/v1/_boom')->assertStatus(500);
    $this->getJson('/v1/_boom')->assertStatus(500);
    $this->getJson('/v1/_expected')->assertStatus(422);
    expect(DB::table('ops_errors')->count())->toBe(1)->and((int) DB::table('ops_errors')->value('count'))->toBe(2)->and(DB::table('ops_errors')->value('url'))->toBe('GET v1/_boom');
    $this->actingAs($this->admin);
    $e = $this->getJson('/v1/platform/system')->assertOk()->json('errors.0');
    expect($e['class'])->toBe('RuntimeException')->and($e['file'])->toContain('tests/Feature/Edition/PlatformTest.php');
    $this->postJson("/v1/platform/errors/{$e['id']}/resolve")->assertOk();
    expect($this->getJson('/v1/platform/system')->json('errors'))->toBe([]);
    ErrorLog::capture(new RuntimeException('again')); // never throws, even when called directly
});

it('keeps slow queries over the threshold', function () {
    config(['sendline.slow_query_ms' => 0]);
    DB::table('users')->count();
    expect(DB::table('ops_slow_queries')->where('sql', 'like', '%from "users"%')->exists())->toBeTrue();
});

it('emails the admins when something breaks, reminds every 6 hours, and says when it’s fixed', function () {
    Mail::fake();
    State::put('heartbeat', now()->subMinutes(20)->toIso8601ZuluString());
    expect(Alerts::check())->toContain('scheduler');
    Mail::assertSent(OpsAlertMail::class, fn ($m) => $m->hasTo('admin@example.com') && $m->title === '[MailVesper] The scheduler isn’t running');
    Alerts::check();
    Mail::assertSent(OpsAlertMail::class, 1); // not again within 6 hours
    $this->travel(7)->hours();
    State::put('heartbeat', now()->subMinutes(20)->toIso8601ZuluString());
    Alerts::check();
    Mail::assertSent(OpsAlertMail::class, 2);
    State::put('heartbeat', now()->toIso8601ZuluString());
    Alerts::check();
    Mail::assertSent(OpsAlertMail::class, fn ($m) => str_starts_with($m->title, '[MailVesper] Fixed:'));

    // A new kind of error is reported once; named recipients replace the admins.
    Settings::set('ops.alert_emails', 'oncall@example.com, not-an-email');
    ErrorLog::capture(new LogicException('New failure'));
    Alerts::check();
    Mail::assertSent(OpsAlertMail::class, fn ($m) => $m->hasTo('oncall@example.com') && $m->title === '[MailVesper] New errors' && str_contains(implode(' ', $m->lines), 'New failure'));
});

it('sends a test alert from the System page', function () {
    Mail::fake();
    $this->actingAs($this->admin);
    $this->postJson('/v1/platform/alerts/test')->assertOk()->assertJson(['sent' => 1, 'recipients' => ['admin@example.com']]);
    Mail::assertSent(OpsAlertMail::class, fn ($m) => $m->title === '[MailVesper] Test alert');
});

it('checks a restore drill table by table, allowing for rows written during the backup', function () {
    expect(Backups::compare(['users' => 10, 'messages' => 10000], ['users' => 10, 'messages' => 9950]))->toBe([])
        ->and(Backups::compare(['users' => 10, 'messages' => 10000], ['users' => 10, 'messages' => 8000]))->toBe(['Table messages has 8000 rows, expected about 10000.'])
        ->and(Backups::compare(['users' => 10, 'campaigns' => 3], ['users' => 10]))->toBe(['Table campaigns is missing.']);
    $r = app(Backups::class)->run(); // the test database is SQLite
    expect($r['status'])->toBe('failed')->and($r['error'])->toContain('Backups need MySQL or MariaDB');
});

it('applies data retention only when the admin sets it', function () {
    [$ws] = sesWorkspace($this);
    $mk = fn (int $daysAgo) => DB::table('messages')->insert(['id' => strtolower((string) Str::ulid()), 'workspace_id' => $ws->id, 'tracking_code' => 'SL-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
        'source' => 'api', 'type' => 'marketing', 'from_email' => 'a@acme.com', 'to_email' => 'r@x.com', 'subject' => 's', 'html' => '<p>secret</p>', 'text' => 'secret', 'status' => 'delivered',
        'created_at' => now()->subDays($daysAgo), 'updated_at' => now()]);
    $mk(200);
    $mk(10);
    DB::table('ses_events')->insert(['workspace_id' => $ws->id, 'event_type' => 'Delivery', 'sns_message_id' => 'old', 'payload' => '{}', 'created_at' => now()->subDays(100)]);
    expect(Retention::prune())->toBe(['bodies' => 0, 'raw_events' => 0]);
    Settings::set('retention.body_days', 90);
    Settings::set('retention.raw_event_days', 30);
    expect(Retention::prune())->toBe(['bodies' => 1, 'raw_events' => 1])
        ->and(DB::table('messages')->whereNotNull('html')->count())->toBe(1)->and(DB::table('messages')->count())->toBe(2);
});

/** An S3 client whose calls are recorded and answered by $answers, one per call. */
function fakeS3(array &$calls, array $answers): void
{
    Offsite::$factory = function (array $config) use (&$calls, $answers) {
        $mock = new MockHandler;
        foreach ($answers as $a) {
            $mock->append(function (CommandInterface $cmd) use (&$calls, $a, $config) {
                $calls[] = [$cmd->getName(), $cmd['Bucket'], $cmd['Key'], $config['endpoint'] ?? null];

                return is_callable($a) ? $a($cmd) : $a;
            });
        }

        return new S3Client($config + ['handler' => $mock]);
    };
}

function offsiteSettings(): void
{
    Settings::set('backup.offsite_bucket', 'acme-backups');
    Settings::set('backup.offsite_prefix', '/sendline/');
    Settings::set('backup.offsite_endpoint', 'https://s3.us-west-004.backblazeb2.com');
    Settings::set('backup.offsite_key', 'K004abc');
    Settings::set('backup.offsite_secret', 'secret-xyz');
}

it('copies each backup to the off-site bucket and alerts when a copy fails', function () {
    Mail::fake();
    $file = tempnam(sys_get_temp_dir(), 'bk').'.sql.gz';
    file_put_contents($file, str_repeat('x', 200));
    $id = DB::table('ops_backups')->insertGetId(['status' => 'ok', 'file' => basename($file), 'size' => 200, 'started_at' => now(), 'finished_at' => now()]);

    app(Backups::class)->copyOffsite($id, $file); // not set up: nothing happens
    expect(DB::table('ops_backups')->find($id)->offsite_status)->toBeNull()->and(Health::snapshot()['offsite']['ok'])->toBeTrue();

    offsiteSettings();
    $calls = [];
    fakeS3($calls, [new Result([])]);
    app(Backups::class)->copyOffsite($id, $file);
    expect($calls)->toBe([['PutObject', 'acme-backups', 'sendline/'.basename($file), 'https://s3.us-west-004.backblazeb2.com']])
        ->and(DB::table('ops_backups')->find($id))->offsite_status->toBe('ok')->offsite_key->toBe('sendline/'.basename($file));

    fakeS3($calls, [fn (CommandInterface $c) => new AwsException('denied', $c, ['code' => 'AccessDenied'])]);
    app(Backups::class)->copyOffsite($id, $file);
    expect(DB::table('ops_backups')->find($id)->offsite_error)->toContain('needs PutObject')->and(Alerts::check())->toContain('offsite');
    Mail::assertSent(OpsAlertMail::class, fn ($m) => $m->title === '[MailVesper] The off-site backup copy failed' && str_contains(implode(' ', $m->lines), 's3://acme-backups/sendline/'));
    @unlink($file);
});

it('tests the off-site bucket from the System page, keeping the secret masked in settings', function () {
    $this->actingAs($this->admin);
    $this->postJson('/v1/platform/backups/offsite-test')->assertOk()->assertJson(['ok' => false, 'message' => 'Fill in the bucket, access key and secret first.']);
    offsiteSettings();
    $calls = [];
    $body = null;
    fakeS3($calls, [function (CommandInterface $c) use (&$body) {
        $body = (string) $c['Body'];

        return new Result([]);
    }, function () use (&$body) {
        return new Result(['Body' => $body]);
    }]);
    $this->postJson('/v1/platform/backups/offsite-test')->assertOk()->assertJson(['ok' => true, 'message' => 'Wrote and read back sendline/sendline-connection-test.txt.']);
    expect(array_column($calls, 0))->toBe(['PutObject', 'GetObject']);
    $g = $this->getJson('/v1/platform/settings')->json('groups');
    expect(collect($g['Off-site backups'])->firstWhere('key', 'backup.offsite_secret')['value'])->toBe('••••-xyz');
    $this->getJson('/v1/platform/system')->assertJsonPath('health.offsite.target', 's3://acme-backups/sendline/');
});
