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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property array<string, mixed> $settings Game settings; servers saved before a setting existed lack its key.
 * @property array{project: string, name: string, icon: string|null, version?: string|null, version_name?: string|null}|null $modpack
 * @property bool $bluemap_enabled
 * @property bool $is_default
 * @property int $backup_keep
 * @property int|null $backup_retention_days
 * @property Carbon $eula_accepted_at
 * @property-read Node $node
 */
#[Fillable(['node_id', 'owner_id', 'name', 'slug', 'hostname', 'server_type', 'mc_version', 'java_version', 'memory_mb', 'settings', 'modpack', 'bluemap_enabled', 'is_default', 'backup_keep', 'backup_retention_days', 'eula_accepted_at'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    // These lists mirror the ones the Rust service accepts (service/src/main.rs).
    public const array TYPES = ['VANILLA', 'PAPER', 'FABRIC', 'FORGE', 'NEOFORGE', 'PUMPKIN', 'MODRINTH', 'AUTO_CURSEFORGE', 'FTBA'];

    // Server types that install a whole modpack instead of one loader.
    public const array PACK_TYPES = ['MODRINTH', 'AUTO_CURSEFORGE', 'FTBA'];

    public const array JAVA_VERSIONS = [8, 17, 21, 25];

    // What a viewer can be allowed on a shared server, beyond seeing it and watching its console.
    public const array PERMISSIONS = ['logs', 'commands', 'power', 'files', 'backups', 'configure'];

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
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * The viewers this server is shared with.
     *
     * @return BelongsToMany<User, $this>
     */
    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('permissions');
    }

    /**
     * What a user may do on this server: every permission plus "admin" for an admin, the
     * permissions of the share for a viewer, and nothing for anyone else.
     *
     * @return list<string>
     */
    public function permissionsFor(User $user): array
    {
        if ($user->isAdmin()) {
            return [...self::PERMISSIONS, 'admin'];
        }

        $share = DB::table('server_user')->where(['server_id' => $this->id, 'user_id' => $user->id])->first();

        return $share === null ? [] : array_values(array_intersect(self::PERMISSIONS, json_decode((string) $share->permissions, true) ?? []));
    }

    /**
     * The server as the pages get it: its data plus what the user may do with it.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        return [...$this->toArray(), 'can' => $this->permissionsFor($user), 'node' => $this->node->name];
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
            'is_default' => 'boolean',
            'backup_keep' => 'integer',
            'backup_retention_days' => 'integer',
            'eula_accepted_at' => 'datetime',
        ];
    }
}
