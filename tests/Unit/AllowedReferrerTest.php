<?php

namespace Tests\Unit;

use App\Models\AllowedReferrer;
use Tests\TestCase;

class AllowedReferrerTest extends TestCase
{
    private function wildcard(string $pattern): AllowedReferrer
    {
        return new AllowedReferrer([
            'pattern' => $pattern,
            'is_regex' => false,
            'is_enabled' => true,
        ]);
    }

    public function test_wildcard_matches_legitimate_subdomain(): void
    {
        $this->assertTrue($this->wildcard('https://*.example.com')->matches('https://app.example.com/play'));
    }

    public function test_wildcard_matches_exact_host_root(): void
    {
        $this->assertTrue($this->wildcard('https://*.example.com')->matches('https://a.example.com'));
    }

    public function test_wildcard_does_not_match_attacker_path_segment(): void
    {
        // The "*" must not span the path: evil.com hosting /.example.com must be rejected.
        $this->assertFalse($this->wildcard('https://*.example.com')->matches('https://evil.com/.example.com'));
    }

    public function test_wildcard_does_not_match_attacker_query_string(): void
    {
        $this->assertFalse($this->wildcard('https://*.example.com')->matches('https://evil.com?x=.example.com'));
    }

    public function test_wildcard_does_not_match_host_in_userinfo(): void
    {
        $this->assertFalse($this->wildcard('https://*.example.com')->matches('https://sub.example.com@evil.com/'));
    }

    public function test_disabled_rule_never_matches(): void
    {
        $rule = new AllowedReferrer([
            'pattern' => 'https://*.example.com',
            'is_regex' => false,
            'is_enabled' => false,
        ]);

        $this->assertFalse($rule->matches('https://app.example.com/'));
    }
}
