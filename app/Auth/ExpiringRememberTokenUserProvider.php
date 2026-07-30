<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

class ExpiringRememberTokenUserProvider extends EloquentUserProvider
{
    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     *
     * Adds server-side expiration on top of the framework's plain hash_equals()
     * check: a token with no recorded issue timestamp (every row that predates
     * this feature) is treated as already expired, not grandfathered in.
     */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        if ($user === null) {
            return null;
        }

        if ($this->rememberTokenHasExpired($user)) {
            $this->clearExpiredRememberToken($user);

            return null;
        }

        return $user;
    }

    protected function rememberTokenHasExpired(UserContract $user): bool
    {
        $issuedAt = $user->remember_token_created_at;

        if ($issuedAt === null) {
            return true;
        }

        $lifetimeDays = (int) config('auth.remember_token_expiration_days');

        return $issuedAt->copy()->addDays($lifetimeDays)->isPast();
    }

    protected function clearExpiredRememberToken(UserContract $user): void
    {
        $user->forceFill([$user->getRememberTokenName() => null]);

        $timestamps = $user->timestamps;
        $user->timestamps = false;
        $user->save();
        $user->timestamps = $timestamps;
    }
}
