<?php

namespace Tests\Feature\CrossCutting;

use App\Auth\ExpiringRememberTokenUserProvider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Cross-Cutting: Remember-Me Token Expiration
 *
 * Covers: App\Auth\ExpiringRememberTokenUserProvider, the custom "users"
 * auth provider (config/auth.php) that adds server-side expiration on top
 * of the framework's plain hash_equals() remember_token check. Tested via
 * direct instantiation against the real DB rather than simulating the
 * encrypted recaller cookie over HTTP.
 */
class RememberTokenExpirationTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function provider(): ExpiringRememberTokenUserProvider
    {
        return new ExpiringRememberTokenUserProvider(app('hash'), User::class);
    }

    /**
     * Sets remember_token_created_at to a specific instant. Must run as a
     * second, separate save after user creation - User::booted()'s saving
     * hook stamps remember_token_created_at to now() any time remember_token
     * is dirty, which it already is on the initial createEmployee() call, so
     * an override passed in that same call would be immediately overwritten.
     */
    private function backdateToken(User $user, Carbon $issuedAt): User
    {
        $user->forceFill(['remember_token_created_at' => $issuedAt])->save();

        return $user->fresh();
    }

    public function test_fresh_token_is_accepted(): void
    {
        $user = $this->createEmployee(['remember_token' => 'token123']);
        $user = $this->backdateToken($user, now());

        $result = $this->provider()->retrieveByToken($user->id, 'token123');

        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    public function test_token_within_expiration_window_is_accepted(): void
    {
        $user = $this->createEmployee(['remember_token' => 'token123']);
        $user = $this->backdateToken($user, now()->subDays(11));

        $result = $this->provider()->retrieveByToken($user->id, 'token123');

        $this->assertNotNull($result);
    }

    public function test_token_older_than_expiration_is_rejected(): void
    {
        $user = $this->createEmployee(['remember_token' => 'token123']);
        $user = $this->backdateToken($user, now()->subDays(13));

        $result = $this->provider()->retrieveByToken($user->id, 'token123');

        $this->assertNull($result);
    }

    public function test_expired_token_is_cleared_from_database_and_cannot_be_replayed(): void
    {
        $user = $this->createEmployee(['remember_token' => 'token123']);
        $user = $this->backdateToken($user, now()->subDays(13));

        $this->assertNull($this->provider()->retrieveByToken($user->id, 'token123'));

        $fresh = User::find($user->id);
        $this->assertNull($fresh->remember_token);
        $this->assertNull($fresh->remember_token_created_at);

        // The stale token string, presented again, must still be rejected.
        $this->assertNull($this->provider()->retrieveByToken($user->id, 'token123'));
    }

    public function test_token_with_no_issued_at_timestamp_is_rejected(): void
    {
        // Simulates every row that predates this feature: remember_token set,
        // remember_token_created_at never backfilled (still null). Written via
        // a raw DB update, bypassing User::booted()'s saving hook entirely -
        // going through Eloquent here would always stamp a timestamp, which
        // isn't what a real pre-migration row looks like. This is the
        // fail-closed default - it must NOT be grandfathered in as valid.
        $user = $this->createEmployee();
        DB::table('users')->where('id', $user->id)->update(['remember_token' => 'token123']);

        $this->assertNull($user->fresh()->remember_token_created_at);

        $result = $this->provider()->retrieveByToken($user->id, 'token123');

        $this->assertNull($result);
    }

    public function test_wrong_token_value_is_still_rejected(): void
    {
        $user = $this->createEmployee(['remember_token' => 'token123']);
        $user = $this->backdateToken($user, now());

        $result = $this->provider()->retrieveByToken($user->id, 'a-completely-different-token');

        $this->assertNull($result);
    }

    public function test_expiration_window_is_configurable(): void
    {
        config(['auth.remember_token_expiration_days' => 5]);

        $user = $this->createEmployee(['remember_token' => 'token123']);
        $user = $this->backdateToken($user, now()->subDays(6));
        $this->assertNull($this->provider()->retrieveByToken($user->id, 'token123'));

        $user2 = $this->createEmployee(['remember_token' => 'token456']);
        $user2 = $this->backdateToken($user2, now()->subDays(4));
        $this->assertNotNull($this->provider()->retrieveByToken($user2->id, 'token456'));
    }

    public function test_saving_user_with_cleared_remember_token_also_clears_issued_at(): void
    {
        $user = $this->createEmployee(['remember_token' => 'token123']);
        $user = $this->backdateToken($user, now());

        $user->forceFill(['remember_token' => null])->save();

        $fresh = $user->fresh();
        $this->assertNull($fresh->remember_token);
        $this->assertNull($fresh->remember_token_created_at);
    }
}
