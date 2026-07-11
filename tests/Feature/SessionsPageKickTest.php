<?php

namespace Tests\Feature;

use App\Filament\Pages\Sessions;
use App\Models\MediamtxSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SessionsPageKickTest extends TestCase
{
    use RefreshDatabase;

    public function test_kick_action_routes_to_moq_endpoint(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        Role::findOrCreate('super-admin');
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $session = MediamtxSession::create([
            'type' => 'moq', 'session_id' => 'm-42', 'state' => 'read',
            'path' => 'live/cam1', 'ip' => '5.6.7.8', 'last_seen_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Sessions::class)
            ->callTableAction('kick', $session);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/moqsessions/kick/m-42'));
    }
}
