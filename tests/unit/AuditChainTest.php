<?php

declare(strict_types=1);

namespace Tests\unit;

use App\Libraries\Legacy\Audit;
use PHPUnit\Framework\TestCase;

/**
 * The properties the nightly checkpoint depends on: a digest over the first n
 * rows must not move when rows are appended, and must move when any single
 * field of an older row changes.
 */
final class AuditChainTest extends TestCase
{
    private function row(string $id, string $actionType, string $reason = ''): array
    {
        $row = array_fill(0, 14, '');
        $row[0] = $id;
        $row[1] = '2026-09-26T00:00:00+05:30';
        $row[2] = 'REV-1';
        $row[9] = $actionType;
        $row[10] = 'admin';
        $row[11] = $reason;
        return $row;
    }

    public function testEmptyLogHasNoDigest(): void
    {
        $this->assertSame('', Audit::prefixDigests([])[0]);
    }

    public function testAppendsLeaveEarlierPrefixesUntouched(): void
    {
        $log = [$this->row('ACT-1', 'deleted'), $this->row('ACT-2', 'restored')];
        $before = Audit::prefixDigests($log);

        $log[] = $this->row('ACT-3', 'flag_received');
        $after = Audit::prefixDigests($log);

        $this->assertSame($before[1], $after[1]);
        $this->assertSame($before[2], $after[2]);
        $this->assertNotSame($after[2], $after[3]);
    }

    public function testEditingAnyFieldChangesTheDigest(): void
    {
        $log = [$this->row('ACT-1', 'deleted', 'rule 1'), $this->row('ACT-2', 'restored', 'rule 2')];
        $clean = Audit::prefixDigests($log);

        foreach ([0 => 'ACT-X', 1 => '2020-01-01T00:00:00+05:30', 9 => 'other', 10 => 'public', 11 => 'quietly rewritten'] as $column => $value) {
            $tampered = $log;
            $tampered[1][$column] = $value;
            $digests = Audit::prefixDigests($tampered);

            $this->assertSame($clean[1], $digests[1], 'row 1 must be unaffected by a change in row 2');
            $this->assertNotSame($clean[2], $digests[2], "column {$column} is not covered by the digest");
        }
    }

    public function testReorderingRowsChangesTheDigest(): void
    {
        $log = [$this->row('ACT-1', 'deleted'), $this->row('ACT-2', 'restored')];
        $swapped = array_reverse($log);

        $this->assertNotSame(
            Audit::prefixDigests($log)[2],
            Audit::prefixDigests($swapped)[2]
        );
    }

    public function testTrailingEmptyCellsDoNotShiftTheDigest(): void
    {
        // Sheets omits trailing empty cells, so the same row can come back with
        // 3 or 14 columns. Position must decide the digest, not length.
        $short = Audit::prefixDigests([['ACT-1', '2026-09-26T00:00:00+05:30', 'REV-1']]);
        $padded = Audit::prefixDigests([[...['ACT-1', '2026-09-26T00:00:00+05:30', 'REV-1'], ...array_fill(0, 11, '')]]);

        $this->assertSame($short[1], $padded[1]);
    }
}
