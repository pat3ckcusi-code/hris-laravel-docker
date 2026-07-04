<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class UserStatusScopeTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_scope_active_includes_active_null_and_empty_status(): void
    {
        $active = $this->createEmployee(['Status' => 'Active']);
        $legacyNull = $this->createEmployee(['Status' => null]);
        $legacyEmpty = $this->createEmployee(['Status' => '']);
        $inactive = $this->createEmployee(['Status' => 'Inactive']);
        $separated = $this->createEmployee(['Status' => 'Separated']);

        $activeIds = User::active()->pluck('id')->all();

        $this->assertContains($active->id, $activeIds);
        $this->assertContains($legacyNull->id, $activeIds);
        $this->assertContains($legacyEmpty->id, $activeIds);
        $this->assertNotContains($inactive->id, $activeIds);
        $this->assertNotContains($separated->id, $activeIds);
    }

    public function test_isactive_isinactive_isseparated_helpers(): void
    {
        $active = $this->createEmployee(['Status' => 'Active']);
        $inactive = $this->createEmployee(['Status' => 'Inactive']);
        $separated = $this->createEmployee(['Status' => 'Separated']);
        $legacyNull = $this->createEmployee(['Status' => null]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($active->isInactive());
        $this->assertFalse($active->isSeparated());

        $this->assertFalse($inactive->isActive());
        $this->assertTrue($inactive->isInactive());

        $this->assertFalse($separated->isActive());
        $this->assertTrue($separated->isSeparated());

        $this->assertTrue($legacyNull->isActive());
    }
}
