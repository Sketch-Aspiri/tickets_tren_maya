<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AuditPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuditPayloadTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function keys(): array
    {
        return [
            'password' => ['password', true],
            'password_confirmation' => ['password_confirmation', true],
            'current_password' => ['current_password', true],
            'mayusculas' => ['PASSWORD', true],
            'secreto 2fa' => ['two_factor_secret', true],
            'marca 2fa' => ['two_factor_confirmed_at', true],
            'codigos de recuperacion' => ['two_factor_recovery_codes', true],
            'remember_token' => ['remember_token', true],
            'api token' => ['api_token', true],
            'authorization' => ['Authorization', true],
            'cookie' => ['cookie_value', true],
            'otp exacto' => ['otp', true],
            'code exacto' => ['code', true],
            'nombre' => ['name', false],
            'estado' => ['status', false],
            'folio' => ['folio', false],
            'ip' => ['ip', false],
            'no borra fragmentos cortos ajenos' => ['footprint', false],
            'no borra barcode' => ['barcode', false],
            'coordinator_id' => ['coordinator_id', false],
        ];
    }

    #[DataProvider('keys')]
    public function test_sensitive_keys_are_detected(string $key, bool $expected): void
    {
        $this->assertSame($expected, AuditPayload::isSensitive($key));
    }

    public function test_redact_removes_sensitive_keys_at_any_depth_and_keeps_the_rest(): void
    {
        $clean = AuditPayload::redact([
            'name' => 'Ana',
            'password' => 'x',
            'nested' => ['token' => 'y', 'ok' => 1, 'deeper' => ['two_factor_secret' => 'z', 'keep' => true]],
            'list' => [['secret' => 'a', 'v' => 2]],
        ]);

        $this->assertSame([
            'name' => 'Ana',
            'nested' => ['ok' => 1, 'deeper' => ['keep' => true]],
            'list' => [['v' => 2]],
        ], $clean);
    }

    public function test_stringify_produces_bounded_plain_text(): void
    {
        $this->assertSame('—', AuditPayload::stringify(null));
        $this->assertSame('true', AuditPayload::stringify(true));
        $this->assertSame('false', AuditPayload::stringify(false));
        $this->assertSame('42', AuditPayload::stringify(42));
        $this->assertSame('{"a":"ñ"}', AuditPayload::stringify(['a' => 'ñ']));
        $this->assertLessThanOrEqual(303, mb_strlen(AuditPayload::stringify(str_repeat('x', 1000))));
    }
}
