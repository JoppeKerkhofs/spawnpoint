<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property string $action
 * @property string $time
 * @property int|null $weekday
 * @property string|null $command
 * @property bool $is_active
 * @property-read Server $server
 */
#[Fillable(['server_id', 'action', 'time', 'weekday', 'command', 'is_active'])]
class Schedule extends Model
{
    public const array ACTIONS = ['backup', 'restart', 'start', 'stop', 'command'];

    /**
     * The share permission an action needs. Schedules sit under "backups", but one that sends a
     * command or stops the server does what those permissions guard, so it needs them too.
     */
    public static function permission(string $action): string
    {
        return match ($action) {
            'command' => 'commands',
            'start', 'stop', 'restart' => 'power',
            default => 'backups',
        };
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
