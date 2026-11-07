<?php

namespace App\Services\Migration;

use App\Exceptions\ApiException;
use App\Jobs\RunProviderImport;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\EmailTemplate;
use App\Models\ProviderImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contacts\ContactService;
use App\Services\Design\EmailRenderer;
use App\Services\Migration\Providers\Provider;
use App\Services\Migration\Providers\ProviderError;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves a customer from another provider. Stages run in a fixed order (suppressions first, so nobody who opted
 * out is ever mailed), each in resumable pages. Contacts are written in bulk and never start journeys; an address
 * that is suppressed stays suppressed whatever the old provider says about it.
 */
class MigrationService
{
    public const PROVIDERS = ['mailchimp' => 'Mailchimp', 'sendgrid' => 'SendGrid', 'brevo' => 'Brevo', 'klaviyo' => 'Klaviyo', 'mailgun' => 'Mailgun', 'kit' => 'Kit'];

    public const REASON = ['unsubscribe' => 'unsubscribe', 'bounce' => 'hard_bounce', 'complaint' => 'complaint'];

    public function __construct(private EmailRenderer $renderer) {}

    /** @param array<string, mixed> $credentials */
    public function adapter(string $provider, array $credentials): Provider
    {
        $class = 'App\\Services\\Migration\\Providers\\'.ucfirst($provider).'Provider';
        if (! isset(self::PROVIDERS[$provider]) || ! class_exists($class)) {
            throw new ApiException('validation_failed', 'Unknown provider.', 422);
        }
        $p = app()->makeWith($class, ['credentials' => $credentials]);
        if (! $p instanceof Provider) {
            throw new ApiException('validation_failed', 'Unknown provider.', 422);
        }

        return $p;
    }

    /** @param array<string, mixed> $credentials */
    public function connect(Workspace $ws, User $user, string $provider, array $credentials): ProviderImport
    {
        $p = $this->adapter($provider, $credentials);
        try {
            $account = $p->account();
            $found = $p->discover();
        } catch (ProviderError $e) {
            throw new ApiException('provider_error', $e->getMessage(), 422);
        }

        return ProviderImport::create(['workspace_id' => $ws->id, 'provider' => $provider, 'status' => 'connected', 'credentials' => $credentials, 'account_name' => mb_substr($account['name'], 0, 200),
            'discovery' => $found, 'created_by' => $user->id]);
    }

    /** @param array<string, mixed> $options */
    public function start(ProviderImport $imp, array $options): ProviderImport
    {
        if ($imp->status !== 'connected') {
            throw new ApiException('not_ready', 'This import has already run.', 422);
        }
        $stages = $this->adapter($imp->provider, $imp->credentials)->stages();
        $wanted = array_values(array_filter($stages, fn ($s) => $s === 'suppressions' || ($options[$s] ?? true))); // suppressions can't be skipped
        $imp->forceFill(['status' => 'importing', 'options' => $options + ['stages' => $wanted], 'stage' => $wanted[0], 'cursor' => null, 'started_at' => now(),
            'progress' => array_fill_keys($wanted, ['done' => 0, 'total' => null, 'skipped' => 0]), 'state' => ['list_map' => []], 'notes' => []])->save();
        RunProviderImport::dispatch($imp->id);

        return $imp;
    }

    /** Runs pages until the time budget is spent. Returns true when there is more to do. */
    public function step(ProviderImport $imp, float $budget = 40.0): bool
    {
        $p = $this->adapter($imp->provider, $imp->credentials);
        $ws = Workspace::findOrFail($imp->workspace_id);
        $deadline = microtime(true) + $budget;
        $stages = $imp->options['stages'] ?? [];
        while (microtime(true) < $deadline) {
            $stage = (string) $imp->stage;
            $page = $p->fetch($stage, $imp->cursor, $imp->options ?? []);
            $prog = $imp->progress ?? [];
            $row = $prog[$stage] ?? ['done' => 0, 'total' => null, 'skipped' => 0];
            [$done, $skipped] = match ($stage) {
                'suppressions' => $this->suppressions($ws, $page['items'], $imp),
                'lists' => $this->lists($ws, $page['items'], $imp),
                'contacts' => $this->contacts($ws, $page['items'], $imp, $p),
                'templates' => $this->templates($ws, $page['items'], $imp, $p),
                default => [0, 0],
            };
            $row['done'] += $done;
            $row['skipped'] += $skipped;
            if (array_key_exists('total', $page) && $page['total'] !== null) {
                $row['total'] = $page['total'];
            }
            $prog[$stage] = $row;
            $imp->progress = $prog;
            $imp->cursor = $page['cursor'];
            $imp->attempts = 0;
            if ($page['cursor'] === null) {
                $next = $stages[array_search($stage, $stages, true) + 1] ?? null;
                if ($next === null) {
                    $imp->forceFill(['status' => 'done', 'stage' => null, 'finished_at' => now(), 'credentials' => []])->save(); // the key isn't kept once we're done

                    return false;
                }
                $imp->stage = $next;
            }
            $imp->save();
        }

        return true;
    }

