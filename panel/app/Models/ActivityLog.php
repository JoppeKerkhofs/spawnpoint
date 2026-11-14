<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property string $actor
 * @property string $event
 * @property string|null $subject
 * @property array<string, mixed>|null $properties
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'actor', 'event', 'subject', 'properties', 'ip', 'created_at'])]
class ActivityLog extends Model
{
    public $timestamps = false;

    /**
     * Record who did what, for the Activity page.
     *
     * @param  string  $event  Such as "server.start".
     * @param  array<string, mixed>  $properties  Never secrets: this is shown to every admin.
     */
    public static function record(string $event, ?Server $server = null, array $properties = []): void
    {
        $user = request()->user();
        $token = $user?->currentAccessToken();

        self::create([
            'user_id' => $user?->id,
            'actor' => match (true) {
                $user === null => 'System',
                $token instanceof PersonalAccessToken => "{$user->name} (API token: {$token->name})",
                default => $user->name,
            },
            'event' => $event,
            'subject' => $server?->slug,
            'properties' => $properties ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
