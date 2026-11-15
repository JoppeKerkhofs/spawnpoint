<?php

namespace App\Models;

use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $base_url
 * @property string $token
 * @property string|null $dns_target
 * @property string|null $bluemap_url
 * @property int|null $memory_limit_mb
 * @property Carbon|null $last_seen_at
 */
#[Fillable(['name', 'base_url', 'token', 'dns_target', 'bluemap_url', 'memory_limit_mb'])]
#[Hidden(['token'])]
class Node extends Model
{
    /** @use HasFactory<NodeFactory> */
    use HasFactory;

    // The node the panel registers for itself from its environment on every start.
    public const string LOCAL = 'local';

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * Where the panel's web server sends browser traffic for this node, as "host:port", or
     * "https://host:port" for a node behind TLS, which makes the relay use TLS as well.
     */
    public function upstream(): string
    {
        $parts = parse_url($this->base_url);
        $https = ($parts['scheme'] ?? 'http') === 'https';

        return ($https ? 'https://' : '').($parts['host'] ?? '').':'.($parts['port'] ?? ($https ? 443 : 80));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_seen_at' => 'datetime',
            'memory_limit_mb' => 'integer',
        ];
    }
}
