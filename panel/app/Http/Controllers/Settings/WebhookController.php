<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SendWebhook;
use App\Models\Webhook;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    /**
     * Show the user's webhooks.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/Webhooks', [
            'webhooks' => $request->user()->webhooks()->latest()->get()->map(fn (Webhook $webhook): array => [
                'id' => $webhook->id,
                'name' => $webhook->name,
                // Only the host: the rest of the address is the secret.
                'host' => parse_url($webhook->url, PHP_URL_HOST),
                'format' => $webhook->format,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
                'last_status' => $webhook->last_status,
                'last_sent' => $webhook->last_sent_at?->diffForHumans(),
            ]),
            'formats' => Webhook::FORMATS,
            'groups' => array_keys(Webhook::GROUPS),
        ]);
    }

    /**
     * Add a webhook.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'string', 'max:2000', 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Webhook::isSafeUrl((string) $value)) {
                    $fail(__('Use an https address on the public internet.'));
                }
            }],
            'format' => ['required', Rule::in(Webhook::FORMATS)],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhook::GROUPS))],
        ]);

        $request->user()->webhooks()->create($data);

        return back();
    }

    /**
     * Pause or resume a webhook.
     */
    public function update(Request $request, int $webhook): RedirectResponse
    {
        $request->user()->webhooks()->findOrFail($webhook)->update($request->validate(['is_active' => ['required', 'boolean']]));

        return back();
    }

    /**
     * Send a test message now and report how the service answered.
     */
    public function test(Request $request, int $webhook): RedirectResponse
    {
        $webhook = $request->user()->webhooks()->findOrFail($webhook);
        $message = __('This is a test from :name.', ['name' => config('app.name')]);

        SendWebhook::dispatchSync($webhook->id, $message, [
            'event' => 'test', 'server' => null, 'name' => null, 'hostname' => null, 'actor' => $request->user()->name,
            'message' => $message, 'at' => now()->toIso8601String(),
        ]);

        $status = $webhook->refresh()->last_status;
        Inertia::flash('toast', $status >= 200 && $status < 300
            ? ['type' => 'success', 'message' => __('The service accepted the test message.')]
            : ['type' => 'error', 'message' => $status ? __('The service answered :status.', ['status' => $status]) : __('The service did not answer, or the webhook is paused.')]);

        return back();
    }

    /**
     * Remove a webhook.
     */
    public function destroy(Request $request, int $webhook): RedirectResponse
    {
        $request->user()->webhooks()->whereKey($webhook)->delete();

        return back();
    }
}
