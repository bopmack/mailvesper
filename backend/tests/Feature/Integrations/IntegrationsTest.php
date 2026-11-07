<?php

use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\JourneyRun;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyEvents;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

const STRIPE_SECRET = 'whsec_testSecretValue123';
const SHOPIFY_SECRET = 'shpss_test_secret_value_0123456789';

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws, $this->people] = sesWorkspace($this, ['member']);
    sesConnect($this, $this->ws)->assertOk();
    JourneyEvents::flush();
});

function iUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/integrations".($path ? "/$path" : '');
}

/** Posts a raw JSON body with the given headers, as Stripe and Shopify do. */
function rawPost($test, string $url, string $body, array $headers)
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }

    return $test->flushHeaders()->call('POST', $url, [], [], [], $server, $body);
}

function stripeSend($test, string $url, array $event, ?int $t = null, string $secret = STRIPE_SECRET)
{
    $body = json_encode($event);
    $t ??= time();

    return rawPost($test, $url, $body, ['Stripe-Signature' => "t=$t,v1=".hash_hmac('sha256', "$t.$body", $secret)]);
}

function shopifySend($test, string $url, string $topic, array $payload, string $id)
{
    $body = json_encode($payload);

    return rawPost($test, $url, $body, ['X-Shopify-Topic' => $topic, 'X-Shopify-Webhook-Id' => $id, 'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, SHOPIFY_SECRET, true))]);
}

function eventJourney($test, array $trigger): string
{
    $id = $test->postJson("/v1/workspaces/{$test->ws->id}/journeys", ['name' => 'J'])->json('journey.id');
    $test->patchJson("/v1/workspaces/{$test->ws->id}/journeys/$id", ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => $trigger],
        ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'ran']]], 'edges' => [['from' => 't1', 'to' => 'k1']]], 'settings' => ['reentry' => 'always']])->assertOk();
    $test->postJson("/v1/workspaces/{$test->ws->id}/journeys/$id/publish")->assertOk();
    JourneyEvents::flush();

    return $id;
}

it('connects Stripe and turns signed events into contact events, once each', function () {
    $this->putJson(iUrl($this, 'stripe'), ['secret' => 'not-a-secret'])->assertStatus(422)->assertJsonPath('error.fields.secret', 'Paste the endpoint’s signing secret: it starts with whsec_.');
    $url = $this->putJson(iUrl($this, 'stripe'), ['secret' => STRIPE_SECRET])->assertOk()->assertJsonPath('integration.has_secret', true)->assertJsonMissingPath('integration.secret')->json('integration.webhook_url');
    expect(Integration::first()->getRawOriginal('secret'))->not->toContain('whsec_'); // encrypted at rest
    $path = parse_url($url, PHP_URL_PATH);
    $journey = eventJourney($this, ['trigger' => 'event', 'event' => 'stripe.checkout.session.completed']);

    $checkout = ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['object' => 'checkout.session', 'id' => 'cs_1', 'customer' => 'cus_A',
        'customer_details' => ['email' => 'Buyer@Example.com', 'name' => 'Bola Buyer'], 'amount_total' => 4900, 'currency' => 'usd', 'status' => 'complete']]];
    stripeSend($this, $path, $checkout)->assertOk()->assertJsonPath('result', 'recorded stripe.checkout.session.completed');
    stripeSend($this, $path, $checkout)->assertOk()->assertJsonPath('result', 'duplicate'); // Stripe retried
    $c = Contact::where('email', 'buyer@example.com')->firstOrFail();
    $ev = DB::table('contact_events')->where('contact_id', $c->id)->where('name', 'stripe.checkout.session.completed')->get();
    expect($c->first_name)->toBe('Bola')->and($c->last_name)->toBe('Buyer')->and($c->source)->toBe('stripe')->and($c->fields['stripe_customer_id'])->toBe('cus_A')
        ->and($ev)->toHaveCount(1)->and(json_decode($ev[0]->properties, true))->toMatchArray(['amount_total' => 49, 'currency' => 'USD', 'object_id' => 'cs_1'])
        ->and(JourneyRun::where('journey_id', $journey)->count())->toBe(1);

    // A subscription event has no email: matched through the customer id.
    stripeSend($this, $path, ['id' => 'evt_2', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['object' => 'subscription', 'id' => 'sub_1', 'customer' => 'cus_A', 'status' => 'canceled',
        'items' => ['data' => [['price' => ['id' => 'price_1', 'nickname' => 'Pro monthly']]]]]]])->assertOk()->assertJsonPath('result', 'recorded stripe.customer.subscription.deleted');
    expect(json_decode(DB::table('contact_events')->where('name', 'stripe.customer.subscription.deleted')->value('properties'), true))->toMatchArray(['plan' => 'Pro monthly', 'subscription_id' => 'sub_1']);
    stripeSend($this, $path, ['id' => 'evt_3', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['object' => 'subscription', 'customer' => 'cus_Unknown']]])
        ->assertOk()->assertJsonPath('result', 'ignored: no email, and no contact with this customer id yet');

    // Wrong secret, a replayed old signature, no signature: refused and shown on the page.
    stripeSend($this, $path, ['id' => 'evt_4', 'type' => 'invoice.paid', 'data' => ['object' => []]], null, 'whsec_wrong')->assertStatus(400);
    stripeSend($this, $path, ['id' => 'evt_5', 'type' => 'invoice.paid', 'data' => ['object' => []]], time() - 600)->assertStatus(400);
    rawPost($this, $path, '{}', [])->assertStatus(400);
    $this->getJson(iUrl($this))->assertJsonPath('integrations.0.events_received', 3)->assertJsonPath('integrations.0.last_error', 'A request with a missing or wrong Stripe signature was refused. Check the signing secret.');
    rawPost($this, '/v1/integrations/stripe/'.str_repeat('a', 40), '{}', [])->assertNotFound();
});

