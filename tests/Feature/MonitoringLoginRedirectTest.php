<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MonitoringLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_login_response_redirects_monitoring_user_to_monitor(): void
    {
        $user = User::factory()->create();
        $user->assignRole('monitoring');

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $response = $this->actingAs($user)
            ->app->make(LoginResponse::class)
            ->toResponse($request);

        $this->assertEquals(route('monitor'), $response->getTargetUrl());
    }

    public function test_login_response_does_not_redirect_admin_to_monitor(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $response = $this->actingAs($user)
            ->app->make(LoginResponse::class)
            ->toResponse($request);

        $this->assertNotEquals(route('monitor'), $response->getTargetUrl());
    }
}
