<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

final class Audit
{
    public static function log(array $opts): void
    {
        try {
            Sheets::append(Sheets::sheetName('modlog'), [
                $opts['actionId']    ?? Ids::make('ACT'),
                $opts['timestamp']   ?? Time::nowIso(),
                $opts['reviewId']    ?? '',
                $opts['productName'] ?? '',
                $opts['productUrl']  ?? '',
                $opts['stars']       ?? 0,
                $opts['reviewText']  ?? '',
                $opts['reportCount'] ?? 0,
                $opts['ruleOrRestore'] ?? '',
                $opts['actionType']  ?? 'other',
                $opts['actor']       ?? 'system',
                $opts['reason']      ?? '',
                $opts['relatedId']   ?? '',
                $opts['contentHash'] ?? '',
            ]);
        } catch (\Throwable $e) {
            error_log('[Audit] ' . $e->getMessage());
        }
    }

    /**
     * Read the full log, newest first.
     */
    public static function all(int $limit = 500): array
    {
        $sheet = Sheets::sheetName('modlog');
        $data = Sheets::read($sheet, 'A2:N');
        $rows = [];
        foreach ($data as $r) {
            if (empty($r[0])) continue;
            $rows[] = self::mapRow($r);
        }
        return array_slice(array_reverse($rows), 0, $limit);
    }

    public static function forReview(string $reviewId): array
    {
        $sheet = Sheets::sheetName('modlog');
        $data = Sheets::read($sheet, 'A2:N');
        $rows = [];
        foreach ($data as $r) {
            if (($r[2] ?? '') !== $reviewId) continue;
            $rows[] = [
                'actionId'    => (string)($r[0] ?? ''),
                'timestamp'   => Time::iso($r[1] ?? ''),
                'actionType'  => (string)($r[9] ?? 'other'),
                'actor'       => (string)($r[10] ?? 'system'),
                'reason'      => (string)($r[11] ?? ''),
                'relatedId'   => (string)($r[12] ?? ''),
                'contentHash' => (string)($r[13] ?? ''),
            ];
        }
        return $rows;
    }

    private static function mapRow(array $r): array
    {
        $actionId = (string)($r[0] ?? '');
        $actionType = (string)($r[9] ?? '');
        if ($actionType === '') {
            $actionType = str_starts_with($actionId, 'FLAG-') ? 'flagged_and_archived'
                : (str_starts_with($actionId, 'ACT-') ? 'deleted' : 'other');
        }
        return [
            'actionId'        => $actionId,
            'timestamp'       => Time::iso($r[1] ?? ''),
            'deletedReviewId' => (string)($r[2] ?? ''),
            'reviewId'        => (string)($r[2] ?? ''),
            'productName'     => (string)($r[3] ?? ''),
            'productUrl'      => (string)($r[4] ?? ''),
            'stars'           => (int)($r[5] ?? 0),
            'reportCount'     => (int)($r[7] ?? 0),
            'ruleBroken'      => (string)($r[8] ?? ''),
            'actionType'      => $actionType,
            'actor'           => (string)($r[10] ?? 'system'),
            'reason'          => (string)($r[11] ?? ''),
            'relatedId'       => (string)($r[12] ?? ''),
            'contentHash'     => (string)($r[13] ?? ''),
        ];
    }

    public static function summary(): array
    {
        $totals = [
            'published' => 0,
            'archived' => 0,
            'rescued' => 0,
            'deleted' => 0,
            'restored' => 0,
            'flagged_and_archived' => 0,
            'flag_received' => 0,
            'grievance_received' => 0,
            'grievance_acknowledged' => 0,
            'grievance_resolved' => 0,
            'reply_published' => 0,
        ];
        $byActor = ['system' => 0, 'community' => 0, 'admin' => 0, 'public' => 0];

        $data = Sheets::read(Sheets::sheetName('modlog'), 'A2:N');
        foreach ($data as $r) {
            if (empty($r[0])) continue;
            $t = (string)($r[9] ?? 'other');
            if (isset($totals[$t])) $totals[$t]++;
            $a = (string)($r[10] ?? 'system');
            if (isset($byActor[$a])) $byActor[$a]++;
        }
        return ['totals' => $totals, 'byActor' => $byActor];
    }
}
