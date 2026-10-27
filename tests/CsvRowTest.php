<?php

/**
 * The client list's CSV: every text cell guarded against spreadsheet formulas, negative numbers left alone.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Tests;

use OpenHealthcare\CcbhcReport\Report\CsvRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvRowTest extends TestCase
{
    /** @return iterable<string, array{int|string, int|string}> */
    public static function cells(): iterable
    {
        yield 'formula' => ['=HYPERLINK("x")', '\'=HYPERLINK("x")'];
        yield 'plus' => ['+1', "'+1"];
        yield 'minus text' => ['-Smith', "'-Smith"];
        yield 'at' => ['@SUM(1)', "'@SUM(1)"];
        yield 'negative number' => ['-12', '-12'];
        yield 'negative decimal' => ['-12.5', '-12.5'];
        yield 'leading space' => [' =x', ' =x'];
        yield 'name' => ['O\'Neil, Pat', 'O\'Neil, Pat'];
        yield 'empty' => ['', ''];
        yield 'integer' => [-3, -3];
    }

    #[DataProvider('cells')]
    public function testACellIsGuardedOnlyWhenASpreadsheetWouldReadAFormula(int|string $in, int|string $out): void
    {
        self::assertSame($out, CsvRow::cell($in));
    }

    public function testEveryColumnIsGuardedNotOnlyTheChartNumberAndName(): void
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        CsvRow::write($out, ['-12', '=cmd', 'contact', '2025-05-10', 'no', '', '+why', '18_plus', '@payer', 'unknown', 'white']);
        rewind($out);
        self::assertSame("-12,'=cmd,contact,2025-05-10,no,,'+why,18_plus,'@payer,unknown,white\n", stream_get_contents($out));
    }
}
