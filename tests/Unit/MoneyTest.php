<?php

namespace Tests\Unit;

use App\Support\Dates\NumberFormatter;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Money\Rounding;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * REQ-0165, REQ-0488 — amounts are integers in halalas with a dedicated money library; no decimals in any calculation.
 * REQ-0502 — mandatory automated tests for every financial calculation (edge and negative cases).
 */
class MoneyTest extends TestCase
{
    public function test_req_0165_amounts_are_integer_halalas(): void
    {
        $m = Money::parse('1250.50');
        $this->assertSame(125050, $m->minor());
        $this->assertSame('1250.50', $m->toDecimalString());
        $this->assertSame(5, Money::parse('0.05')->minor());
        $this->assertSame(-350, Money::parse('-3.5')->minor());
        $this->assertSame(123400, Money::parse('١٢٣٤٫٠٠')->minor());
    }

    public function test_req_0488_classic_float_errors_do_not_happen(): void
    {
        // 0.1 + 0.2 != 0.3 in floating point — with halalas it is exact.
        $this->assertTrue(Money::parse('0.10')->plus(Money::parse('0.20'))->equals(Money::parse('0.30')));
        $sum = Money::zero();
        for ($i = 0; $i < 1000; $i++) {
            $sum = $sum->plus(Money::parse('0.01'));
        }
        $this->assertSame(1000, $sum->minor());
    }

