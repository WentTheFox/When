<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A boolean stored APP_KEY-encrypted (§0.2 tier) in a text column — Laravel's
 * own 'encrypted' cast only round-trips strings, and a plain boolean column
 * would leave the value readable to anyone holding a database copy.
 *
 * @implements CastsAttributes<bool|null, bool|null>
 */
class EncryptedBoolean implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?bool
    {
        return $value === null ? null : Crypt::decryptString($value) === '1';
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString($value ? '1' : '0');
    }
}
