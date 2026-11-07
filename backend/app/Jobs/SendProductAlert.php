<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Commerce\ProductAlerts;
use App\Services\Journeys\JourneyEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Gives every interested contact a price-drop or back-in-stock event, a chunk per run (the next chunk is a new job,
 * so a popular product never holds a worker past its time limit). The alert id makes each contact's event once only.
 */
class SendProductAlert implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 1000;

    public int $tries = 3;

    /** @param array<string, mixed> $props */
    public function __construct(public string $workspaceId, public string $kind, public array $props, public string $alertId, public string $after = '') {}

    public function handle(JourneyEvents $events): void
    {
        $id = (string) $this->props['product_id'];
        $names = $this->kind === 'back_in_stock' ? ['product_viewed' => ProductAlerts::VIEW_DAYS, 'back_in_stock_requested' => ProductAlerts::REQUEST_DAYS] : ['product_viewed' => ProductAlerts::VIEW_DAYS];
        $ids = DB::table('contact_events')->where('workspace_id', $this->workspaceId)
            ->where(function ($q) use ($names) {
                foreach ($names as $name => $days) {
                    $q->orWhere(fn ($w) => $w->where('name', $name)->where('occurred_at', '>=', now()->subDays($days)->toDateTimeString()));
                }
            })
            // product ids arrive as text or numbers
            ->where(fn ($q) => $q->where('properties->product_id', $id)->when(ctype_digit($id) && strlen($id) < 19, fn ($w) => $w->orWhere('properties->product_id', (int) $id)))
            ->where('contact_id', '>', $this->after)->distinct()->orderBy('contact_id')->limit(self::CHUNK)->pluck('contact_id')->all();
        foreach (Contact::whereIn('id', $ids)->get() as $c) {
            $events->fire($c, 'sendline.'.$this->kind, $this->props, 'system', null, null, 'alert:'.$this->alertId.':'.$c->id);
        }
        if (count($ids) === self::CHUNK) {
            self::dispatch($this->workspaceId, $this->kind, $this->props, $this->alertId, (string) end($ids));
        }
    }
}
