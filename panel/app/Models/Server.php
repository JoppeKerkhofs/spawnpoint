<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property bool $bluemap_enabled
 * @property string|null $backup_at
 * @property int $backup_keep
 * @property Carbon $eula_accepted_at
 * @property-read Node $node
 */
#[Fillable(['node_id', 'owner_id', 'name', 'slug', 'hostname', 'server_type', 'mc_version', 'java_version', 'memory_mb', 'settings', 'modpack', 'bluemap_enabled', 'backup_at', 'backup_keep', 'eula_accepted_at'])]
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
     * The viewers this server is shared with.
     *
     * @return BelongsToMany<User, $this>
     */
    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Limit a query to the servers a user may see: all of them for an admin, the shared ones for a viewer.
     *
     * @param  Builder<Server>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->whereHas('viewers', fn (Builder $viewers) => $viewers->whereKey($user->id));
        }
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
            'bluemap_enabled' => 'boolean',
            'backup_keep' => 'integer',
            'eula_accepted_at' => 'datetime',
        ];
    }
}
