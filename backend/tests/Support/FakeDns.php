<?php

namespace Tests\Support;

use App\Services\Ses\DnsLookup;

class FakeDns extends DnsLookup
{
    /** @var array<string, list<string>> "TYPE host" => answers */
    public array $records = [];

    public function lookup(string $type, string $host): array
    {
        return $this->records["$type $host"] ?? [];
    }

    /** Publishes every record a domain needs, as the customer would at their DNS host. */
    public function publish(array $records): void
    {
        foreach ($records as $r) {
            $this->records[$r['type'].' '.$r['host']][] = $r['value'];
        }
    }
}
