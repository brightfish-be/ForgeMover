<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::find($key)?->value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        if ($value === null) {
            static::where('key', $key)->delete();

            return;
        }
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
