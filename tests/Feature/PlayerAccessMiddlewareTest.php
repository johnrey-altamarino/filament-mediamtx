<?php

namespace Tests\Feature;

use App\Models\PlayerIpRule;
use App\Models\PlayerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mirror the data migration default (migration may have already seeded this row)
        PlayerSetting::updateOrCreate(['key' => 'ip_mode'], ['value' => 'blacklist']);
        // Clear any cached values between tests
        cache()->forget('player_ip_rules');
        cache()->forget('player_settings');
    }

    // ── Blacklist mode ────────────────────────────────────────────────

    public function test_blacklist_mode_allows_unlisted_ip(): void
    {
        $response = $this->get('/play');

        $response->assertStatus(200);
    }

    public function test_blacklist_mode_blocks_listed_ip(): void
    {
        PlayerIpRule::create(['ip' => '1.2.3.4']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '1.2.3.4']);

        $response->assertStatus(403);
    }

    public function test_blacklist_mode_allows_ip_not_in_list(): void
    {
        PlayerIpRule::create(['ip' => '1.2.3.4']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(200);
    }

    // ── Whitelist mode ────────────────────────────────────────────────

    public function test_whitelist_mode_blocks_unlisted_ip(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(403);
    }

    public function test_whitelist_mode_allows_listed_ip(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);
        PlayerIpRule::create(['ip' => '9.9.9.9']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(200);
    }

    public function test_whitelist_mode_with_0000_allows_any_ip(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);
        PlayerIpRule::create(['ip' => '0.0.0.0']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(200);
    }

    public function test_whitelist_mode_blocks_ip_when_only_different_ip_listed(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);
        PlayerIpRule::create(['ip' => '1.2.3.4']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(403);
    }

    public function test_blocked_ip_renders_branded_error_page(): void
    {
        PlayerIpRule::create(['ip' => '1.2.3.4']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '1.2.3.4']);

        $response->assertStatus(403);
        $response->assertSee('Stream unavailable');
        $response->assertSee("This video isn't available here.", escape: false);
    }

    public function test_whitelist_rejection_renders_branded_error_page(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(403);
        $response->assertSee('Stream unavailable');
    }
}
