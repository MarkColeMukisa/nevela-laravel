<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Auth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /**
     * The secret RFC 6238 tests with: the twenty ASCII digits below, in base32, which is how
     * an authenticator app is given a secret. Worked out here, not written out, so that a
     * published test value isn't mistaken for a real key.
     */
    private static function secret(): string
    {
        $bits = '';
        foreach (str_split(str_repeat('1234567890', 2)) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $five) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec($five)], str_split($bits, 5)));
    }

    public function test_codes_match_the_published_test_vectors(): void
    {
        // RFC 6238, appendix B (SHA-1). The RFC lists eight digits; an app shows the last six.
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $time => $code) {
            $this->assertSame($code, Totp::code(self::secret(), $time), "at {$time}");
        }
    }

    public function test_a_code_is_accepted_one_step_either_side_and_no_further(): void
    {
        $now = 1700000010;
        $this->assertTrue(Totp::verify(self::secret(), Totp::code(self::secret(), $now), $now));
        // Typed just as it changed, or on a phone whose clock is a little off.
        $this->assertTrue(Totp::verify(self::secret(), Totp::code(self::secret(), $now - 30), $now));
        $this->assertTrue(Totp::verify(self::secret(), Totp::code(self::secret(), $now + 30), $now));
        $this->assertFalse(Totp::verify(self::secret(), Totp::code(self::secret(), $now - 90), $now));
        $this->assertFalse(Totp::verify(self::secret(), Totp::code(self::secret(), $now + 90), $now));
    }

    public function test_only_six_digits_are_a_code_however_they_are_spaced(): void
    {
        $now = 1700000010;
        $code = Totp::code(self::secret(), $now);

        $this->assertTrue(Totp::verify(self::secret(), substr($code, 0, 3).' '.substr($code, 3), $now));
        foreach (['', '12345', '1234567', 'abcdef', $code.'0'] as $wrong) {
            $this->assertFalse(Totp::verify(self::secret(), $wrong, $now), $wrong);
        }
    }

    public function test_a_new_secret_is_long_enough_and_is_what_the_qr_code_carries(): void
    {
        $secret = Totp::secret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::secret());

        $uri = Totp::uri($secret, 'ada@example.com', 'My Shop');
        $this->assertStringStartsWith('otpauth://totp/My%20Shop:ada%40example.com?secret='.$secret, $uri);
        $this->assertStringContainsString('issuer=My%20Shop', $uri);
        // A code made from the secret in the address is the code the server expects.
        $this->assertTrue(Totp::verify($secret, Totp::code($secret)));
    }
}
