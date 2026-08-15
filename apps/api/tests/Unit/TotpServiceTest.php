<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    /**
     * RFC-6238-Testvektor: ASCII-Secret "12345678901234567890" (Base32:
     * GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ), T=59s => Counter 1 => Code 287082.
     */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_code_matches_rfc_6238_vector(): void
    {
        $totp = new TotpService();

        $this->assertSame('287082', $totp->codeAt(self::RFC_SECRET, 1));
        $this->assertSame('081804', $totp->codeAt(self::RFC_SECRET, intdiv(1111111109, 30)));
    }

    public function test_verify_accepts_valid_code_with_window(): void
    {
        $totp = new TotpService();

        $this->assertTrue($totp->verify(self::RFC_SECRET, '287082', 59));
        // Ein Fenster (30s) Abweichung wird toleriert.
        $this->assertTrue($totp->verify(self::RFC_SECRET, '287082', 59 + 30));
        $this->assertFalse($totp->verify(self::RFC_SECRET, '287082', 59 + 120));
        $this->assertFalse($totp->verify(self::RFC_SECRET, '000000', 59));
        $this->assertFalse($totp->verify(self::RFC_SECRET, 'abc', 59));
    }

    public function test_generated_secret_roundtrip(): void
    {
        $totp = new TotpService();
        $secret = $totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);

        $code = $totp->codeAt($secret, intdiv(time(), 30));
        $this->assertTrue($totp->verify($secret, $code));
    }
}
