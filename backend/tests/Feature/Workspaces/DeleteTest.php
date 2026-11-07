<?php

use App\Exceptions\ApiException;
use App\Jobs\PurgeWorkspace;
use App\Jobs\SendMessage;
use App\Mail\CodeMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

afterEach(function () {
    PurgeWorkspace::$budgetSeconds = 40;
    PurgeWorkspace::$chunk = 2000;
});

/** A workspace full of data: contacts on a list with tags and events, messages with events, a campaign, a journey run, keys, webhooks. */
function fillWorkspace(Workspace $ws, int $contacts = 3): void
{
    $now = now();
    $uid = fn () => strtolower((string) Str::ulid());
    $list = $uid();
    DB::table('lists')->insert(['id' => $list, 'workspace_id' => $ws->id, 'name' => 'Main', 'created_at' => $now, 'updated_at' => $now]);
    for ($i = 0; $i < $contacts; $i++) {
        $cid = strtolower((string) Str::ulid());
        DB::table('contacts')->insert(['id' => $cid, 'workspace_id' => $ws->id, 'email' => "c$i-{$ws->slug}@example.com", 'status' => 'subscribed', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('contact_list')->insert(['contact_id' => $cid, 'list_id' => $list, 'created_at' => $now]);
        DB::table('contact_tags')->insert(['workspace_id' => $ws->id, 'contact_id' => $cid, 'tag' => 'vip']);
        DB::table('contact_events')->insert(['workspace_id' => $ws->id, 'contact_id' => $cid, 'name' => 'order.placed', 'source' => 'api', 'occurred_at' => $now]);
        $mid = strtolower((string) Str::ulid());
        DB::table('messages')->insert(['id' => $mid, 'workspace_id' => $ws->id, 'tracking_code' => 'SL-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
            'source' => 'api', 'type' => 'marketing', 'from_email' => 'a@acme.com', 'to_email' => "c$i@example.com", 'subject' => 's', 'html' => '<p>x</p>', 'text' => 'x', 'status' => 'delivered', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('message_events')->insert(['workspace_id' => $ws->id, 'message_id' => $mid, 'type' => 'delivered', 'machine' => false, 'occurred_at' => $now]);
        DB::table('ses_events')->insert(['workspace_id' => $ws->id, 'event_type' => 'Delivery', 'message_id' => $mid, 'sns_message_id' => (string) Str::uuid(), 'recipients' => '[]', 'occurred_at' => $now, 'payload' => '{}', 'created_at' => $now]);
    }
    DB::table('api_keys')->insert(['id' => $uid(), 'workspace_id' => $ws->id, 'name' => 'k', 'prefix' => 'sl_live_'.Str::random(6), 'key_hash' => Str::random(64), 'created_at' => $now, 'updated_at' => $now]);
    $cmp = $uid();
    DB::table('campaigns')->insert(['id' => $cmp, 'workspace_id' => $ws->id, 'name' => 'Sale', 'status' => 'sending', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('campaign_recipients')->insert(['campaign_id' => $cmp, 'email' => 'c0@example.com', 'status' => 'pending']);
    $j = $uid();
    DB::table('journeys')->insert(['id' => $j, 'workspace_id' => $ws->id, 'name' => 'Welcome', 'status' => 'active', 'graph' => '{}', 'hook_token' => Str::random(32), 'secret' => Str::random(32), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('journey_steps')->insert(['journey_id' => $j, 'run_id' => 1, 'node_id' => 'n1', 'outcome' => 'ok', 'created_at' => $now]);
    $ep = $uid();
    DB::table('webhook_endpoints')->insert(['id' => $ep, 'workspace_id' => $ws->id, 'url' => 'https://hooks.example.com/x', 'events' => '[]', 'secret' => 'whsec_x', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('webhook_deliveries')->insert(['id' => $uid(), 'endpoint_id' => $ep, 'workspace_id' => $ws->id, 'event_type' => 'email.delivered', 'payload' => '{}', 'status' => 'delivered', 'attempts' => 1, 'created_at' => $now, 'updated_at' => $now]);
}

it('deletes a workspace for everyone and purges its data, leaving other workspaces alone', function () {
    Storage::fake('public');
    [$ws, $people] = sesWorkspace($this, ['admin']);
    $keep = Workspace::create(['name' => 'Keep Co', 'slug' => 'keep-co', 'owner_id' => $people['owner']->id]);
    $keep->members()->attach($people['owner']->id, ['role' => 'owner']);
    $people['admin']->forceFill(['current_workspace_id' => $ws->id])->save();
    fillWorkspace($ws);
    fillWorkspace($keep, 2);
    Storage::disk('public')->put("media/{$ws->id}/logo.png", 'png');
    $keepCounts = fn () => [DB::table('contacts')->where('workspace_id', $keep->id)->count(), DB::table('messages')->where('workspace_id', $keep->id)->count(), DB::table('message_events')->where('workspace_id', $keep->id)->count()];
    $before = $keepCounts();

    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => ' acme ', 'password' => STRONG_PASSWORD])->assertOk()
        ->assertJsonPath('deleted', true)->assertJsonPath('current_workspace_id', $keep->id);

    expect(Workspace::find($ws->id))->toBeNull() // the purge ran (sync queue in tests)
        ->and(DB::table('workspace_user')->where('workspace_id', $ws->id)->count())->toBe(0);
    foreach (['contacts', 'messages', 'message_events', 'ses_events', 'contact_tags', 'contact_events', 'api_keys', 'campaigns', 'journeys', 'webhook_endpoints', 'webhook_deliveries', 'lists'] as $t) {
        expect(DB::table($t)->where('workspace_id', $ws->id)->count())->toBe(0, "$t still has rows");
    }
    expect(DB::table('campaign_recipients')->count())->toBe(1) // the other workspace's
        ->and(DB::table('journey_steps')->count())->toBe(1)
        ->and(DB::table('contact_list')->count())->toBe(2)
        ->and($keepCounts())->toBe($before)
        ->and(Storage::disk('public')->exists("media/{$ws->id}/logo.png"))->toBeFalse()
        ->and($people['admin']->fresh()->current_workspace_id)->toBeNull()
        ->and(AuditLog::where('action', 'workspace.deleted')->where('workspace_id', $ws->id)->exists())->toBeTrue(); // the audit trail stays
    $this->getJson("/v1/workspaces/{$ws->id}/contacts")->assertNotFound();
});

it('cuts access at once and hands a long purge over to a fresh job', function () {
    Queue::fake();
    [$ws] = sesWorkspace($this);
    fillWorkspace($ws, 5);
    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => 'Acme', 'password' => STRONG_PASSWORD])->assertOk();

    $w = Workspace::find($ws->id);
    expect($w->deleting_at)->not->toBeNull()
        ->and(DB::table('api_keys')->where('workspace_id', $ws->id)->count())->toBe(0)
        ->and(DB::table('campaigns')->where('workspace_id', $ws->id)->value('status'))->toBe('cancelled')
        ->and(DB::table('journeys')->where('workspace_id', $ws->id)->value('status'))->toBe('paused')
        ->and((bool) DB::table('webhook_endpoints')->where('workspace_id', $ws->id)->value('enabled'))->toBeFalse();
    Queue::assertPushed(PurgeWorkspace::class, 1);
    $this->getJson("/v1/workspaces/{$ws->id}/contacts")->assertNotFound();

    PurgeWorkspace::$chunk = 2;
    PurgeWorkspace::$budgetSeconds = 0; // out of time after every chunk
    (new PurgeWorkspace($ws->id))->handle();
    Queue::assertPushed(PurgeWorkspace::class, 2);
    expect(Workspace::find($ws->id))->not->toBeNull();
    for ($i = 0; $i < 100 && Workspace::find($ws->id); $i++) {
        (new PurgeWorkspace($ws->id))->handle();
    }
    expect(Workspace::find($ws->id))->toBeNull()->and(DB::table('contacts')->count())->toBe(0);
});

it('drops a queued email instead of sending it once its workspace is deleted', function () {
    [$ws] = sesWorkspace($this);
    $id = strtolower((string) Str::ulid());
    DB::table('messages')->insert(['id' => $id, 'workspace_id' => $ws->id, 'tracking_code' => 'SL-AAAA-BBBB', 'source' => 'api', 'type' => 'transactional',
        'from_email' => 'a@acme.com', 'to_email' => 'r@example.com', 'subject' => 's', 'html' => '<p>x</p>', 'text' => 'x', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $ws->forceFill(['deleting_at' => now()])->save();
    app()->call([new SendMessage($id), 'handle']);
    expect(DB::table('messages')->where('id', $id)->exists())->toBeFalse();
});

it('only lets the owner delete, with the exact name and their password', function () {
    [$ws] = sesWorkspace($this, ['admin']);
    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => 'Acme Inc', 'password' => STRONG_PASSWORD])->assertStatus(422)->assertJsonPath('error.code', 'name_mismatch');
    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => 'Acme', 'password' => 'not the password'])->assertStatus(422)->assertJsonPath('error.code', 'wrong_password');
    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => 'Acme'])->assertStatus(422);
    switchTo($this, 'admin@example.com');
    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => 'Acme', 'password' => STRONG_PASSWORD])->assertForbidden();
    expect(Workspace::find($ws->id)->deleting_at)->toBeNull();
});