    public function test_req_0488_parsing_rejects_more_than_two_decimals_and_garbage(): void
    {
        foreach (['1.005', 'abc', '', '1e3', '1.2.3'] as $bad) {
            try {
                Money::parse($bad);
                $this->fail("accepted $bad");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_req_0488_percentage_uses_basis_points_and_explicit_rounding(): void
    {
        // 15% VAT on 100.01 = 15.0015 → 15.00 (half-up)
        $this->assertSame(1500, Money::parse('100.01')->percentage(1500)->minor());
        // 15% of 0.10 = 0.015 → 0.02 half-up, 0.01 half-even/down
        $this->assertSame(2, Money::of(10)->percentage(1500, Rounding::HalfUp)->minor());
        $this->assertSame(2, Money::of(10)->percentage(1500, Rounding::HalfEven)->minor());
        $this->assertSame(1, Money::of(10)->percentage(1500, Rounding::Down)->minor());
        $this->assertSame(-2, Money::of(-10)->percentage(1500, Rounding::HalfUp)->minor());
        $this->assertSame(-2, Money::of(-10)->percentage(1500, Rounding::Down)->minor());
        $this->assertSame(-1, Money::of(-10)->percentage(1500, Rounding::TowardZero)->minor());
    }

    public function test_req_0488_rounding_modes_on_exact_halves(): void
    {
        $this->assertSame(3, Money::divide(5, 2, Rounding::HalfUp));
        $this->assertSame(2, Money::divide(5, 2, Rounding::HalfEven));
        $this->assertSame(4, Money::divide(7, 2, Rounding::HalfEven));
        $this->assertSame(-3, Money::divide(-5, 2, Rounding::HalfUp));
        $this->assertSame(-2, Money::divide(-5, 2, Rounding::HalfEven));
        $this->assertSame(3, Money::divide(5, 2, Rounding::Up));
        $this->assertSame(-2, Money::divide(-5, 2, Rounding::Up));
        $this->assertSame(-3, Money::divide(-5, 2, Rounding::Down));
    }

    public function test_req_0502_allocation_never_loses_or_creates_a_halala(): void
    {
        $parts = Money::parse('100.00')->split(3);
        $this->assertSame([3334, 3333, 3333], array_map(fn ($m) => $m->minor(), $parts));
        $this->assertSame(10000, Money::sum($parts)->minor());

        // Heirs' shares 1/2, 1/4, 1/8, 1/8 of 999.99
        $shares = Money::parse('999.99')->allocate([4, 2, 1, 1]);
        $this->assertSame(99999, Money::sum($shares)->minor());
        $this->assertSame([49999, 25000, 12500, 12500], array_map(fn ($m) => $m->minor(), $shares));

        $neg = Money::of(-100)->split(3);
        $this->assertSame(-100, Money::sum($neg)->minor());

        $this->assertSame([0, 7], array_map(fn ($m) => $m->minor(), Money::of(7)->allocate([0, 1])));
    }

    public function test_req_0502_negative_and_invalid_allocations_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100)->allocate([1, -1]);
    }

    public function test_req_0165_currencies_never_mix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'SAR')->plus(Money::of(100, 'AED'));
    }

    public function test_req_0165_overflow_is_detected(): void
    {
        $this->expectException(\OverflowException::class);
        Money::of(PHP_INT_MAX)->plus(Money::of(1));
    }

    public function test_req_0488_cast_rejects_floats(): void
    {
        $cast = new MoneyCast();
        $this->assertSame(125050, $cast->set(null, 'amount', Money::parse('1250.50'), []));
        $this->assertSame(10, $cast->set(null, 'amount', 10, []));
        $this->expectException(InvalidArgumentException::class);
        $cast->set(null, 'amount', 12.5, []);
    }

    /** REQ-0363, REQ-0364 — digits and currency display. */
    public function test_req_0364_amount_display_with_digits_and_currency(): void
    {
        $m = Money::parse('1250.50');
        $this->assertSame('1,250.50 SAR', NumberFormatter::amount($m));
        $this->assertSame('1,250.50 ر.س', NumberFormatter::amount($m, 'western', 'abbreviation'));
        // The official Saudi riyal sign U+20C1 — not U+FDFC "﷼", which renders the word «ریال».
        $this->assertSame("1,250.50 \u{20C1}", NumberFormatter::amount($m, 'western', 'symbol'));
        $this->assertStringNotContainsString("\u{FDFC}", NumberFormatter::amount($m, 'western', 'symbol'));
        $this->assertSame('١٬٢٥٠٫٥٠ ر.س', NumberFormatter::amount($m, 'eastern', 'abbreviation'));
        // A negative number is isolated so the minus stays at its start inside Arabic text; the sign stays to its left.
        $this->assertSame("\u{2066}-0.05\u{2069} SAR", NumberFormatter::amount(Money::of(-5)));
        $this->assertSame('1,000,000.00 SAR', NumberFormatter::amount(Money::parse('1000000')));
    }

    /** REQ-0364 — the platform ships the official sign's glyph and loads it for U+20C1 only, last in the font sheet. */
    public function test_req_0364_the_riyal_sign_glyph_is_shipped_and_mapped(): void
    {
        $root = dirname(__DIR__, 2);   // plain PHPUnit test: no Laravel helpers
        $ttf = (string) file_get_contents("$root/public/fonts/saudi-riyal.ttf");
        $this->assertSame("\x00\x01\x00\x00", substr($ttf, 0, 4), 'TrueType header');
        $tables = [];
        $n = unpack('n', substr($ttf, 4, 2))[1];
        for ($i = 0; $i < $n; $i++) {
            $rec = substr($ttf, 12 + 16 * $i, 16);
            $tables[substr($rec, 0, 4)] = unpack('Noff/Nlen', substr($rec, 8, 8));
        }
        foreach (['cmap', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'name', 'OS/2', 'post'] as $t) {
            $this->assertArrayHasKey($t, $tables, $t);
        }
        $cmap = substr($ttf, $tables['cmap']['off'], $tables['cmap']['len']);
        $this->assertStringContainsString(pack('n2', 0x20C1, 0xFFFF), $cmap, 'format-4 segment for U+20C1');
        $this->assertGreaterThan(100, $tables['glyf']['len'], 'the sign has an outline');

        $css = (string) file_get_contents("$root/public/fonts/fonts.css");
        $last = substr($css, strrpos($css, '@font-face', -1) - 400);
        $this->assertStringContainsString("src: url('saudi-riyal.ttf')", $last);
        $this->assertStringContainsString('unicode-range: U+20C1;', $last);
        foreach (['Tajawal', 'Cairo'] as $family) {
            $this->assertMatchesRegularExpression("/font-family: '$family';[^}]*saudi-riyal\.ttf[^}]*U\+20C1;/", $css, $family);
        }
    }

    /** REQ-0363 — digits conversion both ways. */
    public function test_req_0363_digits_conversion(): void
    {
        $this->assertSame('١٤٤٨', NumberFormatter::digits('1448', 'eastern'));
        $this->assertSame('1448', NumberFormatter::digits('١٤٤٨', 'western'));
    }
}
