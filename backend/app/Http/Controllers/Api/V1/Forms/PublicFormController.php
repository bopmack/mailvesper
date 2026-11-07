<?php

namespace App\Http\Controllers\Api\V1\Forms;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Workspace;
use App\Services\Contacts\FormBuilder;
use App\Services\Contacts\Forms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The public side of signup forms (no login): what the hosted page and the embed script show, the submit
 * endpoint (JSON from the script, or a plain HTML form post from any site) and the confirmation link.
 * Any website may post to a form: the routes answer CORS from any origin, without cookies (PublicCors).
 */
class PublicFormController extends Controller
{
    public function __construct(private Forms $forms) {}

    public function show(string $key): JsonResponse
    {
        $f = $this->form($key);

        return response()->json(['form' => ['name' => $f->name, 'sender' => Workspace::whereKey($f->workspace_id)->value('name'),
            'heading' => $f->heading, 'intro' => $f->intro, 'button' => $f->button ?: 'Subscribe', 'fields' => self::publicFields($f->fields ?? []),
            'design' => FormBuilder::design($f->design), 'display' => FormBuilder::display($f->display), 'double_opt_in' => $f->double_opt_in, 'open' => $f->enabled]]);
    }

    /**
     * Fields as the page draws them: a hidden field's fixed value stays on the server.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private static function publicFields(array $fields): array
    {
        return array_map(fn ($f) => array_diff_key($f + ['type' => 'text', 'width' => 'full', 'step' => 1], ['value' => 1]), $fields);
    }

    public function submit(Request $request, string $key): JsonResponse|RedirectResponse
    {
        $html = ! $request->expectsJson() && ! $request->isJson(); // a plain <form method="post"> from the customer's site
        $f = $this->form($key);
        $done = function (array $r) use ($html, $f) {
            if ($html) {
                return redirect()->away($r['redirect'] ?? rtrim((string) config('app.frontend_url'), '/')."/f/?k={$f->public_key}&done=".($r['status'] ?? 'added'), 303);
            }

            return response()->json($r, $r['status'] === 'pending' ? 202 : 200);
        };
        if (filled($request->input('_hp'))) { // the hidden honeypot field: a bot filled it in
            return $done(['status' => $f->double_opt_in ? 'pending' : 'added', 'message' => 'Thanks!', 'redirect' => null]);
        }
        try {
            return $done($this->forms->submit($f, $request->all(), $request->ip()));
        } catch (ApiException $e) {
            if ($html) {
                return redirect()->away(rtrim((string) config('app.frontend_url'), '/')."/f/?k={$f->public_key}&error=".urlencode($e->getMessage()), 303);
            }
            throw $e;
        }
    }

    public function confirm(string $token): JsonResponse
    {
        return response()->json($this->forms->confirm($token));
    }

    private function form(string $key): Form
    {
        $f = Form::where('public_key', $key)->first() ?? throw new ApiException('not_found', 'This form doesn’t exist.', 404);
        if ($f->published_at === null) { // a draft: nothing public until it's published
            throw new ApiException('not_published', 'This form isn’t published yet.', 404);
        }

        return $f;
    }
}
