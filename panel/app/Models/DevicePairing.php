<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A device waiting to be paired. The app holds the secret, a person types the code in the panel,
 * and the token the approval mints waits here until the app collects it, once.
 *
 * @property int $id
 * @property string $code
 * @property string $secret_hash
 * @property string $device_name
 * @property string|null $token
 * @property list<string>|null $abilities
 * @property Carbon|null $approved_at
 * @property Carbon $expires_at
 */
#[Fillable(['code', 'secret_hash', 'device_name', 'expires_at', 'created_at'])]
#[Hidden(['secret_hash', 'token'])]
class DevicePairing extends Model
{
    public $timestamps = false;

    // Long enough to walk to a computer, short enough that a shoulder-surfed code is useless.
    public const int MINUTES = 10;

    // No I, L, O, 0 or 1: these are read aloud and typed by hand.
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * A code that no pending pairing is already using.
     */
    public static function newCode(): string
    {
        do {
            $code = collect(range(1, 8))->map(fn (): string => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Mint the token for this device and keep it until the app collects it.
     *
     * @param  list<string>  $abilities
     */
    public function approve(User $user, array $abilities): void
    {
        $this->forceFill([
            'user_id' => $user->id,
            'abilities' => $abilities,
            'token' => $user->createToken($this->device_name, $abilities)->plainTextToken,
            'approved_at' => now(),
        ])->save();

        ActivityLog::record('token.pair', properties: ['device' => $this->device_name, 'abilities' => $abilities]);
    }

    /**
     * Throw away what nobody collected.
     */
    public static function pruneExpired(): int
    {
        return self::query()->where('expires_at', '<', now())->delete();
    }

    /**
     * Whether this is the secret the app was given.
     */
    public function matches(string $secret): bool
    {
        // A plain SHA-256, as Sanctum does for its tokens: the secret is 64 random characters, so
        // there is nothing to brute-force, and a slow password hash on an endpoint anyone may
        // poll would only be a way to keep the processor busy.
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public static function secret(): string
    {
        return Str::random(64);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'abilities' => 'array',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
