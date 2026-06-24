<?php

namespace Tests\Unit;

use App\Support\UserAgentLabel;
use Tests\TestCase;

class UserAgentLabelTest extends TestCase
{
    public function test_recognises_obs_publisher(): void
    {
        $ua = 'Mozilla/5.0 (OBS-Studio/32.1.1; Windows x86_64; en-US)';

        $this->assertSame('OBS Studio', UserAgentLabel::format($ua));
    }

    public function test_formats_browser_and_platform(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0';

        $this->assertSame('Edge / Windows', UserAgentLabel::format($ua));
    }

    public function test_empty_is_unknown(): void
    {
        $this->assertSame('Unknown', UserAgentLabel::format(''));
        $this->assertSame('Unknown', UserAgentLabel::format(null));
    }

    public function test_unrecognised_falls_back_to_first_token(): void
    {
        $this->assertSame('CustomEncoder', UserAgentLabel::format('CustomEncoder/1.0'));
    }
}
