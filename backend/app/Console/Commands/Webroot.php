<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Builds the folder the web server serves: the app's pages at /, the API at /api (Laravel's public/) and uploaded
 * images at /media. Links by default, so `git pull` updates the site; --copy copies the static pages instead.
 */
class Webroot extends Command
{
    protected $signature = 'mailvesper:webroot {path : The web server\'s document root, e.g. /var/www/mailvesper} {--copy : Copy the static pages instead of linking them} {--force : Replace existing entries}';

    /** The name before the rename to MailVesper; scripts and cron lines that use it keep working. */
    protected $aliases = ['sendline:webroot'];

    protected $description = 'Build the web root: the app at /, the API at /api, uploaded images at /media';

    /** Published path => path inside the repository (the folder above backend/). */
    public const STATIC = ['index.html' => 'index.html', 'app' => 'app', 'auth' => 'site/auth', 'login' => 'site/login',
        'signup' => 'site/signup', 'docs' => 'site/docs', 'r' => 'site/r', 'u' => 'site/u', 'f' => 'site/f', 'a' => 'site/a'];

    public function handle(): int
    {
        $root = rtrim((string) $this->argument('path'), '/');
        $repo = dirname(base_path());
        $real = self::resolve($root);
        if ($root === '' || $real === realpath($repo) || $real === realpath(base_path()) || str_starts_with($real.'/', realpath(base_path()).'/')) {
            $this->error('Choose a web root outside the MailVesper folder, e.g. /var/www/mailvesper.');

            return self::FAILURE;
        }
        if (! is_dir($root) && ! @mkdir($root, 0755, true)) {
            $this->error("Couldn’t create $root.");

            return self::FAILURE;
        }
        foreach (self::STATIC as $name => $source) {
            if (! file_exists("$repo/$source")) {
                $this->error("Missing $repo/$source: is this a complete MailVesper download?");

                return self::FAILURE;
            }
        }

        $media = storage_path('app/public/media');
        File::ensureDirectoryExists($media, 0755);
        $links = ['api' => base_path('public'), 'media' => $media];
        $made = [];
        foreach (self::STATIC as $name => $source) {
            if (! $this->place("$root/$name", "$repo/$source", ! $this->option('copy'))) {
                return self::FAILURE;
            }
            $made[] = $name;
        }
        foreach ($links as $name => $target) {
            if (! $this->place("$root/$name", $target, true)) { // always links: they must follow the running app
                return self::FAILURE;
            }
            $made[] = $name;
        }
        $this->writeHtaccess($root, $repo);

        $this->info("Web root ready at $root (".implode(', ', $made).').');
        $this->line('Point your web server\'s document root there. See INSTALL.md for Apache and nginx examples.');

        return self::SUCCESS;
    }

    /** The absolute path with links resolved, also for a folder that doesn't exist yet (via its nearest existing parent). */
    private static function resolve(string $path): string
    {
        $path = str_starts_with($path, '/') ? $path : getcwd().'/'.$path;
        $tail = [];
        while (! file_exists($path) && $path !== '/') {
            $tail[] = basename($path);
            $path = dirname($path);
        }
        $base = rtrim((string) realpath($path), '/');

        return $base.($tail ? '/'.implode('/', array_reverse($tail)) : '');
    }

    private function place(string $at, string $target, bool $link): bool
    {
        if (file_exists($at) || is_link($at)) {
            if (is_link($at) && readlink($at) === $target) {
                return true; // already right
            }
            if (! $this->option('force')) {
                $this->error("$at already exists. Run again with --force to replace it.");

                return false;
            }
            is_dir($at) && ! is_link($at) ? File::deleteDirectory($at) : @unlink($at);
        }
        $ok = $link ? @symlink($target, $at) : (is_dir($target) ? File::copyDirectory($target, $at) : @copy($target, $at));
        if (! $ok) {
            $this->error('Couldn’t '.($link ? 'link' : 'copy')." $at → $target.");
        }

        return $ok;
    }

    /** Apache: the security headers between our markers; anything else in the file (e.g. a control panel's PHP settings) stays. */
    private function writeHtaccess(string $root, string $repo): void
    {
        $block = str_replace('__CSP_HEADER__', 'Content-Security-Policy', trim((string) @file_get_contents("$repo/deploy-web/htaccess-security.conf")));
        if ($block === '') {
            return;
        }
        $file = "$root/.htaccess";
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $begin = '# BEGIN MailVesper security headers';
        $end = '# END MailVesper security headers';
        $pattern = '/'.preg_quote($begin, '/').'.*?'.preg_quote($end, '/').'\n?/s';
        $next = preg_match($pattern, $current) ? (string) preg_replace_callback($pattern, fn () => $block."\n", $current) : rtrim($current).($current === '' ? '' : "\n\n").$block."\n";
        file_put_contents($file, $next);
    }
}
