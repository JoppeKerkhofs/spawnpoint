<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string $hostname
 * @property string $server_type
 * @property string $mc_version
 * @property int $java_version
 * @property int $memory_mb
 * @property array{motd: string, difficulty: string, mode: string, max_players: int} $settings
 * @property array{project: string, name: string, icon: string|null}|null $modpack
 * @property Carbon $eula_accepted_at
 * @property-read Node $node
 */
#[Fillable(['node_id', 'owner_id', 'name', 'slug', 'hostname', 'server_type', 'mc_version', 'java_version', 'memory_mb', 'settings', 'modpack', 'eula_accepted_at'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    // These lists mirror the ones the Rust service accepts (service/src/main.rs).
    public const array TYPES = ['VANILLA', 'PAPER', 'FABRIC', 'FORGE', 'NEOFORGE', 'MODRINTH', 'AUTO_CURSEFORGE'];

    // Server types that install a whole modpack instead of one loader.
    public const array PACK_TYPES = ['MODRINTH', 'AUTO_CURSEFORGE'];

    public const array JAVA_VERSIONS = [8, 17, 21, 25];

    public const array DIFFICULTIES = ['peaceful', 'easy', 'normal', 'hard'];

    public const array MODES = ['survival', 'creative', 'adventure', 'spectator'];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'java_version' => 'integer',
            'memory_mb' => 'integer',
            'settings' => 'array',
            'modpack' => 'array',
            'eula_accepted_at' => 'datetime',
        ];
    }
}
