<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts a JSON array column at rest, using the same
 * json_encode(['encrypted' => Crypt::encryptString(...)]) wrapping
 * convention already established by Pds::setSectionDataAttribute() /
 * getSectionDataAttribute() — the wrapped payload is itself valid JSON, so
 * the underlying column can stay `json` typed with no migration needed.
 *
 * get() tolerates a value that isn't wrapped/encrypted yet (still-plaintext
 * legacy rows during a backfill window) by returning the plain decoded
 * array instead of throwing.
 *
 * @implements CastsAttributes<array|null, array|null>
 */
class EncryptedArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (is_array($decoded) && array_key_exists('encrypted', $decoded) && is_string($decoded['encrypted'])) {
            try {
                $decrypted = Crypt::decryptString($decoded['encrypted']);
                $inner = json_decode($decrypted, true);

                return json_last_error() === JSON_ERROR_NONE ? $inner : null;
            } catch (\Exception) {
                return null;
            }
        }

        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(['encrypted' => Crypt::encryptString(json_encode($value))]);
    }
}
