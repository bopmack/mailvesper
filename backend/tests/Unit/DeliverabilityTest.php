<?php

use App\Services\Deliverability\Analyzer;
use App\Services\Deliverability\Dkim;
use App\Services\Deliverability\Spf;
use Tests\Support\FakeDns;

// The signed fixtures were made with dkimpy (an independent implementation) and a throwaway key; only its public half is kept.

function dkimDns(): FakeDns
{
    $dns = new FakeDns;
    $dns->records['TXT sel1._domainkey.example-shop.com'] = ['v=DKIM1; k=rsa; p='.trim((string) file_get_contents((__DIR__.'/../fixtures/dkim/sel1.pub')))];

    return $dns;
}

it('verifies DKIM signatures made by another implementation, in every canonicalization', function () {
    foreach (['relaxed-relaxed', 'simple-simple', 'relaxed-simple'] as $c) {
        $r = (new Dkim(dkimDns()))->verify((string) file_get_contents(__DIR__."/../fixtures/dkim/$c.eml"));
        expect($r)->toHaveCount(1)->and($r[0])->toMatchArray(['domain' => 'example-shop.com', 'selector' => 'sel1', 'result' => 'pass']);
    }
});

it('fails DKIM when the email changed after signing, or the key is wrong or missing', function () {
    $raw = (string) file_get_contents((__DIR__.'/../fixtures/dkim/relaxed-relaxed.eml'));
    expect((new Dkim(dkimDns()))->verify(str_replace('Our   sale', 'Our big sale', $raw))[0]['result'])->toBe('fail')
        ->and((new Dkim(dkimDns()))->verify(str_replace('Spring   sale', 'Winter sale', $raw))[0]['detail'])->toContain('doesn’t match the key');
    // Relaxed canonicalization ignores extra spaces in the body.
    expect((new Dkim(dkimDns()))->verify(str_replace('Our   sale', 'Our sale', $raw))[0]['result'])->toBe('pass');
    expect((new Dkim(new FakeDns))->verify($raw)[0])->toMatchArray(['result' => 'permerror'])
        ->and((new Dkim(new FakeDns))->verify("From: a@b.com\r\nSubject: x\r\n\r\nhi\r\n"))->toBe([]);
});

it('evaluates SPF like a receiving server: includes, a, mx, ranges, redirects and the lookup limit', function () {
    $dns = new FakeDns;
    $dns->records['TXT acme.com'] = ['v=spf1 ip4:203.0.113.0/24 a mx include:relay.example.net ~all'];
    $dns->records['TXT relay.example.net'] = ['v=spf1 ip4:23.83.208.0/20 ip6:2001:db8::/32 -all'];
    $dns->records['A acme.com'] = ['198.51.100.7'];
    $dns->records['MX acme.com'] = ['10 mx.acme.com'];
    $dns->records['A mx.acme.com'] = ['198.51.100.25'];
    $spf = new Spf($dns);
    expect($spf->check('203.0.113.9', 'acme.com')['result'])->toBe('pass')
        ->and($spf->check('23.83.218.248', 'acme.com')['result'])->toBe('pass')        // through include
        ->and($spf->check('2001:db8::5', 'acme.com')['result'])->toBe('pass')
        ->and($spf->check('198.51.100.7', 'acme.com')['result'])->toBe('pass')         // a
        ->and($spf->check('198.51.100.25', 'acme.com')['result'])->toBe('pass')        // mx
        ->and($spf->check('192.0.2.1', 'acme.com')['result'])->toBe('softfail')
        ->and($spf->check('192.0.2.1', 'nospf.com')['result'])->toBe('none');
    $dns->records['TXT loop.com'] = ['v=spf1 '.implode(' ', array_map(fn ($i) => "include:s$i.loop.com", range(1, 11))).' -all'];
    foreach (range(1, 11) as $i) {
        $dns->records["TXT s$i.loop.com"] = ['v=spf1 -all'];
    }
    $dns->records['TXT redir.com'] = ['v=spf1 redirect=acme.com'];
    expect($spf->check('192.0.2.1', 'loop.com')['result'])->toBe('permerror')
        ->and($spf->check('203.0.113.9', 'redir.com')['result'])->toBe('pass');
});

it('tells real internet addresses from internal ones', function () {
    expect(Analyzer::publicIp('23.83.209.25'))->toBeTrue()
        ->and(Analyzer::publicIp('100.99.192.246'))->toBeFalse()  // carrier-grade NAT inside a provider
        ->and(Analyzer::publicIp('10.1.2.3'))->toBeFalse()
        ->and(Analyzer::publicIp('127.0.0.1'))->toBeFalse();
});