it('keeps integration settings to owners and admins', function () {
    switchTo($this, 'member@example.com');
    $this->putJson(iUrl($this, 'stripe'), ['secret' => STRIPE_SECRET])->assertForbidden();
    $this->getJson(iUrl($this))->assertOk()->assertJsonPath('integrations.0.connected', false);
});

it('reads Shopify orders and customers, with marketing consent', function () {
    $path = parse_url($this->putJson(iUrl($this, 'shopify'), ['secret' => SHOPIFY_SECRET, 'shop_domain' => 'acme.myshopify.com'])->assertOk()->json('integration.webhook_url'), PHP_URL_PATH);
    $order = ['id' => 820982911946154508, 'email' => 'shopper@example.com', 'name' => '#1001', 'total_price' => '59.00', 'currency' => 'NGN', 'order_status_url' => 'https://acme.myshopify.com/s/1',
        'customer' => ['id' => 115310627314723954, 'email' => 'shopper@example.com', 'first_name' => 'Ada', 'last_name' => 'Obi', 'email_marketing_consent' => ['state' => 'subscribed']],
        'line_items' => [['product_id' => 632910392, 'title' => 'Ankara dress', 'quantity' => 2]]];
    shopifySend($this, $path, 'orders/create', $order, 'wh-1')->assertOk()->assertJsonPath('result', 'recorded shopify.orders.create');
    shopifySend($this, $path, 'orders/create', $order, 'wh-1')->assertOk()->assertJsonPath('result', 'duplicate');
    $c = Contact::where('email', 'shopper@example.com')->firstOrFail();
    expect($c->first_name)->toBe('Ada')->and($c->fields)->toMatchArray(['shopify_customer_id' => '115310627314723954', 'accepts_marketing' => true])
        ->and(json_decode(DB::table('contact_events')->where('name', 'shopify.orders.create')->value('properties'), true))
        ->toMatchArray(['order_name' => '#1001', 'total_price' => '59.00', 'item_count' => 2, 'product_ids' => '632910392', 'first_item' => 'Ankara dress']);
    // A name the team set is never overwritten by the store.
    $c->forceFill(['first_name' => 'Adaeze'])->save();
    shopifySend($this, $path, 'customers/update', ['id' => 115310627314723954, 'email' => 'shopper@example.com', 'first_name' => 'Ada'], 'wh-2')->assertOk();
    expect($c->refresh()->first_name)->toBe('Adaeze');

    $body = json_encode($order);
    rawPost($this, $path, $body, ['X-Shopify-Topic' => 'orders/create', 'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'other', true))])->assertStatus(400);
});

it('sends price drops and restocks to the contacts who showed interest', function () {
    $path = parse_url($this->putJson(iUrl($this, 'shopify'), ['secret' => SHOPIFY_SECRET, 'shop_domain' => 'acme.myshopify.com'])->assertOk()->json('integration.webhook_url'), PHP_URL_PATH);
    $drops = eventJourney($this, ['trigger' => 'price_drop', 'min_percent' => 10]);
    $restocks = eventJourney($this, ['trigger' => 'back_in_stock']);
    $ev = app(JourneyEvents::class);
    $mk = fn ($e) => app(ContactService::class)->upsert($this->ws, ['email' => $e], 'api');
    $viewer = $mk('viewer@example.com');
    $asker = $mk('asker@example.com');
    $old = $mk('old@example.com');
    $other = $mk('other@example.com');
    $ev->fire($viewer, 'product_viewed', ['product_id' => '777'], 'api');            // as text…
    $ev->fire($asker, 'back_in_stock_requested', ['product_id' => 777], 'api');      // …or a number
    $ev->fire($old, 'product_viewed', ['product_id' => '777'], 'api', now()->subDays(45)); // too long ago
    $ev->fire($other, 'product_viewed', ['product_id' => '888'], 'api');
    $product = fn ($price, $qty) => ['id' => 777, 'title' => 'Linen shirt', 'handle' => 'linen-shirt', 'image' => ['src' => 'https://cdn.example.com/s.jpg'],
        'variants' => [['price' => $price, 'inventory_management' => 'shopify', 'inventory_quantity' => $qty], ['price' => '99.00', 'inventory_management' => 'shopify', 'inventory_quantity' => 0]]];

    shopifySend($this, $path, 'products/update', $product('50.00', 0), 'p-1')->assertJsonPath('result', 'product updated'); // first sight: recorded only
    shopifySend($this, $path, 'products/update', $product('47.50', 0), 'p-2')->assertJsonPath('result', 'product updated: price_drop'); // 5%: below the journey's 10%
    expect(JourneyRun::where('journey_id', $drops)->count())->toBe(0)
        ->and(DB::table('contact_events')->where('name', 'sendline.price_drop')->pluck('contact_id')->all())->toBe([$viewer->id]);
    shopifySend($this, $path, 'products/update', $product('40.00', 3), 'p-3')->assertJsonPath('result', 'product updated: price_drop, back_in_stock');
    $runs = fn ($j) => JourneyRun::where('journey_id', $j)->join('contacts', 'contacts.id', '=', 'journey_runs.contact_id')->orderBy('email')->pluck('email')->all();
    expect($runs($drops))->toBe(['viewer@example.com'])->and($runs($restocks))->toBe(['asker@example.com', 'viewer@example.com'])
        ->and(JourneyRun::where('journey_id', $drops)->first()->context['properties'])->toMatchArray(['name' => 'Linen shirt', 'price' => 40, 'old_price' => 47.5, 'drop_percent' => 15,
            'url' => 'https://acme.myshopify.com/products/linen-shirt']);

    // Any store, through the product API.
    [, $key] = ApiKey::generate($this->ws->id, 'store', 'full', null);
    $api = fn (array $b) => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $key"])->postJson('/v1/products', $b);
    $api(['product_id' => '888', 'price' => 20])->assertStatus(202)->assertJsonPath('alerts', []);
    $api(['product_id' => '888', 'price' => 10, 'name' => 'Cap'])->assertStatus(202)->assertJsonPath('alerts', ['price_drop']);
    expect($runs($drops))->toBe(['other@example.com', 'viewer@example.com']);
    $api(['product_id' => 'bad id!'])->assertStatus(422);
});

it('publishes the commerce and integration recipes', function () {
    foreach (['browse_abandoned', 'price_drop', 'back_in_stock', 'shopify_abandoned_checkout', 'stripe_payment_failed'] as $r) {
        $this->postJson("/v1/workspaces/{$this->ws->id}/journeys", ['recipe' => $r])->assertCreated();
    }
    expect(DB::table('journeys')->count())->toBe(5);
});
