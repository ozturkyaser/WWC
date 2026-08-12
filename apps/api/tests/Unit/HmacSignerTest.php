<?php

namespace Tests\Unit;

use App\Services\HmacSigner;
use PHPUnit\Framework\TestCase;

class HmacSignerTest extends TestCase
{
    public function test_sign_and_verify_roundtrip(): void
    {
        $sig = HmacSigner::sign('POST', '/api/agent/heartbeat', '1700000000', 'nonce123', '{"a":1}', 'secret');
        $this->assertTrue(HmacSigner::verify('POST', '/api/agent/heartbeat', '1700000000', 'nonce123', '{"a":1}', $sig, 'secret'));
        $this->assertFalse(HmacSigner::verify('POST', '/api/agent/heartbeat', '1700000000', 'nonce123', '{"a":1}', $sig, 'wrong'));
    }

    public function test_timestamp_window(): void
    {
        $this->assertTrue(HmacSigner::isTimestampFresh((string) time()));
        $this->assertFalse(HmacSigner::isTimestampFresh((string) (time() - 1000)));
    }
}
