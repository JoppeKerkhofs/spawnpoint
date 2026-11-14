<?php

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(public int $webhookId, public string $message, public array $details) {}

    /**
     * Send the message and note how the receiving service answered.
     */
    public function handle(): void
    {
        $webhook = Webhook::query()->find($this->webhookId);

        // Checked again at send time: the name may resolve somewhere else than when it was saved.
        if ($webhook === null || ! $webhook->is_active || ! Webhook::isSafeUrl($webhook->url)) {
            return;
        }

        try {
            $status = Http::connectTimeout(3)->timeout(10)->withoutRedirecting()
                ->post($webhook->url, $webhook->payload($this->message, $this->details))
                ->status();
        } catch (ConnectionException) {
            $status = 0;
        }

        $webhook->update(['last_status' => $status, 'last_sent_at' => now()]);

        // A server error or no answer is worth another try; a refusal is not.
        if ($status === 0 || $status >= 500) {
            $this->release($this->backoff);
        }
    }
}
