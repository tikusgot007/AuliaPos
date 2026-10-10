<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL24: parse_no_order() must only accept numbers that format_no_order() can
 * actually produce. Before the fix "A10000" and "B0001" both resolved to internal
 * number 110000 (two different display formats mapped to the same order), "A0000"
 * mapped onto the plain legacy number 100000, and very long input overflowed
 * (TypeError / silently clamped).
 *
 * Pure functions; no database.
 *
 * @internal
 */
final class OrderHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('order');
    }

    /**
     * @return array<string, array{0:int}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'legacy plain 1'        => [1],
            'legacy plain 99999'    => [99999],
            'ambang itself'         => [100000],
            'first lettered'        => [100001],
            'inside cycle'          => [100018],
            'last of cycle A'       => [109999],
            'first of cycle B'      => [110000],
            'second of cycle B'     => [110001],
            'last of cycle B'       => [119998],
            'first of cycle C'      => [119999],
            'last of cycle Z'       => [100000 + 9999 * 26],
            'first of cycle AA'     => [100000 + 9999 * 26 + 1],
            'last of cycle ZZ'      => [100000 + 9999 * (26 + 26 * 26)],
            'first of cycle AAA'    => [100000 + 9999 * (26 + 26 * 26) + 1],
        ];
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testFormatThenParseReturnsTheSameNumber(int $noOrder): void
    {
        $this->assertSame($noOrder, parse_no_order(format_no_order($noOrder)));
    }

    public function testBoundaryOfCycleParsesToDifferentNumbers(): void
    {
        $this->assertSame(109999, parse_no_order('A9999'));
        $this->assertSame(110000, parse_no_order('B0001'));
    }

    public function testShortFormWithoutLeadingZerosIsStillAccepted(): void
    {
        $this->assertSame(100001, parse_no_order('A1'));
        $this->assertSame(parse_no_order('A0001'), parse_no_order('A1'));
        $this->assertSame(110000, parse_no_order('B1'));
    }

    public function testCaseAndSurroundingSpacesAreTolerated(): void
    {
        $this->assertSame(100018, parse_no_order(' a0018 '));
        $this->assertSame(100001, parse_no_order('a0001'));
    }

    public function testPlainLegacyNumbersAreReturnedAsIs(): void
    {
        $this->assertSame(123, parse_no_order('123'));
        $this->assertSame(123, parse_no_order('000123'));
        $this->assertSame(100000, parse_no_order('100000'));
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'cycle number zero'          => ['A0000'],
            'cycle number above cycle'   => ['A10000'],
            'five digit after letter'    => ['B12345'],
            'letters only'               => ['ABC'],
            'digits then letters'        => ['12abc'],
            'dash inside'                => ['A-1'],
            'space inside'               => ['A 1'],
            'empty'                      => [''],
            'too many letters'           => ['ZZZZ0001'],
            'absurdly long letters'      => ['ZZZZZZZZZZZZZZZZZZZZ0001'],
            'absurdly long digits'       => ['A99999999999999999999'],
            'plain number too long'      => ['9999999999999999999999'],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testInvalidInputIsRejectedWithoutError(string $input): void
    {
        $this->assertNull(parse_no_order($input));
    }
}
