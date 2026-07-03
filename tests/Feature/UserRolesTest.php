<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_monitoring_only_user_is_monitoring_only(): void
    {
        $user = User::factory()->create();
        $user->assignRole('monitoring');

        $this->assertTrue($user->isMonitoringOnly());
    }

    public function test_user_with_elevated_role_is_not_monitoring_only(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['monitoring', 'moderator']);

        $this->assertFalse($user->isMonitoringOnly());
    }

    public function test_user_without_monitoring_is_not_monitoring_only(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->assertFalse($user->isMonitoringOnly());
    }
}
