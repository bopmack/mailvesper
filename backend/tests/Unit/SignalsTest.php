<?php

use App\Services\Activity\Signals;

it('names the mailbox provider from the address and from the receiving server', function () {
    expect(Signals::providerFromDomain('a@gmail.com'))->toBe('google')
        ->and(Signals::providerFromDomain('a@hotmail.co.uk'))->toBe('microsoft')
        ->and(Signals::providerFromDomain('a@aol.com'))->toBe('yahoo')
        ->and(Signals::providerFromDomain('a@me.com'))->toBe('apple')
        ->and(Signals::providerFromDomain('a@acme.io'))->toBe('other');
    // Company domains on Google Workspace or Microsoft 365 are recognised from the server's reply.
    expect(Signals::providerFromDelivery('250 2.0.0 OK  1729760000 a1-20020a05600c.123 - gsmtp', 'ceo@acme.io'))->toBe('google')
        ->and(Signals::providerFromDelivery('250 2.6.0 <x@eu-west-1.amazonses.com> [InternalId=1, Hostname=AM0PR.eurprd04.prod.outlook.com] Queued mail for delivery', 'ceo@acme.io'))->toBe('microsoft')
        ->and(Signals::providerFromDelivery('250 ok', 'x@acme.io'))->toBe('other');
});

it('tells machine opens from people', function () {
    expect(Signals::machineOpen('Mozilla/5.0', 600))->toBe('apple_privacy')
        ->and(Signals::machineOpen('Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', 1))->toBeNull()
        ->and(Signals::machineOpen('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko)', 0.5))->toBe('scanner')
        ->and(Signals::machineOpen('Barracuda Sentinel', 900))->toBe('scanner')
        ->and(Signals::machineOpen('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', 900))->toBeNull();
});

it('filters scanner clicks', function () {
    $person = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    expect(Signals::botClick($person, 120, 0))->toBeFalse()
        ->and(Signals::botClick($person, 1, 0))->toBeTrue()          // seconds after delivery
        ->and(Signals::botClick($person, 120, 2))->toBeTrue()        // every link at once
        ->and(Signals::botClick('Mimecast URL protect', 120, 0))->toBeTrue()
        ->and(Signals::botClick('', 120, 0))->toBeTrue();
});

it('treats mail providers’ security scanners as machines, but not a person behind Gmail’s image proxy', function () {
    // Gmail's scanner: Google's network, a frozen Chrome 42 / Edge 12 signature, seconds after sending.
    expect(Signals::machineOpen('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.135 Safari/537.36 Edge/12.246 Mozilla/5.0', 4, '209.85.238.160'))->toBe('scanner')
        ->and(Signals::machineOpen('Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36', 3600, '66.249.84.10'))->toBe('scanner')
        ->and(Signals::machineOpen('Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', 3600, '66.249.84.10'))->toBeNull()
        ->and(Signals::machineOpen('Mozilla/5.0 (Macintosh) Chrome/130.0 Safari/537.36', 3600, '102.89.1.1'))->toBeNull()
        ->and(Signals::googleIp('8.8.8.8'))->toBeFalse()->and(Signals::googleIp('not-an-ip'))->toBeFalse();
});
