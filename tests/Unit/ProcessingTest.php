<?php

namespace LogInsight\Tests\Unit;

use LogInsight\Processing\Fingerprinter;
use LogInsight\Processing\Redactor;
use LogInsight\Processing\Tagger;
use LogInsight\Security\IpAllowlist;
use LogInsight\Tests\TestCase;

class ProcessingTest extends TestCase
{
    private function redactor(): Redactor
    {
        return Redactor::fromConfig();
    }

    public function test_redacts_sensitive_json_keys_and_key_value_pairs(): void
    {
        $out = $this->redactor()->redact('{"user":"bob","password":"hunter2","api_key":"abc","n":5} and token=xyz123 ok');

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('"abc"', $out);
        $this->assertStringNotContainsString('xyz123', $out);
        $this->assertStringContainsString('"user":"bob"', $out);
        $this->assertJson(explode(' and ', $out)[0], 'redacted JSON stays valid');
    }

    public function test_redacts_bearer_jwt_and_luhn_valid_cards_only(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r';
        $out = $this->redactor()->redact("Authorization: Bearer abc.def-ghi jwt=$jwt card 4111 1111 1111 1111 order 1234567890123");

        $this->assertStringNotContainsString('abc.def-ghi', $out);
        $this->assertStringNotContainsString($jwt, $out);
        $this->assertStringNotContainsString('4111', $out);
        $this->assertStringContainsString('1234567890123', $out, 'non-Luhn digit runs are left alone');
    }

    public function test_redaction_can_be_disabled_and_handles_null(): void
    {
        $off = new Redactor(false, ['password']);
        $this->assertSame('password=abc', $off->redact('password=abc'));
        $this->assertNull($this->redactor()->redact(null));
    }

    public function test_fingerprint_ignores_ids_quotes_uuids_but_not_the_error_type(): void
    {
        $f = new Fingerprinter;
        $a = $f->fingerprint('QueryException', "Table 'shop.orders_1' missing for id 55", '/app/A.php', 10);
        $b = $f->fingerprint('QueryException', "Table 'shop.orders_9' missing for id 9001", '/other/dir/A.php', 10);
        $c = $f->fingerprint('QueryException', "Different problem entirely", '/app/A.php', 10);
        $d = $f->fingerprint('OtherException', "Table 'shop.orders_1' missing for id 55", '/app/A.php', 10);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame($a, $d);
        $this->assertSame(
            $f->normalize('user 7f9c2d3a-1b2c-4d5e-8f90-123456789abc failed 0xDEADBEEF'),
            'user <uuid> failed <hex>'
        );
    }

    public function test_ip_allowlist_supports_exact_cidr_and_ipv6(): void
    {
        $this->assertTrue(IpAllowlist::matches('10.1.2.3', ['10.0.0.0/8']));
        $this->assertFalse(IpAllowlist::matches('11.1.2.3', ['10.0.0.0/8']));
        $this->assertTrue(IpAllowlist::matches('192.168.1.5', ['192.168.1.5']));
        $this->assertTrue(IpAllowlist::matches('192.168.1.130', ['192.168.1.128/25']));
        $this->assertFalse(IpAllowlist::matches('192.168.1.5', ['192.168.1.128/25']));
        $this->assertTrue(IpAllowlist::matches('2001:db8::1', ['2001:db8::/32']));
        $this->assertFalse(IpAllowlist::matches('2001:db9::1', ['2001:db8::/32']));
        $this->assertFalse(IpAllowlist::matches('not-an-ip', ['10.0.0.0/8']));
        $this->assertFalse(IpAllowlist::matches('10.0.0.1', []), 'empty list matches nothing (middleware skips the check separately)');
    }

    public function test_auto_tags_cover_level_environment_exception_and_context(): void
    {
        $tags = (new Tagger(['QueryException' => 'database']))->tagsFor([
            'level' => 'error', 'environment' => 'production', 'channel' => null, 'message' => 'x',
            'exception_class' => 'Illuminate\\Database\\QueryException', 'stack_trace' => null,
        ], ['user_id' => 3, 'tags' => ['Payment', 'stripe']]);

        $this->assertEqualsCanonicalizing(['error', 'production', 'database', 'has-user', 'payment', 'stripe'], $tags);
    }

    public function test_safe_regex_rejects_overlong_and_invalid_patterns(): void
    {
        $this->assertTrue(Tagger::safeRegex('time(d)?\s*out', 'Request timed out'));
        $this->assertFalse(Tagger::safeRegex('(unclosed', 'anything'));
        $this->assertFalse(Tagger::safeRegex(str_repeat('a', 500), str_repeat('a', 500)));
    }
}
