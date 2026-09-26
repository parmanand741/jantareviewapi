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
                '',                  // G: retired — plaintext review text never returns here
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
            $rows[] = self::mapRow($r);
        }
        return $rows;
    }

    /**
     * What the public endpoints may publish: a whitelisted projection, never
     * the stored row. Anything a person typed into an admin box — a grievance
     * resolution note, a case reference — stays behind Admin::require().
     *
     * @return array<int, array<string, string>>
     */
    public static function publicAll(int $limit = 500): array
    {
        return array_map([self::class, 'publicRow'], self::all($limit));
    }

    /** @return array<int, array<string, string>> */
    public static function publicForReview(string $reviewId): array
    {
        return array_map([self::class, 'publicRow'], self::forReview($reviewId));
    }

    /** @param array<string, mixed> $row A row from all() or forReview(). */
    public static function publicRow(array $row): array
    {
        $actionId = (string) ($row['actionId'] ?? '');
        $reviewId = (string) ($row['reviewId'] ?? '');

        return [
            'actionId'     => $actionId,
            'timestamp'    => (string) ($row['timestamp'] ?? ''),
            'reviewId'     => $reviewId,
            'productName'  => (string) ($row['productName'] ?? ''),
            'actionType'   => (string) ($row['actionType'] ?? 'other'),
            'actor'        => (string) ($row['actor'] ?? 'system'),
            'reason'       => self::publicReason((string) ($row['reason'] ?? '')),
            'integrityRef' => self::integrityRef($actionId, $reviewId, (string) ($row['contentHash'] ?? '')),
        ];
    }

    private static function publicReason(string $reason): string
    {
        // Rows logged before resolution notes moved out of the audit trail.
        $redacted = preg_replace('~^Case\s+(\S+)\s+(?:—|-)\s+.*$~u', 'Case $1 resolved', trim($reason));

        return mb_substr(is_string($redacted) ? $redacted : '', 0, 200);
    }

    /**
     * A stable public reference for the row's content. The stored hash is an
     * unkeyed digest of the review text, so publishing it lets anyone confirm
     * what a removed review said; this cannot be tested offline.
     */
    private static function integrityRef(string $actionId, string $reviewId, string $contentHash): string
    {
        if ($actionId === '' && $reviewId === '' && $contentHash === '') return '';

        return substr(hash_hmac(
            'sha256',
            'ref|' . $actionId . '|' . $reviewId . '|' . $contentHash,
            (string) Config::get('encryption_key')
        ), 0, 12);
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

    /**
     * Fold the log into a rolling digest and record it, so that rewriting an
     * old row shows up as a mismatch against the previous checkpoint. Done in
     * one pass at night rather than per append: a per-row chain would need a
     * read for every moderation event, and the Sheets quota is shared by the
     * whole site.
     *
    /**
     * @param bool $dryRun Compute and compare, record nothing. The first run
     *                     against live data should always be this one.
     * @return array{kind: string, rowCount: int, digest: string, note: string}
     */
    public static function checkpoint(bool $dryRun = false): array
    {
        $sheet = Sheets::sheetName('modlog');
        $rows = array_values(array_filter(
            Sheets::read($sheet, 'A2:N'),
            static fn(array $r): bool => (string) ($r[0] ?? '') !== ''
        ));
        $prefix = self::prefixDigests($rows);
        $count = count($rows);
        $head = $prefix[$count] ?? '';

        $checks = self::checkpoints();
        $previous = $checks[0] ?? null;
        $kind = 'checkpoint';
        $note = '';

        // A purge shifts every row up, so the chain legitimately restarts — but
        // only when the log itself records that purge.
        $purged = self::purgedAfter($rows, (string) ($previous['checkedAt'] ?? ''));

        if ($previous === null) {
            $kind = 'genesis';
            $note = 'First checkpoint for this log.';
        } elseif ($count < (int) $previous['rowCount'] || ($prefix[(int) $previous['rowCount']] ?? '') !== (string) ($previous['digest'] ?? '')) {
            $kind = $purged ? 'genesis' : 'break';
            $note = $kind === 'genesis'
                ? 'Rows were purged under the retention policy; a new chain starts here.'
                : 'The log no longer matches the digest recorded at row ' . $previous['rowCount'] . '.';
        }

        $record = [
            'kind' => $kind,
            'rowCount' => $count,
            'digest' => $head,
            'note' => $note,
        ];

        $checkSheet = Sheets::sheetName('integrityChecks');
        if (!$dryRun) {
            Sheets::ensureSheet($checkSheet, ['checkedAt', 'kind', 'rowCount', 'digest', 'note']);
            Sheets::append($checkSheet, [Time::nowIso(), $kind, $count, $head, $note]);
        }

        if ($kind === 'break') {
            log_message('error', 'Moderation log integrity check failed: {note}', ['note' => $note]);
        }

        return $record;
    }

    /**
     * What the public transparency page may honestly claim about the log.
     *
     * @return array{verifiedAt: string, rowCount: int, status: string}|null
     */
    public static function integrity(): ?array
    {
        $latest = self::checkpoints()[0] ?? null;
        if ($latest === null) {
            return null;
        }
        return [
            'verifiedAt' => Time::iso((string) ($latest['checkedAt'] ?? '')),
            'rowCount' => (int) ($latest['rowCount'] ?? 0),
            'status' => (string) ($latest['kind'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> Newest first. */
    private static function checkpoints(): array
    {
        $rows = Sheets::read(Sheets::sheetName('integrityChecks'), 'A2:E');
        $out = [];
        foreach (array_reverse($rows) as $r) {
            if ((string) ($r[0] ?? '') === '') continue;
            $out[] = [
                'checkedAt' => (string) ($r[0] ?? ''),
                'kind' => (string) ($r[1] ?? ''),
                'rowCount' => (int) ($r[2] ?? 0),
                'digest' => (string) ($r[3] ?? ''),
                'note' => (string) ($r[4] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Digest of every prefix of the log: p[n] covers the first n rows. Only a
     * chain that reaches forward over unchanged history can tell an appended
     * row apart from an edited one.
     *
     * @param array<int, array<int, mixed>> $rows
     * @return array<int, string>
     */
    public static function prefixDigests(array $rows): array
    {
        $key = (string) Config::get('encryption_key');
        $prefix = [0 => ''];
        $running = '';
        foreach ($rows as $i => $row) {
            $cells = array_pad(array_map(static fn($v): string => (string) $v, array_slice($row, 0, 14)), 14, '');
            $rowDigest = hash('sha256', implode("\x1f", $cells));
            $running = hash_hmac('sha256', $running . '|' . $rowDigest, $key);
            $prefix[$i + 1] = $running;
        }
        return $prefix;
    }

    /**
     * @param array<int, array<int, mixed>> $rows The log, oldest first.
     */
    private static function purgedAfter(array $rows, string $sinceIso): bool
    {
        $since = $sinceIso === '' ? 0 : strtotime(Time::iso($sinceIso));
        if ($since === false) $since = 0;
        foreach ($rows as $row) {
            if ((string) ($row[9] ?? '') !== 'retention_purged') continue;
            $at = strtotime(Time::iso((string) ($row[1] ?? '')));
            if ($at !== false && $at > $since) return true;
        }
        return false;
    }
}
