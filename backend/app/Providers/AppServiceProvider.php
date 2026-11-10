<?php

namespace App\Providers;

use App\Services\Ops\SlowQueries;
use App\Services\Platform\Runtime;
use App\Services\Ses\AwsFactory;
use App\Support\Edition;
use Aws\Sns\MessageValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Edition::class);

        // MailVesper Pro (private package): its bootstrap file registers an autoloader and returns its provider class.
        // The provider checks the license itself; without a valid one the app stays Community.
        $pro = rtrim((string) config('sendline.pro_path'), '/').'/bootstrap.php';
        if (is_file($pro)) {
            $provider = require $pro;
            if (is_string($provider) && class_exists($provider)) {
                $this->app->register($provider);
            }
        }

        // One factory per request/test, so tests can install a fake AWS handler.
        $this->app->singleton(AwsFactory::class);

        // SNS signature checks: Amazon's signing certificate, cached for a day (it rarely changes).
        $this->app->singleton(MessageValidator::class, fn () => new MessageValidator(
            fn (string $url) => Cache::remember('sns-cert:'.sha1($url), 86400, fn () => Http::timeout(10)->get($url)->throw()->body())
        ));
    }

    public function boot(): void
    {
        if ($proxies = config('sendline.trusted_proxies')) {
            TrustProxies::at($proxies);
        }
        DB::listen(fn (QueryExecuted $q) => SlowQueries::record($q)); // Platform → System
        Runtime::apply(); // the admin panel's brand, system email and sign-in keys
        // Microsoft and Apple sign-in come from SocialiteProviders.
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);
        Event::listen(SocialiteWasCalled::class, [AppleExtendSocialite::class, 'handle']);

        $email = fn (Request $r) => mb_strtolower(trim((string) $r->input('email')));

        // General auth endpoints: per IP.
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(30)->by('ip:'.$r->ip()));

        // Endpoints that send email or create accounts: per IP and per address.
        RateLimiter::for('auth-strict', fn (Request $r) => [
            Limit::perHour(30)->by('ip:'.$r->ip()),
            Limit::perHour(10)->by('email:'.$email($r)),
        ]);

        // Calls that reach the customer's AWS account: per workspace.
        RateLimiter::for('aws', function (Request $r) {
            $ws = $r->route('workspace'); // throttling runs before route-model binding, so this may still be the raw id

            return Limit::perMinute(20)->by('ws:'.(is_object($ws) ? $ws->getKey() : (string) $ws));
        });

        // SMTP connection tests and verification codes reach the customer's mail server: per workspace.
        RateLimiter::for('smtp', function (Request $r) {
            $ws = $r->route('workspace');

            return Limit::perMinute(20)->by('smtp:'.(is_object($ws) ? $ws->getKey() : (string) $ws));
        });

        // Public API: per key.
        RateLimiter::for('api-key', fn (Request $r) => Limit::perMinute(600)->by('key:'.sha1((string) $r->bearerToken())));

        // Unsubscribe links: mailbox providers call one-click from shared IPs, so keep this generous.
        // Signup forms: per sender IP (a person, or a site's own server posting for its visitors) and per form.
        RateLimiter::for('forms', fn (Request $r) => [Limit::perMinute(30)->by('ip:'.$r->ip()), Limit::perMinute(600)->by('form:'.(string) $r->route('key'))]);
        RateLimiter::for('unsubscribe', fn (Request $r) => Limit::perMinute(600)->by('ip:'.$r->ip()));

        // Journey webhook triggers: per journey URL and per sender IP.
        // Stripe / Shopify webhooks: per connection only (their senders share IPs across every store).
        RateLimiter::for('integrations', fn (Request $r) => Limit::perMinute(1200)->by('int:'.sha1((string) $r->route('token'))));
        RateLimiter::for('hooks', fn (Request $r) => [Limit::perMinute(600)->by('hook:'.sha1((string) $r->route('token'))), Limit::perMinute(300)->by('ip:'.$r->ip())]);

        // SES events from SNS: generous, per connection token.
        RateLimiter::for('relay', fn (Request $r) => Limit::perMinute(3000)->by('relay:'.sha1((string) $r->route('token'))));
        RateLimiter::for('sns', fn (Request $r) => Limit::perMinute(60000)->by('sns:'.sha1((string) $r->route('token')))); // storing a notice is cheap; a big send makes thousands a minute
    }
}
