<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SpanishDateTime;
use Tests\TestCase;

class SpanishDateTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'America/Cancun']);
    }

    public function test_parses_email_client_dates_in_spanish(): void
    {
        $this->assertSame('2026-08-11 18:11:00', SpanishDateTime::parse('martes 11 de agosto 2026, 1:11 p. m.')?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-06 15:09:00', SpanishDateTime::parse('viernes 6 de noviembre 2026, 10:09 a. m.')?->format('Y-m-d H:i:s'));
        // 12:30 a. m. es media noche; 12:30 p. m. es mediodía.
        $this->assertSame('2026-09-01 05:30:00', SpanishDateTime::parse('martes 1 de septiembre 2026, 12:30 a. m.')?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 17:30:00', SpanishDateTime::parse('martes 1 de septiembre 2026, 12:30 p. m.')?->format('Y-m-d H:i:s'));
    }

    public function test_parses_common_numeric_formats(): void
    {
        $this->assertSame('2026-10-05 05:00:00', SpanishDateTime::parse('05/10/2026')?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 19:30:00', SpanishDateTime::parse('2026-10-05 14:30')?->format('Y-m-d H:i:s'));
    }

    public function test_returns_null_for_anything_else(): void
    {
        foreach (['', '   ', 'NEREYDA', 'martes 11 de foo 2026, 1:11 p. m.', '31/31/2026', str_repeat('a', 200)] as $text) {
            $this->assertNull(SpanishDateTime::parse($text), $text);
        }
    }
}
