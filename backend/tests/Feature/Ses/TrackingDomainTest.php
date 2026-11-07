<?php

use App\Models\Message;
use App\Models\SesConnection;
use App\Models\TrackingDomain;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use App\Services\Smtp\Tracking;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->dns = new FakeDns;
    $this->app->instance(DnsLookup::class, $this->dns);
    [$this->ws] = sesWorkspace($this, ['member']);
    sesConnect($this, $this->ws)->assertOk();
    SesConnection::query()->update(['sns_topic_arn' => 'arn:aws:sns:eu-west-1:123456789012:sendline-events']);
    UrlGuard::$resolver = fn () => ['203.0.113.10']; // links.acme.com resolves publicly
    $this->serving = false; // whether the web server answers for links.acme.com yet
    Http::fake(fn () => $this->serving ? Http::response(Tracking::pingToken()) : Http::response('Not found', 404));
    Tracking::flush();
});

afterEach(function () {
    UrlGuard::$resolver = null;
    Tracking::flush();
});

function tdUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/tracking-domain".($path ? "/$path" : '');
}

it('switches on only once the domain points here and answers over HTTPS, and back off if it stops', function () {
    $target = parse_url((string) config('app.url'), PHP_URL_HOST);
    $this->putJson(tdUrl($this), ['host' => 'acme.com'])->assertStatus(422); // needs a subdomain
    $this->putJson(tdUrl($this), ['host' => $target])->assertStatus(422);
    $this->putJson(tdUrl($this), ['host' => 'https://Links.Acme.com/'])->assertOk()->assertJsonPath('tracking_domain.host', 'links.acme.com')
        ->assertJsonPath('tracking_domain.status', 'pending')->assertJsonPath('tracking_domain.last_error', "Add a CNAME record: links.acme.com → $target.");

    $this->dns->records['CNAME links.acme.com'] = [$target];
    $this->postJson(tdUrl($this, 'check'))->assertOk()->assertJsonPath('tracking_domain.status', 'pending')
        ->assertJsonPath('tracking_domain.last_error', "links.acme.com points here, but it doesn’t answer with MailVesper yet. The web server must serve links.acme.com like $target, with a certificate for it.");

    $this->serving = true;
    $this->artisan('tracking:check')->assertSuccessful();
    expect(TrackingDomain::first())->status->toBe('active')->ses_ready->toBeTrue()
        ->and(collect($this->aws->argsFor('CreateConfigurationSet'))->pluck('ConfigurationSetName'))->toContain(SesConnection::CONFIG_SET_OWN_TRACKING)
        ->and(collect($this->aws->argsFor('CreateConfigurationSetEventDestination'))->last()['EventDestination']['MatchingEventTypes'])->not->toContain('OPEN', 'CLICK');

    // The web server stops answering for it: links go back to the shared address.
    $this->serving = false;
    $this->artisan('tracking:check')->assertSuccessful();
    expect(TrackingDomain::first()->status)->toBe('pending')->and(Tracking::host($this->ws->id))->toBeNull();

    switchTo($this, 'member@example.com');
    $this->putJson(tdUrl($this), ['host' => 'links.acme.com'])->assertForbidden();
});

it('tracks SES emails on the workspace’s domain instead of SES, and records opens and clicks', function () {
    $this->dns->records['CNAME links.acme.com'] = [parse_url((string) config('app.url'), PHP_URL_HOST)];
    $this->serving = true;
    $this->putJson(tdUrl($this), ['host' => 'links.acme.com'])->assertOk()->assertJsonPath('tracking_domain.status', 'active');

    $mailer = app(Mailer::class);
    $m = $mailer->submit($this->ws, [$mailer->validate($this->ws, ['from' => 'news@acme.com', 'to' => 'reader@example.com', 'subject' => 'Hi',
        'html' => '<html><body><p><a href="https://acme.com/sale">Shop</a></p></body></html>', 'type' => 'marketing'])], 'api')[0];
    $req = collect($this->aws->argsFor('SendEmail'))->last();
    $html = $req['Content']['Simple']['Body']['Html']['Data'];
    expect($req['ConfigurationSetName'])->toBe(SesConnection::CONFIG_SET_OWN_TRACKING)
        ->and($html)->toContain('https://links.acme.com/')->toContain('/v1/t/o/'.$m->id)->not->toContain('href="https://acme.com/sale"');

    preg_match('#href="([^"]+/v1/t/c/[^"]+)"#', $html, $link);
    $path = parse_url(html_entity_decode($link[1]), PHP_URL_PATH).'?'.parse_url(html_entity_decode($link[1]), PHP_URL_QUERY);
    $this->travel(3)->minutes(); // a person, not a link scanner seconds after sending
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'])
        ->get($path)->assertRedirect('https://acme.com/sale');
    expect(DB::table('message_events')->where('message_id', $m->id)->where('type', 'clicked')->count())->toBe(1)
        ->and(Message::find($m->id)->clicked_at)->not->toBeNull();

    // Without an active domain, SES does the tracking as before.
    TrackingDomain::query()->update(['status' => 'pending']);
    Tracking::flush();
    $mailer->submit($this->ws, [$mailer->validate($this->ws, ['from' => 'news@acme.com', 'to' => 'b@example.com', 'subject' => 'Hi', 'html' => '<p><a href="https://acme.com">x</a></p>'])], 'api');
    expect(collect($this->aws->argsFor('SendEmail'))->last()['ConfigurationSetName'])->toBe(SesConnection::CONFIG_SET);
    $this->get('/v1/t/ping')->assertOk()->assertSee(Tracking::pingToken());
});