it('confirms with an emailed code for accounts without a password', function () {
    Mail::fake();
    $u = User::create(['name' => 'Sam Social', 'email' => 'sam@example.com']);
    $u->markEmailAsVerified();
    $ws = Workspace::create(['name' => 'Social Co', 'slug' => 'social-co', 'owner_id' => $u->id]);
    $ws->members()->attach($u->id, ['role' => 'owner']);
    $this->actingAs($u);

    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => 'Social Co', 'code' => '000000'])->assertStatus(422);
    $this->postJson("/v1/workspaces/{$ws->id}/delete-code")->assertOk()->assertJsonPath('code_delivered', true);
    $code = null;
    Mail::assertSent(CodeMail::class, function (CodeMail $m) use (&$code) {
        $code = $m->code;

        return $m->envelope()->subject === "$code is your MailVesper confirmation code";
    });
    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => 'Social Co', 'code' => $code])->assertOk();
    expect(Workspace::find($ws->id))->toBeNull();
});

it('lets an edition refuse the deletion (Pro: a plan still billing) and changes nothing', function () {
    [$ws] = sesWorkspace($this);
    fillWorkspace($ws, 1);
    app(Edition::class)->onWorkspaceDeleting(fn () => throw new ApiException('plan_active', 'Cancel the plan first.', 409));
    $this->deleteJson("/v1/workspaces/{$ws->id}", ['confirm_name' => 'Acme', 'password' => STRONG_PASSWORD])->assertStatus(409)->assertJsonPath('error.code', 'plan_active');
    expect(Workspace::find($ws->id)->deleting_at)->toBeNull()
        ->and(DB::table('workspace_user')->where('workspace_id', $ws->id)->count())->toBe(1)
        ->and(DB::table('api_keys')->where('workspace_id', $ws->id)->count())->toBe(1);
});
