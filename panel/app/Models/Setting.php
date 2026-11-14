<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /**
     * Get a panel setting: the value saved in the panel, or else the one from the environment.
     */
    public static function value(string $key): mixed
    {
        $saved = self::query()->find($key);

        return $saved === null ? config("panel.{$key}") : $saved->value;
    }

    /**
     * Save a setting; an empty value removes it, so the environment's value applies again.
     */
    public static function put(string $key, ?string $value): void
    {
        if (blank($value)) {
            self::query()->whereKey($key)->delete();
        } else {
            self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
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
            'value' => 'encrypted',
        ];
    }
}
