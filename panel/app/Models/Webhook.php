<?php

namespace App\Models;

use App\Jobs\SendWebhook;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $url
 * @property string $format
 * @property list<string> $events
 * @property bool $is_active
 * @property int|null $last_status
 * @property Carbon|null $last_sent_at
 * @property-read User $user
 */
#[Fillable(['name', 'url', 'format', 'events', 'is_active', 'last_status', 'last_sent_at'])]
#[Hidden(['url'])]
class Webhook extends Model
{
    // How the message is wrapped: Discord reads "content", Slack-style services read "text",
    // and "json" sends every field for a service of your own, such as an SMS gateway.
    public const array FORMATS = ['discord', 'slack', 'json'];

    // What a webhook can be told about, and the events each choice covers.
    public const array GROUPS = [
        'power' => ['server.start', 'server.stop', 'server.restart', 'server.kill'],
        'status' => ['server.online', 'server.offline'],
        'crash' => ['server.crashed'],
        'backups' => ['backup.create', 'backup.restore', 'schedule.run'],
        'changes' => ['server.create', 'server.update', 'server.delete', 'world.import'],
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Queue a message to every active webhook that asked for this event and whose owner may see the server.
     */
    public static function notify(string $event, Server $server, string $actor, ?string $detail = null): void
    {
        $group = collect(self::GROUPS)->search(fn (array $events): bool => in_array($event, $events, true));

        if ($group === false) {
            return;
        }

        $message = self::message($event, $server->name, $actor, $detail);

        self::query()->where('is_active', true)->whereJsonContains('events', $group)->with('user')->get()
            ->filter(fn (self $webhook): bool => $webhook->user->can('view', $server))
            ->each(fn (self $webhook) => SendWebhook::dispatch($webhook->id, $message, [
                'event' => $event,
                'server' => $server->slug,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'actor' => $actor,
                'message' => $message,
                'at' => now()->toIso8601String(),
            ]));
    }

    /**
     * Whether an address is safe to send to: https, and not a machine on this network. Without
     * this a webhook could be pointed at the panel's own services.
     */
    public static function isSafeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host)) {
            return false;
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        return $addresses !== [] && collect($addresses)->every(
            fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false,
        );
    }

    /**
     * The body to send for this webhook's format.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function payload(string $message, array $details): array
    {
        return match ($this->format) {
            // An embed: the server as the title, a coloured bar for the kind of news, and the details as fields.
            'discord' => ['embeds' => [array_filter([
                'title' => $details['name'] ?? config('app.name'),
                'description' => $message,
                'color' => match ($details['event']) {
                    'server.crashed', 'server.kill', 'server.delete' => 0xD64545,
                    'server.stop', 'server.offline', 'server.restart' => 0xE0A526,
                    'server.online', 'server.start', 'server.create' => 0x3FA36B,
                    default => 0x4F8FD6,
                },
                'fields' => isset($details['hostname']) ? [
                    ['name' => 'Address', 'value' => $details['hostname'], 'inline' => true],
                    ['name' => 'By', 'value' => $details['actor'], 'inline' => true],
                ] : null,
                'footer' => ['text' => config('app.name')],
                'timestamp' => $details['at'],
            ])]],
            'slack' => ['text' => $message],
            default => $details,
        };
    }

    private static function message(string $event, string $server, string $actor, ?string $detail): string
    {
        return match ($event) {
            'server.start' => "{$actor} started {$server}.",
            'server.stop' => "{$actor} stopped {$server}.",
            'server.restart' => "{$actor} restarted {$server}.",
            'server.kill' => "{$actor} killed {$server}.",
            'server.online' => "{$server} is online.",
            'server.offline' => "{$server} went offline.",
            'server.crashed' => "{$server} crashed.".($detail ? " {$detail}" : ''),
            'backup.create' => "{$actor} started a backup of {$server}.",
            'backup.restore' => "{$actor} restored a backup of {$server}.",
            'schedule.run' => "A schedule ran on {$server}".($detail ? ": {$detail}." : '.'),
            'server.create' => "{$actor} created {$server}.",
            'server.update' => "{$actor} changed the configuration of {$server}.",
            'server.delete' => "{$actor} deleted {$server}.",
            'world.import' => "{$actor} imported a world into {$server}.",
            default => "{$event} on {$server}.",
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }
}