    /**
     * Bulk suppressions: same rules as Suppression::add (a bounce or complaint upgrades an unsubscribe), contacts follow.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: int, 1: int}
     */
    private function suppressions(Workspace $ws, array $items, ProviderImport $imp): array
    {
        $rows = [];
        $skipped = 0;
        foreach ($items as $it) {
            $email = ContactService::normaliseEmail((string) ($it['email'] ?? ''));
            $reason = self::REASON[$it['reason'] ?? ''] ?? null;
            if (! $reason || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;

                continue;
            }
            // The strongest reason wins when one address appears more than once.
            $rank = ['unsubscribe' => 1, 'hard_bounce' => 2, 'complaint' => 3];
            if (! isset($rows[$email]) || $rank[$reason] > $rank[$rows[$email]['reason']]) {
                $rows[$email] = ['workspace_id' => $ws->id, 'email' => $email, 'reason' => $reason, 'detail' => mb_substr('Imported from '.self::PROVIDERS[$imp->provider].(empty($it['detail']) ? '' : ': '.$it['detail']), 0, 255), 'message_id' => null, 'created_at' => now()];
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::transaction(function () use ($ws, $chunk) {
                DB::table('suppressions')->insertOrIgnore($chunk);
                foreach (['complaint', 'hard_bounce'] as $strong) {
                    $emails = array_column(array_filter($chunk, fn ($r) => $r['reason'] === $strong), 'email');
                    if ($emails) {
                        DB::table('suppressions')->where('workspace_id', $ws->id)->whereIn('email', $emails)->whereIn('reason', $strong === 'complaint' ? ['unsubscribe', 'manual', 'hard_bounce'] : ['unsubscribe', 'manual'])->update(['reason' => $strong]);
                    }
                }
                $this->syncStatuses($ws->id, array_column($chunk, 'email'));
            });
        }

        return [count($rows), $skipped];
    }

    /** @param list<string> $emails */
    private function syncStatuses(string $wsId, array $emails): void
    {
        foreach (Contact::FROM_SUPPRESSION as $reason => $status) {
            DB::table('contacts')->where('workspace_id', $wsId)->whereIn('email', $emails)
                ->whereIn('email', DB::table('suppressions')->where('workspace_id', $wsId)->where('reason', $reason)->select('email'))->update(['status' => $status, 'updated_at' => now()]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: int, 1: int}
     */
    private function lists(Workspace $ws, array $items, ProviderImport $imp): array
    {
        $only = $imp->options['list_ids'] ?? null;
        $state = $imp->state ?? [];
        $n = 0;
        foreach ($items as $it) {
            $ext = (string) $it['ext_id'];
            if (is_array($only) && $only && ! in_array($ext, $only, true)) {
                continue;
            }
            $name = mb_substr(trim((string) $it['name']) ?: 'Imported list', 0, 100);
            $list = ContactList::firstOrCreate(['workspace_id' => $ws->id, 'name' => $name]);
            $state['list_map'][$ext] = $list->id;
            $n++;
        }
        $imp->state = $state;

        return [$n, 0];
    }

    /**
     * Bulk contacts (no journey events). Existing contacts get the new fields/tags/lists; names only fill blanks.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: int, 1: int}
     */
    private function contacts(Workspace $ws, array $items, ProviderImport $imp, Provider $p): array
    {
        $byEmail = [];
        $skipped = 0;
        $suppress = [];
        foreach ($items as $it) {
            $email = ContactService::normaliseEmail((string) ($it['email'] ?? ''));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                $skipped++;

                continue;
            }
            $status = (string) ($it['status'] ?? 'subscribed');
            // Never imported as subscribed: people who never confirmed or never agreed to marketing (pending, transactional-only…).
            if (! in_array($status, ['subscribed', 'unsubscribed', 'bounced', 'complained'], true)) {
                $skipped++;

                continue;
            }
            $byEmail[$email] = $it;
            $reason = ['unsubscribed' => 'unsubscribe', 'bounced' => 'bounce', 'complained' => 'complaint'][$status] ?? null;
            if ($reason) {
                $suppress[] = ['email' => $email, 'reason' => $reason, 'detail' => 'status on import'];
            }
        }
        if (! $byEmail) {
            return [0, $skipped];
        }
        // A contact the old provider marks as unsubscribed/bounced is suppressed here too, even if the suppression stage missed it.
        if ($suppress) {
            $this->suppressions($ws, $suppress, $imp);
        }
        $emails = array_keys($byEmail);
        $existing = Contact::where('workspace_id', $ws->id)->whereIn('email', $emails)->get()->keyBy('email');
        $suppressed = DB::table('suppressions')->where('workspace_id', $ws->id)->whereIn('email', $emails)->pluck('reason', 'email');
        $fieldKeys = [];
        $rows = [];
        $room = app(Edition::class)->room($ws, 'contacts'); // plan limits (Pro SaaS); null = none
        foreach ($byEmail as $email => $it) {
            $c = $existing[$email] ?? null;
            if (! $c && $room !== null) {
                if ($room <= 0) {
                    $skipped++;
                    $notes = (array) ($imp->notes ?? []);
                    $note = 'Some contacts weren’t imported: your plan’s contact limit was reached. Upgrade, then run the import again (nothing is duplicated).';
                    if (! in_array($note, $notes, true)) {
                        $imp->forceFill(['notes' => [...$notes, $note]])->save();
                    }
                    unset($byEmail[$email]);

                    continue;
                }
                $room--;
            }
            $fields = [];
            foreach ((array) ($it['fields'] ?? []) as $k => $v) {
                $key = self::fieldKey((string) $k);
                if ($key !== null && $v !== null && $v !== '' && is_scalar($v)) {
                    $fields[$key] = is_string($v) ? mb_substr($v, 0, 1000) : $v;
                    $fieldKeys[$key] = (string) $k;
                }
            }
            $rows[] = ['id' => $c->id ?? strtolower((string) Str::ulid()), 'workspace_id' => $ws->id, 'email' => $email,
                'first_name' => $c?->first_name ?: (mb_substr(trim((string) ($it['first_name'] ?? '')), 0, 100) ?: null),
                'last_name' => $c?->last_name ?: (mb_substr(trim((string) ($it['last_name'] ?? '')), 0, 100) ?: null),
                'fields' => ($merged = array_merge($c ? ($c->fields ?? []) : [], $fields)) ? json_encode($merged, JSON_UNESCAPED_UNICODE) : null,
                'status' => isset($suppressed[$email]) ? Contact::FROM_SUPPRESSION[$suppressed[$email]] : 'subscribed', // never re-subscribe
                'source' => $c ? $c->source : 'import', 'import_id' => $c ? $c->import_id : null, 'created_at' => $c ? ($c->created_at ?? now()) : now(), 'updated_at' => now()];
        }
        DB::transaction(function () use ($ws, $rows, $byEmail, $emails, $imp, $fieldKeys) {
            foreach (array_chunk($rows, 500) as $part) {
                DB::table('contacts')->upsert($part, ['workspace_id', 'email'], ['first_name', 'last_name', 'fields', 'status', 'updated_at']);
            }
            foreach ($fieldKeys as $key => $label) {
                DB::table('contact_fields')->insertOrIgnore(['workspace_id' => $ws->id, 'key' => $key, 'label' => mb_substr(ucfirst(str_replace('_', ' ', strtolower($label))), 0, 80), 'type' => 'text', 'created_at' => now()]);
            }
            $ids = DB::table('contacts')->where('workspace_id', $ws->id)->whereIn('email', $emails)->pluck('id', 'email');
            $map = $imp->state['list_map'] ?? [];
            $tag = trim((string) ($imp->options['tag'] ?? ''));
            $tagRows = [];
            $listRows = [];
            foreach ($byEmail as $email => $it) {
                $id = $ids[$email] ?? null;
                if (! $id) {
                    continue;
                }
                foreach (array_merge((array) ($it['tags'] ?? []), $tag !== '' ? [$tag] : []) as $t) {
                    $t = mb_strtolower(mb_substr(trim((string) $t), 0, 64));
                    if ($t !== '' && ContactService::validTag($t)) {
                        $tagRows[] = ['workspace_id' => $ws->id, 'contact_id' => $id, 'tag' => $t];
                    }
                }
                foreach ((array) ($it['lists'] ?? []) as $ext) {
                    if (isset($map[(string) $ext])) {
                        $listRows[] = ['list_id' => $map[(string) $ext], 'contact_id' => $id, 'created_at' => now()];
                    }
                }
            }
            foreach (array_chunk($tagRows, 1000) as $c) {
                DB::table('contact_tags')->insertOrIgnore($c);
            }
            foreach (array_chunk($listRows, 1000) as $c) {
                DB::table('contact_list')->insertOrIgnore($c);
            }
        });

        return [count($rows), $skipped];
    }

    /**
     * Templates arrive as complete HTML documents; they become one-block designs the renderer sends exactly as written.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: int, 1: int}
     */
    private function templates(Workspace $ws, array $items, ProviderImport $imp, Provider $p): array
    {
        $n = 0;
        $skipped = 0;
        foreach ($items as $it) {
            $html = (string) ($it['html'] ?? '');
            if (trim($html) === '' || strlen($html) > 1_000_000 || EmailTemplate::where('workspace_id', $ws->id)->count() >= 500) {
                $skipped++;

                continue;
            }
            $html = self::clean($p->convertMergeTags($html));
            if (! preg_match('/^\s*(<!doctype|<html)/i', $html)) {
                $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>'.$html.'</body></html>';
            }
            $doc = ['settings' => ['width' => 600, 'bodyBg' => '#FFFFFF', 'contentBg' => '#FFFFFF', 'font' => 'Helvetica, Arial, sans-serif', 'text' => '#3F3F46', 'heading' => '#18181B', 'link' => '#5B5BF7', 'btn' => '#5B5BF7', 'radius' => 8, 'preheader' => ''],
                'rows' => [['id' => 'r'.Str::random(6), 'layout' => [1], 'cells' => [[['id' => 'b'.Str::random(6), 'type' => 'html', 'props' => ['code' => $html, 'pad' => [0, 0, 0, 0]]]]], 'bg' => '', 'pad' => [0, 0], 'stack' => true, 'hide' => '']]];
            $subject = mb_substr($p->convertMergeTags(trim((string) ($it['subject'] ?? ''))), 0, 255);
            $r = $this->renderer->render($doc, $subject);
            EmailTemplate::create(['workspace_id' => $ws->id, 'name' => mb_substr(trim((string) ($it['name'] ?? '')) ?: 'Imported email', 0, 120), 'subject' => $subject ?: null, 'category' => self::PROVIDERS[$imp->provider],
                'doc' => $doc, 'html' => $r['html'], 'size_kb' => $r['kb'], 'checks' => $r['checks'], 'created_by' => $imp->created_by, 'updated_by' => $imp->created_by]);
            $n++;
        }

        return [$n, $skipped];
    }

    /** Custom field keys in MailVesper's format: lower snake case; null for names that can't become a key. */
    public static function fieldKey(string $name): ?string
    {
        $k = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name)), '_');
        if ($k === '' || in_array($k, ['email', 'first_name', 'last_name', 'fname', 'lname', 'firstname', 'lastname'], true)) {
            return null;
        }
        $k = preg_match('/^[a-z]/', $k) ? $k : 'f_'.$k;

        return ContactService::validKey(mb_substr($k, 0, 40)) ? mb_substr($k, 0, 40) : null;
    }

    /** Imported HTML is the customer's own, but scripts and event handlers never go into an email. */
    public static function clean(string $html): string
    {
        $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = (string) preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        return (string) preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1=$2#$2', $html);
    }
}
