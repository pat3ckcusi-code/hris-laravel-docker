<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts a monetary decimal column at rest, storing ciphertext in a text
 * column while exposing a plain float to the rest of the app.
 *
 * get() tolerates a value that isn't ciphertext yet (still-plaintext legacy
 * rows during a backfill window) by falling back to a numeric read instead
 * of throwing, matching the try/decrypt-else pattern already used by
 * Pds::encryptSensitiveFields()/decryptSensitiveFields().
 *
 * @implements CastsAttributes<float|null, float|int|string|null>
 */
class EncryptedDecimal implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);

            return is_numeric($decrypted) ? round((float) $decrypted, 2) : null;
        } catch (DecryptException) {
            return is_numeric($value) ? round((float) $value, 2) : null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString(number_format((float) $value, 2, '.', ''));
    }
}
