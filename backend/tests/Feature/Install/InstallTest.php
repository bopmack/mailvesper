<?php

use App\Console\Commands\Webroot;
use App\Models\User;
use App\Support\EnvFile;
use App\Support\Installation;
use App\Support\Requirements;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;

function installTmp(): string
{
    $dir = sys_get_temp_dir().'/sendline-install-'.bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

it('writes .env values that Laravel reads back exactly, keeping the rest of the file', function () {
    $dir = installTmp();
    file_put_contents("$dir/.env", "# keep me\nAPP_NAME=Old\n# DB_HOST=127.0.0.1\nOTHER=stays\n");
    $tricky = 'p@ss "word" $HOME \\ back#slash';
    (new EnvFile("$dir/.env"))->set(['APP_NAME' => 'MailVesper', 'DB_HOST' => 'db.internal', 'DB_PASSWORD' => $tricky, 'APP_DEBUG' => false, 'SESSION_DOMAIN' => null, 'NEW_KEY' => 'https://mail.example.com/api']);

    $text = (string) file_get_contents("$dir/.env");
    expect($text)->toContain("# keep me\nAPP_NAME=MailVesper\nDB_HOST=db.internal\nOTHER=stays\n") // the commented example line was replaced in place
        ->and(substr_count($text, 'DB_HOST'))->toBe(1);
    $parsed = Dotenv::parse($text);
    expect($parsed['DB_PASSWORD'])->toBe($tricky)
        ->and($parsed['NEW_KEY'])->toBe('https://mail.example.com/api')
        ->and($parsed['APP_DEBUG'])->toBe('false')
        ->and((new EnvFile("$dir/.env"))->get('DB_PASSWORD'))->toBe($tricky)
        ->and(decoct(fileperms("$dir/.env") & 0777))->toBe('600');
    expect(fn () => (new EnvFile("$dir/.env"))->set(['bad key' => 'x']))->toThrow(RuntimeException::class);
    File::deleteDirectory($dir);
});

it('creates the first account as the verified installation admin, and only once', function () {
    putenv('MAILVESPER_ADMIN_PASSWORD=correct horse battery staple');
    $this->artisan('mailvesper:make-admin', ['--email' => 'Owner@Example.com', '--name' => 'Ada Owner', '--no-interaction' => true])->assertSuccessful();
    $u = User::sole();
    expect($u->email)->toBe('owner@example.com')
        ->and($u->hasVerifiedEmail())->toBeTrue()
        ->and(Installation::isAdmin($u))->toBeTrue();
    $this->postJson('/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'correct horse battery staple'])->assertOk();

    $this->artisan('mailvesper:make-admin', ['--email' => 'second@example.com', '--no-interaction' => true])->assertFailed();
    expect(User::count())->toBe(1);
    putenv('MAILVESPER_ADMIN_PASSWORD');
});

it('still answers to the old sendline: command names and SENDLINE_* variables', function () {
    putenv('SENDLINE_ADMIN_PASSWORD=correct horse battery staple');
    $this->artisan('sendline:make-admin', ['--email' => 'owner@example.com', '--name' => 'Ada', '--no-interaction' => true])->assertSuccessful();
    putenv('SENDLINE_ADMIN_PASSWORD');
    expect(User::sole()->email)->toBe('owner@example.com');
});

it('refuses a short admin password or a missing one', function () {
    putenv('MAILVESPER_ADMIN_PASSWORD=short');
    $this->artisan('mailvesper:make-admin', ['--email' => 'owner@example.com', '--name' => 'Ada', '--no-interaction' => true])->assertFailed();
    putenv('MAILVESPER_ADMIN_PASSWORD');
    $this->artisan('mailvesper:make-admin', ['--email' => 'owner@example.com', '--name' => 'Ada', '--no-interaction' => true])->assertFailed();
    expect(User::count())->toBe(0);
});

it('builds the web root with the pages, the API and media, and keeps other .htaccess rules', function () {
    $root = installTmp();
    file_put_contents("$root/.htaccess", "# control panel PHP settings\nphp_flag log_errors On\n");
    $this->artisan('mailvesper:webroot', ['path' => $root])->assertSuccessful();

    $repo = dirname(base_path());
    foreach (Webroot::STATIC as $name => $source) {
        expect(readlink("$root/$name"))->toBe("$repo/$source");
    }
    expect(readlink("$root/api"))->toBe(base_path('public'))
        ->and(is_file("$root/api/index.php"))->toBeTrue()
        ->and(readlink("$root/media"))->toBe(storage_path('app/public/media'))
        ->and(is_file("$root/app/index.html"))->toBeTrue();
    $ht = (string) file_get_contents("$root/.htaccess");
    expect($ht)->toContain('php_flag log_errors On')->toContain('# BEGIN MailVesper security headers')->toContain('Header always set Content-Security-Policy "default-src')->not->toContain('__CSP_HEADER__');

    // Running it again changes nothing and doesn't duplicate the headers.
    $this->artisan('mailvesper:webroot', ['path' => $root])->assertSuccessful();
    expect(substr_count((string) file_get_contents("$root/.htaccess"), '# BEGIN MailVesper security headers'))->toBe(1);
    File::deleteDirectory($root);
});

it('copies the pages with --copy, and never replaces something without --force', function () {
    $root = installTmp();
    mkdir("$root/app");
    file_put_contents("$root/app/old.txt", 'someone else’s file');
    $this->artisan('mailvesper:webroot', ['path' => $root, '--copy' => true])->assertFailed();
    expect(is_file("$root/app/old.txt"))->toBeTrue();

    $this->artisan('mailvesper:webroot', ['path' => $root, '--copy' => true, '--force' => true])->assertSuccessful();
    expect(is_link("$root/app"))->toBeFalse()
        ->and(is_file("$root/app/index.html"))->toBeTrue()
        ->and(is_file("$root/app/old.txt"))->toBeFalse()
        ->and(is_link("$root/api"))->toBeTrue(); // the API is always linked to the running app
    File::deleteDirectory($root);
});

it('refuses a web root inside the MailVesper folder', function () {
    $this->artisan('mailvesper:webroot', ['path' => dirname(base_path())])->assertFailed();
    $this->artisan('mailvesper:webroot', ['path' => base_path('public/web')])->assertFailed();
    expect(is_dir(base_path('public/web')))->toBeFalse();
});

it('reports server requirements, and only required ones block the install', function () {
    Requirements::$reach = fn () => false;
    $rows = Requirements::server();
    Requirements::$reach = null;
    $net = collect($rows)->firstWhere('name', 'Internet access');
    expect($net['required'])->toBeFalse()->and($net['ok'])->toBeFalse()->and($net['detail'])->toContain('Ask your hosting company')
        ->and(collect($rows)->firstWhere('name', 'PHP version')['ok'])->toBeTrue()
        ->and(collect($rows)->pluck('name')->implode(' '))->not->toContain('Node')->not->toContain('proc_open')
        ->and(Requirements::passes($rows))->toBeTrue(); // only plain PHP and MySQL are required
    expect(Requirements::passes([['ok' => true, 'required' => true], ['ok' => false, 'required' => false]]))->toBeTrue();
});

it('publishes every page in site/ (the installer only comes with the zip download)', function () {
    $pages = array_map('basename', glob(dirname(base_path()).'/site/*', GLOB_ONLYDIR));
    $published = array_map('basename', array_values(Webroot::STATIC));
    expect(array_values(array_diff($pages, $published)))->toBe(['install']);
});
