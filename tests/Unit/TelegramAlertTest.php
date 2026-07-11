<?php

namespace Tests\Unit;

use App\Support\TelegramAlert;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TelegramAlertTest extends TestCase
{
    public function test_esc_encodes_html_special_chars(): void
    {
        $this->assertSame('&lt;b&gt;&amp;', TelegramAlert::esc('<b>&'));
    }

    public function test_flood_cap_blocks_after_cap(): void
    {
        Cache::flush();

        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = TelegramAlert::floodCap('test', 3);
        }

        $this->assertSame([true, true, true, false], $results);
    }
}
