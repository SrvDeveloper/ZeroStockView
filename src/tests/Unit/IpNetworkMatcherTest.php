<?php

namespace Tests\Unit;

use App\Services\IpNetworkMatcher;
use PHPUnit\Framework\TestCase;

class IpNetworkMatcherTest extends TestCase
{
    public function test_it_matches_ipv4_and_ipv6_cidr_ranges(): void
    {
        $matcher = new IpNetworkMatcher;

        $this->assertTrue($matcher->matches('203.0.113.42', '203.0.113.0/24'));
        $this->assertFalse($matcher->matches('203.0.114.1', '203.0.113.0/24'));
        $this->assertTrue($matcher->matches('2001:db8::42', '2001:db8::/32'));
        $this->assertFalse($matcher->matches('2001:db9::1', '2001:db8::/32'));
    }

    public function test_it_validates_exact_addresses_and_prefix_lengths(): void
    {
        $matcher = new IpNetworkMatcher;

        $this->assertTrue($matcher->isValid('203.0.113.42'));
        $this->assertTrue($matcher->isValid('2001:db8::1'));
        $this->assertFalse($matcher->isValid('203.0.113.0/33'));
        $this->assertFalse($matcher->isValid('2001:db8::/129'));
        $this->assertFalse($matcher->isValid('not-an-ip'));
    }
}
