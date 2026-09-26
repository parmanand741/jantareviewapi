<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Legacy\Backup;
use App\Libraries\Legacy\Sheets;
use CodeIgniter\Test\CIUnitTestCase;

final class BackupTest extends CIUnitTestCase
{
    public function testCsvRoundTripsAwkwardValues(): void
    {
        $rows = [
            ['id', 'text', 'count'],
            ['REV-1', 'He said "no", then left', '2'],
            ['REV-2', "two\nlines", '0'],
            ['REV-3', '=IMPORTXML("x"&JOIN(",",A1:A9))', '5'],
            ['REV-4', 'बैटरी अच्छी', ''],
            ['REV-5', 'comma, separated', '1,7'],
        ];

        $this->assertSame($rows, Backup::fromCsv(Backup::toCsv($rows)));
    }

    public function testEmptyStoreExportsToEmptyCsv(): void
    {
        $this->assertSame('', Backup::toCsv([]));
        $this->assertSame([], Backup::fromCsv(''));
    }

    /** @dataProvider columnCases */
    public function testColumnLetters(string $expected, int $index): void
    {
        $this->assertSame($expected, Sheets::columnLetter($index));
    }

    public static function columnCases(): array
    {
        return [
            ['A', 1],
            ['Z', 26],
            ['AA', 27],
            ['AZ', 52],
            ['BA', 53],
            ['ZZ', 702],
            ['AAA', 703],
            ['U', 21],
        ];
    }
}
