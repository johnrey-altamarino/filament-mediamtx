<?php

namespace Tests\Feature;

use App\Livewire\MultistreamMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MultistreamMonitorTest extends TestCase
{
    use RefreshDatabase;

    private array $fakePathsItems = [];

    private bool $pathsFakeRegistered = false;

    /**
     * Re-fakes the paths list endpoint with a fresh response. Http::fake()
     * stacks stubs rather than replacing them (first matching stub wins), so
     * to let later calls actually override earlier ones within a single test
     * we re-fake via a single mutable callback registered once.
     */
    private function fakePaths(int $count): void
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = [
                'name' => "live/p{$i}",
                'ready' => true,
                'source' => ['type' => 'rtmpConn'],
                'tracks' => ['H264'],
                'readers' => [],
            ];
        }

        $this->fakePathsItems = $items;

        if (! $this->pathsFakeRegistered) {
            $this->pathsFakeRegistered = true;

            Http::fake([
                '*/v3/paths/list' => fn () => Http::response(['items' => $this->fakePathsItems], 200),
            ]);
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/monitor')->assertRedirect('/login');
    }

    public function test_first_page_shows_six_tiles_and_paginates_the_rest(): void
    {
        $this->fakePaths(7);

        Livewire::test(MultistreamMonitor::class)
            ->assertSet('page', 1)
            ->assertSee('live/p1')
            ->assertSee('live/p6')
            ->assertDontSee('live/p7')
            ->call('nextPage')
            ->assertSet('page', 2)
            ->assertSee('live/p7')
            ->assertDontSee('live/p1');
    }

    public function test_empty_state_when_no_active_publishers(): void
    {
        $this->fakePaths(0);

        Livewire::test(MultistreamMonitor::class)
            ->assertSee('No active publishers');
    }

    public function test_page_clamps_down_when_list_shrinks(): void
    {
        $this->fakePaths(7);

        $component = Livewire::test(MultistreamMonitor::class)
            ->call('nextPage')
            ->assertSet('page', 2);

        $this->fakePaths(3); // now only one page worth

        $component->call('refresh')
            ->assertSet('page', 1)
            ->assertSee('live/p1');
    }

    public function test_authenticated_route_renders_grid(): void
    {
        $this->fakePaths(2);

        $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/monitor')
            ->assertOk()
            ->assertSee('live/p1');
    }

    public function test_monitor_page_has_logout_control(): void
    {
        $this->fakePaths(0);

        $response = $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/monitor');

        $response->assertOk();
        $response->assertSee(route('filament.admin.auth.logout'), escape: false);
    }
}
