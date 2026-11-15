<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendWebhook;
use App\Models\Webhook;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    /**
     * The token owner's own webhooks. Only the host is sent back: the rest of the address is the secret.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->webhooks()->latest()->get()->map(fn (Webhook $webhook): array => [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'host' => parse_url($webhook->url, PHP_URL_HOST),
            'format' => $webhook->format,
            'events' => $webhook->events,
            'is_active' => $webhook->is_active,
            'last_status' => $webhook->last_status,
            'last_sent_at' => $webhook->last_sent_at?->toIso8601String(),
        ])]);
    }

    /**
     * Add a webhook.
     */
    public function store(Request $request): JsonResponse
    {
        $webhook = $request->user()->webhooks()->create($this->validated($request));

        return response()->json(['data' => ['id' => $webhook->id, 'name' => $webhook->name, 'host' => parse_url($webhook->url, PHP_URL_HOST)]], 201);
    }

    /**
     * Pause or resume a webhook. As on the web page, nothing else about it changes: its address
     * is a secret the panel never sends back, so it could not be edited in place anyway.
     */
    public function update(Request $request, Webhook $webhook): JsonResponse
    {
        abort_unless($webhook->user_id === $request->user()->id, 404);

        $webhook->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => ['id' => $webhook->id, 'name' => $webhook->name, 'is_active' => $webhook->is_active]]);
    }

    /**
     * Remove a webhook.
     */
    public function destroy(Request $request, Webhook $webhook): Response
    {
        abort_unless($webhook->user_id === $request->user()->id, 404);

        $webhook->delete();

        return response()->noContent();
    }

    /**
     * Send a test message to the service.
     */
    public function test(Request $request, Webhook $webhook): JsonResponse
    {
        abort_unless($webhook->user_id === $request->user()->id, 404);

        // The same message and fields as the web page's test: Webhook::payload() reads them all.
        $message = (string) __('This is a test from :name.', ['name' => config('app.name')]);

        SendWebhook::dispatchSync($webhook->id, $message, [
            'event' => 'test', 'server' => null, 'name' => null, 'hostname' => null, 'actor' => $request->user()->name,
            'message' => $message, 'at' => now()->toIso8601String(),
        ]);

        return response()->json(['data' => ['last_status' => $webhook->refresh()->last_status]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
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
    }
}
