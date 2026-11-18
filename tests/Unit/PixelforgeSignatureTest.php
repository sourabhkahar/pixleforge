<?php

namespace Tests\Unit;

use App\Services\Webhooks\PixelforgeSignature;
use PHPUnit\Framework\TestCase;

class PixelforgeSignatureTest extends TestCase
{
    private const BODY = '{"id":"evt_1","type":"ping"}';

    public function test_accepts_a_valid_signature(): void
    {
        $signer = new PixelforgeSignature('whsec_x');
        $t = 1_800_000_000;
        $header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.self::BODY, 'whsec_x');

        $this->assertTrue($signer->verify(self::BODY, $header, $t + 10));
    }

    public function test_rejects_tampering_wrong_secret_staleness_and_garbage(): void
    {
        $signer = new PixelforgeSignature('whsec_x');
        $t = 1_800_000_000;
        $header = $signer->sign(self::BODY, $t);

        $this->assertFalse($signer->verify(self::BODY.' ', $header, $t), 'body changed');
        $this->assertFalse((new PixelforgeSignature('whsec_other'))->verify(self::BODY, $header, $t), 'wrong secret');
        $this->assertFalse($signer->verify(self::BODY, $header, $t + 301), 'too old');
        $this->assertFalse($signer->verify(self::BODY, 'v1=abc', $t), 'no timestamp');
        $this->assertFalse($signer->verify(self::BODY, null, $t), 'missing header');
        $this->assertFalse((new PixelforgeSignature(null))->verify(self::BODY, $header, $t), 'no secret configured');
    }
}
