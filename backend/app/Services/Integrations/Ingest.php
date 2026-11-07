<?php

namespace App\Services\Integrations;

use App\Exceptions\ApiException;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\Workspace;
use App\Services\Commerce\ProductAlerts;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyEvents;
use App\Services\Platform\Brand;
use Illuminate\Support\Facades\DB;

/** Turns a verified Stripe or Shopify webhook into a contact event (or a product update). */
class Ingest
{
    public function __construct(private ContactService $contacts, private JourneyEvents $events, private ProductAlerts $products) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return string what happened, for the provider's delivery log
     */
    public function handle(Integration $i, string $eventId, array $payload, ?string $topic = null): string
    {
        $ws = Workspace::find($i->workspace_id);
        if (! $ws || $ws->deleting_at) {
            return 'ignored: workspace removed';
        }
        // Providers retry: each event is handled once.
        if (! DB::table('integration_events')->insertOrIgnore(['integration_id' => $i->id, 'event_id' => mb_substr($eventId, 0, 120), 'created_at' => now()])) {
            return 'duplicate';
        }
        $i->forceFill(['events_received' => $i->events_received + 1, 'last_event_at' => now()])->save();

        if ($i->provider === 'shopify' && in_array($topic, ['products/create', 'products/update'], true)) {
            $p = Shopify::product($payload, $i->shop_domain);
            if (! $p) {
                return 'ignored: no product id';
            }
            $alerts = $this->products->update($ws->id, $p['product_id'], $p['price'], $p['in_stock'], $p);

            return $alerts ? 'product updated: '.implode(', ', $alerts) : 'product updated';
        }

        $e = $i->provider === 'stripe' ? Stripe::parse($payload) : Shopify::parse((string) $topic, $payload);
        if (! $e) {
            return 'ignored: not an event '.Brand::name().' reads';
        }
        $fields = $e['fields'] ?? [];
        if (! empty($e['customer'])) {
            $fields['stripe_customer_id'] = $e['customer'];
        }
        $contact = null;
        if ($e['email'] && filter_var(ContactService::normaliseEmail($e['email']), FILTER_VALIDATE_EMAIL)) {
            $data = ['email' => $e['email']] + ($fields ? ['fields' => $fields] : []);
            $existing = Contact::where('workspace_id', $ws->id)->where('email', ContactService::normaliseEmail($e['email']))->first();
            foreach (['first_name', 'last_name'] as $k) {
                if (! empty($e[$k]) && (! $existing || ! $existing->{$k})) { // fill in names, never overwrite ones you set
                    $data[$k] = mb_substr($e[$k], 0, 100);
                }
            }
            try {
                $contact = $this->contacts->upsert($ws, $data, $i->provider);
            } catch (ApiException $x) {
                return 'ignored: '.$x->getMessage();
            }
        } elseif (! empty($e['customer'])) {
            $contact = Contact::where('workspace_id', $ws->id)->where('fields->stripe_customer_id', $e['customer'])->first();
        }
        if (! $contact) {
            return 'ignored: no email, and no contact with this customer id yet';
        }
        $this->events->fire($contact, $e['name'], $e['properties'], $i->provider);

        return 'recorded '.$e['name'];
    }
}
