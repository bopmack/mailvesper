<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> passwords the fake breach API should report as compromised */
    public static array $breached = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Requests come from the MailVesper front end (stateful Sanctum session).
        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/login/', 'Accept' => 'application/json']);

        // No test may reach the internet. Breached-password lookups answer "not found" unless a test says otherwise.
        self::$breached = [];
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => function (Request $request) {
            $prefix = strtoupper(substr($request->url(), -5));
            $lines = [];
            foreach (self::$breached as $password) {
                $hash = strtoupper(sha1($password));
                if (str_starts_with($hash, $prefix)) {
                    $lines[] = substr($hash, 5).':52341';
                }
            }

            return Http::response(implode("\r\n", $lines), 200);
        }]);
    }
}
