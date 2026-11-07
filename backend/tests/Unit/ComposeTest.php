<?php

use App\Services\Sending\Compose;

it('parses and formats addresses safely', function () {
    expect(Compose::parseAddress('Ada Lovelace <Ada@Example.COM>'))->toBe(['Ada@example.com', 'Ada Lovelace'])
        ->and(Compose::parseAddress('"Acme, Inc" <news@acme.com>'))->toBe(['news@acme.com', 'Acme, Inc'])
        ->and(Compose::parseAddress('news@acme.com'))->toBe(['news@acme.com', null])
        ->and(Compose::parseAddress("a@b.com\r\nBcc: x@y.com"))->toBeNull()
        ->and(Compose::parseAddress('not an email'))->toBeNull();
    expect(Compose::formatAddress('a@b.com', 'Acme "News"'))->toBe('"Acme \"News\"" <a@b.com>')
        ->and(Compose::formatAddress('a@b.com', 'Amara Ọkafọ'))->toBe('=?UTF-8?B?'.base64_encode('Amara Ọkafọ').'?= <a@b.com>')
        ->and(Compose::formatAddress('a@b.com', null))->toBe('a@b.com');
});

it('merges tags with fallbacks and escapes HTML', function () {
    $vars = ['first_name' => '<b>Amara</b>', 'empty' => ''];
    expect(Compose::merge('Hi {{first_name}}, {{ empty | friend }} {{ missing|"there" }}{{nope}}!', $vars, true))
        ->toBe('Hi &lt;b&gt;Amara&lt;/b&gt;, friend there!')
        ->and(Compose::merge('Hi {{ first_name }}', $vars, false))->toBe('Hi <b>Amara</b>');
});

it('turns HTML into readable plain text', function () {
    $html = '<html><head><style>p{color:red}</style></head><body><h1>Big&nbsp;news</h1><p>Hello <b>Amara</b>,<br>see <a href="https://acme.com/x?a=1&amp;b=2">our sale</a>.</p><ul><li>One</li><li>Two</li></ul><p><a href="https://acme.com">https://acme.com</a></p></body></html>';
    expect(Compose::htmlToText($html))->toBe("Big news\n\nHello Amara,\nsee our sale (https://acme.com/x?a=1&b=2).\n\n- One\n- Two\n\nhttps://acme.com");
});

it('refuses headers MailVesper owns and header injection', function () {
    expect(Compose::headerError(['X-Campaign' => 'fall']))->toBeNull()
        ->and(Compose::headerError(['List-Unsubscribe' => 'x']))->toContain('MailVesper sets')
        ->and(Compose::headerError(['X-A' => "a\r\nBcc: evil@x.com"]))->toContain('one line')
        ->and(Compose::headerError(['Bad Name' => 'x']))->toContain('letters');
});
