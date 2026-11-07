<?php

use App\Models\User;
use App\Support\EnvFile;
use App\Support\Installation;
use App\Support\WebInstaller;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sl-webinstall-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    WebInstaller::$dir = $this->dir;
    $this->sqlite = $this->dir.'/db.sqlite';
});

afterEach(function () {
    WebInstaller::$dir = null;
    File::deleteDirectory($this->dir);
});

/** The form as the installer page sends it. */
function installForm(array $over = []): array
{
    return array_replace_recursive([
        'url' => 'https://mail.example.com',
        'db' => ['driver' => 'sqlite'],
        'mail' => ['mailer' => 'smtp', 'from' => 'no-reply@example.com', 'host' => 'smtp.example.com', 'port' => '465', 'user' => 'apikey', 'password' => 'smtp secret'],
    ], $over);
}

it('shows the server checks and the defaults until the installation has its admin, then closes', function () {
    $r = $this->getJson('/v1/install')->assertOk()->json();
    expect($r['installed'])->toBeFalse()
        ->and(collect($r['checks'])->pluck('name'))->toContain('PHP add-ons', 'Web folder')
        ->and($r['defaults']['url'])->toBe('http://localhost')
        ->and($r['ready'])->toBeFalse(); // no web folder outside the zip

    makeUser();
    $this->getJson('/v1/install')->assertOk()->assertExactJson(['installed' => true]);
    $this->postJson('/v1/install/database', ['db' => ['driver' => 'sqlite']])->assertNotFound();
    $this->postJson('/v1/install/settings', installForm())->assertNotFound();
});

it('tests the database before anything is saved, with an answer that says what to fix', function () {
    $this->postJson('/v1/install/database', ['db' => ['driver' => 'sqlite']])->assertOk()->assertJson(['ok' => true]);
    $r = $this->postJson('/v1/install/database', ['db' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'name' => 'sendline', 'user' => 'x']])->assertStatus(422)->json();
    expect($r['step'])->toBe('db')->and($r['message'])->toStartWith('Couldn’t connect to the database');
    expect(file_exists(WebInstaller::envPath()))->toBeFalse();
});

it('writes .env from the form and hands out a one-time token for the next steps', function () {
    $this->postJson('/v1/install/settings', installForm(['url' => 'https://mail.example.com/app']))->assertStatus(422)->assertJsonPath('step', 'url');
    $this->postJson('/v1/install/settings', installForm(['mail' => ['host' => '']]))->assertStatus(422)->assertJsonPath('step', 'mail');

    $token = $this->postJson('/v1/install/settings', installForm())->assertOk()->json('token');
    expect($token)->toMatch('/^[a-f0-9]{48}$/')->and(WebInstaller::tokenMatches($token))->toBeTrue();
    $env = new EnvFile(WebInstaller::envPath());
    expect($env->get('APP_URL'))->toBe('https://mail.example.com/api')
        ->and($env->get('FRONTEND_URL'))->toBe('https://mail.example.com')
        ->and($env->get('SESSION_SECURE_COOKIE'))->toBe('true')
        ->and($env->get('DB_CONNECTION'))->toBe('sqlite')
        ->and($env->get('CACHE_STORE'))->toBe('file')
        ->and($env->get('MAIL_HOST'))->toBe('smtp.example.com')
        ->and($env->get('MAIL_SCHEME'))->toBe('smtps')
        ->and($env->get('MAIL_PASSWORD'))->toBe('smtp secret')
        ->and($env->get('APP_KEY'))->toStartWith('base64:')
        ->and(decbin(fileperms(WebInstaller::envPath()) & 0777))->toBe('110000000'); // 0600

    // Run again (the person went back to fix something): the key stays, the token is new.
    $key = $env->get('APP_KEY');
    $again = $this->postJson('/v1/install/settings', installForm())->assertOk()->json('token');
    expect($again)->not->toBe($token)->and(WebInstaller::tokenMatches($token))->toBeFalse()->and((new EnvFile(WebInstaller::envPath()))->get('APP_KEY'))->toBe($key);
});

it('needs the token for the tables and the admin, then creates the verified admin and closes for good', function () {
    $this->postJson('/v1/install/tables')->assertForbidden();
    $this->postJson('/v1/install/admin', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'a long enough passphrase'])->assertForbidden();
    $this->withHeader('X-Install-Token', 'not-the-token')->postJson('/v1/install/admin')->assertForbidden();

    $h = ['X-Install-Token' => WebInstaller::newToken()];
    $this->withHeaders($h)->postJson('/v1/install/tables')->assertOk();
    $this->withHeaders($h)->postJson('/v1/install/admin', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'short'])
        ->assertStatus(422)->assertJsonPath('step', 'admin')->assertJsonPath('message', 'Use at least 12 characters.');
    expect(User::count())->toBe(0);

    $r = $this->withHeaders($h)->postJson('/v1/install/admin', ['name' => 'Ada Obi', 'email' => 'Ada@Example.com', 'password' => 'a long enough passphrase', 'timezone' => 'Africa/Lagos'])->assertOk()->json();
    $u = User::sole();
    expect($u->email)->toBe('ada@example.com')->and($u->hasVerifiedEmail())->toBeTrue()->and($u->timezone)->toBe('Africa/Lagos')
        ->and(app(Installation::class)::isAdmin($u))->toBeTrue()
        ->and($r['cron'])->toContain('artisan schedule:run')->toContain(base_path())->and($r['cron'])->toBe('* * * * * '.$r['cron_command'])
        ->and($r['login'])->toEndWith('/login/')
        ->and(file_exists(WebInstaller::lockPath()))->toBeTrue()
        ->and(file_exists(WebInstaller::tokenPath()))->toBeFalse();

    // Closed: even with the old token, and even if the account is deleted later.
    $u->delete();
    $this->withHeaders($h)->postJson('/v1/install/admin', ['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'a long enough passphrase'])->assertNotFound();
    $this->getJson('/v1/install')->assertExactJson(['installed' => true]);
});

it('says what to fix in an address or an SQLite path', function () {
    expect(WebInstaller::databaseProblem(['driver' => 'sqlite', 'path' => '/nonexistent/dir/x.sqlite']))->toStartWith('Couldn’t create the SQLite file')
        ->and(WebInstaller::urlProblem('mail.example.com'))->toBe('Enter a full address, like https://mail.example.com')
        ->and(WebInstaller::urlProblem('https://example.com/sendline'))->toStartWith('MailVesper needs its own (sub)domain')
        ->and(WebInstaller::urlProblem('https://mail.example.com/'))->toBeNull();
});
