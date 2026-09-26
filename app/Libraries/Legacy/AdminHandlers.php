<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

final class AdminHandlers
{
    public static function getDashboard(Request $req): never
    {
        Admin::require();

        $reviews = [];
        foreach (Sheets::read(Sheets::sheetName('reviews'), 'A2:U') as $r) {
            if (empty($r[0])) continue;
            $host = parse_url((string)($r[2] ?? ''), PHP_URL_HOST) ?: '';
            $rc = (int)($r[10] ?? 0);
            $reviews[] = [
                'id' => (string)$r[0],
                'timestamp' => Time::iso($r[1] ?? ''),
                'productUrl' => (string)($r[2] ?? ''),
                'imageUrl' => (string)($r[3] ?? ''),
                'stars' => (int)($r[4] ?? 0),
                'reviewText' => Crypto::decrypt((string)($r[5] ?? '')),
                'helpful' => (int)($r[6] ?? 0),
                'notHelpful' => (int)($r[7] ?? 0),
                'netScore' => (int)($r[6] ?? 0) - (int)($r[7] ?? 0),
                'status' => Validator::status($r[9] ?? ''),
                'reportCount' => $rc,
                'flagged' => $rc >= (int) Config::get('report_ceiling'),
                'productName' => (string)($r[11] ?? ''),
                'store' => ProductLink::storeFor($host),
                'parentId' => (string)($r[13] ?? ''),
                'isReply' => !empty($r[13]),
                'contentHash' => (string)($r[14] ?? ''),
                'platform' => (string)($r[17] ?? ''),
                'archiveCycles' => (int)($r[18] ?? 0),
                'flags' => array_values(array_filter(explode(',', (string)($r[20] ?? '')))),
            ];
        }

        $deleted = [];
        foreach (Sheets::read(Sheets::sheetName('deleted'), 'A2:R') as $r) {
            if (empty($r[0]) || !empty($r[13])) continue;
            $host = parse_url((string)($r[2] ?? ''), PHP_URL_HOST) ?: '';
            $deleted[] = [
                'id' => (string)$r[0],
                'timestamp' => Time::iso($r[1] ?? ''),
                'productUrl' => (string)($r[2] ?? ''),
                'imageUrl' => (string)($r[3] ?? ''),
                'stars' => (int)($r[4] ?? 0),
                'reviewText' => Crypto::decrypt((string)($r[5] ?? '')),
                'helpful' => (int)($r[6] ?? 0),
                'notHelpful' => (int)($r[7] ?? 0),
                'netScore' => (int)($r[8] ?? 0),
                'status' => Validator::status($r[9] ?? ''),
                'reportCount' => (int)($r[10] ?? 0),
                'productName' => (string)($r[11] ?? ''),
                'deletedAt' => Time::iso($r[12] ?? ''),
                'restoredAt' => Time::iso($r[13] ?? ''),
                'isRestored' => !empty($r[13]),
                'store' => ProductLink::storeFor($host),
                'parentId' => (string)($r[14] ?? ''),
                'isReply' => !empty($r[14]),
                'deletionReason' => (string)($r[15] ?? ''),
                'relatedCaseId' => (string)($r[16] ?? ''),
            ];
        }

        Response::ok([
            'reviews' => array_reverse($reviews),
            'deleted' => array_reverse($deleted),
            'log' => Audit::all(500),
        ]);
    }

    public static function deleteReview(Request $req): never
    {
        Admin::requireKey($req->payload['adminKey'] ?? null);

        $reviewId  = Validator::sanitize($req->payload['reviewId'] ?? '', 20);
        $rule      = Validator::sanitize($req->payload['ruleBroken'] ?? 'Legal Violation', 100);
        $caseId    = Validator::sanitize($req->payload['relatedCaseId'] ?? '', 20);
        if ($reviewId === '') Response::error('Missing reviewId.');

        $revSheet = Sheets::sheetName('reviews');
        $delSheet = Sheets::sheetName('deleted');
        $all      = Sheets::read($revSheet, 'A2:U');

        // Identify target + (if top-level) its replies
        $target = null;
        foreach ($all as $r) if (($r[0] ?? '') === $reviewId) {
            $target = $r;
            break;
        }
        if (!$target) Response::error('Review not found.');

        $isTopLevel = empty($target[13]);
        $toDelete = [];
        $doomed = [];
        foreach ($all as $i => $r) {
            if (empty($r[0])) continue;
            $isTarget = ($r[0] === $reviewId);
            $isChild  = $isTopLevel && ((string)($r[13] ?? '') === $reviewId);
            if ($isTarget || $isChild) {
                $toDelete[] = $r;
                $doomed[] = $i + 2;
            }
        }

        $now = Time::nowIso();

        // Append to Deleted_Reviews (A..Q)
        $rows = [];
        foreach ($toDelete as $r) {
            $h = (int)($r[6] ?? 0);
            $nh = (int)($r[7] ?? 0);
            $rows[] = [
                (string)($r[0] ?? ''),
                Time::iso($r[1] ?? '') ?: $now,
                (string)($r[2] ?? ''),
                (string)($r[3] ?? ''),
                (int)($r[4] ?? 0),
                (string)($r[5] ?? ''),
                $h,
                $nh,
                $h - $nh,
                Validator::status($r[9] ?? ''),
                (int)($r[10] ?? 0),
                (string)($r[11] ?? ''),
                $now,
                '',
                (string)($r[13] ?? ''),
                $rule,
                $caseId,
                (string)($r[17] ?? ''),
            ];
        }
        foreach ($rows as $row) {
            Sheets::append($delSheet, $row);
        }

        // Remove the archived rows where they stand. Clearing the tab and
        // rewriting the survivors lost every review if the second call failed,
        // and deleteDimension is one request for the whole set.
        Sheets::deleteRows($revSheet, $doomed);

        // Audit
        foreach ($toDelete as $r) {
            Audit::log([
                'reviewId'    => (string)($r[0] ?? ''),
                'productName' => (string)($r[11] ?? ''),
                'productUrl'  => (string)($r[2] ?? ''),
                'stars'       => (int)($r[4] ?? 0),
                'reportCount' => (int)($r[10] ?? 0),
                'ruleOrRestore' => $rule,
                'actionType'  => 'deleted',
                'actor'       => 'admin',
                'reason'      => $rule . ($caseId ? " (Case {$caseId})" : ''),
                'relatedId'   => $caseId,
                'contentHash' => (string)($r[14] ?? ''),
            ]);
        }

        $replyCount = count($toDelete) - 1;
        Response::ok([
            'message' => $isTopLevel && $replyCount > 0
                ? "Review and {$replyCount} " . ($replyCount === 1 ? 'reply' : 'replies') . " moved to Deleted_Reviews."
                : 'Entry moved to Deleted_Reviews and logged.',
            'retentionDays' => (int) Config::get('retention_days'),
        ]);
    }

    public static function restoreReview(Request $req): never
    {
        Admin::requireKey($req->payload['adminKey'] ?? null);
        $reviewId = Validator::sanitize($req->payload['reviewId'] ?? '', 20);
        if ($reviewId === '') Response::error('Missing reviewId.');

        $sheet = Sheets::sheetName('reviews');
        $data  = Sheets::read($sheet, 'A2:P');
        $idx = null;
        foreach ($data as $i => $r) if (($r[0] ?? '') === $reviewId) {
            $idx = $i;
            break;
        }
        if ($idx === null) Response::error('Review not found.');

        $rowNum = $idx + 2;
        Sheets::batchWrite($sheet, [
            ['range' => "J{$rowNum}", 'values' => [['published']]],
            ['range' => "K{$rowNum}", 'values' => [[0]]],
        ]);

        $row = $data[$idx];
        Audit::log([
            'reviewId' => $reviewId,
            'productName' => (string)($row[11] ?? ''),
            'productUrl' => (string)($row[2] ?? ''),
            'stars' => (int)($row[4] ?? 0),
            'actionType' => 'restored',
            'actor' => 'admin',
            'reason' => 'Manual restore and un-flag',
            'contentHash' => (string)($row[14] ?? ''),
        ]);

        Response::ok(['message' => 'Restored and un-flagged.']);
    }

    public static function restoreFromDeleted(Request $req): never
    {
        Admin::requireKey($req->payload['adminKey'] ?? null);
        $reviewId = Validator::sanitize($req->payload['reviewId'] ?? '', 20);
        if ($reviewId === '') Response::error('Missing reviewId.');

        $delSheet = Sheets::sheetName('deleted');
        $revSheet = Sheets::sheetName('reviews');
        $data = Sheets::read($delSheet, 'A2:R');

        $idx = null;
        $row = null;
        foreach ($data as $i => $r) if (($r[0] ?? '') === $reviewId) {
            $idx = $i;
            $row = $r;
            break;
        }
        if ($idx === null) Response::error('Deleted entry not found.');
        if (!empty($row[13])) Response::error('This entry has already been restored.');

        $parentId = (string)($row[14] ?? '');

        if ($parentId !== '') {
            $revData = Sheets::read($revSheet, 'A2:A');
            $exists = false;
            foreach ($revData as $r) if (($r[0] ?? '') === $parentId) {
                $exists = true;
                break;
            }
            if (!$exists) Response::error('Cannot restore reply — its parent is not in the live feed.');
        }

        // Add back to Reviews. Deleted rows use their own column order, so this
        // rebuilds the live layout position by position: contentHash and the
        // submitter identifier are not carried into Deleted_Reviews and so
        // cannot survive a restore.
        $newRow = [
            (string)($row[0] ?? ''),
            Time::iso($row[1] ?? '') ?: Time::nowIso(),
            (string)($row[2] ?? ''),
            (string)($row[3] ?? ''),
            (int)($row[4] ?? 0),
            (string)($row[5] ?? ''),
            (int)($row[6] ?? 0),
            (int)($row[7] ?? 0),
            (int)($row[8] ?? 0),
            'published',
            0,
            (string)($row[11] ?? ''),
            '',
            $parentId,
            '',
            '',
            '',
            (string)($row[17] ?? ''),
            0,
            '',   // T: the hash of a restored entry's text is not recoverable
            '',   // U: nor were its submission-time flags
        ];
        Sheets::append($revSheet, $newRow);

        // Mark restored
        $rowNum = $idx + 2;
        Sheets::setCell($delSheet, "N{$rowNum}", Time::nowIso());

        Audit::log([
            'reviewId' => (string)($row[0] ?? ''),
            'productName' => (string)($row[11] ?? ''),
            'productUrl' => (string)($row[2] ?? ''),
            'stars' => (int)($row[4] ?? 0),
            'actionType' => 'restored',
            'actor' => 'admin',
            'reason' => 'Restored from Deleted_Reviews',
            'relatedId' => (string)($row[16] ?? ''),
        ]);

        Response::ok(['message' => $parentId !== '' ? 'Reply restored.' : 'Review restored.']);
    }

    public static function listGrievances(Request $req): never
    {
        Admin::require();
        $rows = Sheets::read(Sheets::sheetName('grievances'), 'A2:J');
        $out = [];
        foreach ($rows as $r) {
            if (empty($r[0])) continue;
            $out[] = [
                'caseId' => (string)$r[0],
                'filedAt' => Time::iso($r[1] ?? ''),
                'acknowledgedAt' => Time::iso($r[2] ?? ''),
                'resolvedAt' => Time::iso($r[3] ?? ''),
                'reviewId' => (string)($r[4] ?? ''),
                'category' => (string)($r[5] ?? ''),
                'description' => (string)($r[6] ?? ''),
                'contactEmail' => Crypto::decrypt((string)($r[7] ?? '')),
                'status' => (string)($r[8] ?? 'received'),
                'resolutionNote' => (string)($r[9] ?? ''),
                'anonymous' => false,
            ];
        }
        foreach (Sheets::read(Sheets::sheetName('anonymousGrievances'), 'A2:I') as $r) {
            if (empty($r[0])) continue;
            $out[] = [
                'caseId' => (string) $r[0],
                'filedAt' => Time::iso($r[1] ?? ''),
                'acknowledgedAt' => Time::iso($r[2] ?? ''),
                'resolvedAt' => Time::iso($r[3] ?? ''),
                'reviewId' => (string) ($r[4] ?? ''),
                'category' => (string) ($r[5] ?? ''),
                'description' => (string) ($r[6] ?? ''),
                'contactEmail' => '',
                'status' => (string) ($r[7] ?? 'received'),
                'resolutionNote' => (string) ($r[8] ?? ''),
                'anonymous' => true,
            ];
        }
        Response::ok(['grievances' => array_reverse($out)]);
    }

    public static function listSuggestions(Request $req): never
    {
        Admin::require();
        $sheet = Sheets::sheetName('suggestions');
        Sheets::ensureSheet($sheet, [
            'suggestionId', 'submittedAt', 'category', 'suggestion', 'submitterToken', 'status',
        ]);
        $out = [];
        foreach (Sheets::read($sheet, 'A2:F') as $r) {
            if (empty($r[0])) continue;
            $out[] = [
                'suggestionId' => (string) $r[0],
                'submittedAt' => Time::iso($r[1] ?? ''),
                'category' => (string) ($r[2] ?? ''),
                'suggestion' => (string) ($r[3] ?? ''),
                'status' => self::suggestionStatus($r[5] ?? ''),
            ];
        }

        Response::ok(['suggestions' => array_reverse($out)]);
    }

    public static function updateSuggestionStatus(Request $req): never
    {
        Admin::requireKey($req->payload['adminKey'] ?? null);
        $suggestionId = Validator::sanitize($req->payload['suggestionId'] ?? '', 30);
        $status = Validator::sanitize($req->payload['status'] ?? '', 40);
        if ($suggestionId === '') Response::error('Missing suggestion ID.');
        if (!in_array($status, self::suggestionStatuses(), true)) {
            Response::error('Invalid suggestion status.');
        }

        $sheet = Sheets::sheetName('suggestions');
        $rows = Sheets::read($sheet, 'A2:A');
        foreach ($rows as $index => $row) {
            if ((string)($row[0] ?? '') !== $suggestionId) continue;
            Sheets::batchWrite($sheet, [
                ['range' => 'F' . ($index + 2), 'values' => [[$status]]],
            ]);
            Audit::log([
                'actionType' => 'suggestion_status_updated',
                'actor' => 'admin',
                'reason' => "Suggestion {$suggestionId} marked {$status}",
                'relatedId' => $suggestionId,
            ]);
            Response::ok(['message' => 'Suggestion status updated.', 'status' => $status]);
        }

        Response::error('Suggestion not found.', 404);
    }

    public static function acknowledgeGrievance(Request $req): never
    {
        Admin::requireKey($req->payload['adminKey'] ?? null);
        $caseId = Validator::sanitize($req->payload['caseId'] ?? '', 20);
        if ($caseId === '') Response::error('Missing caseId.');

        $sheet = Sheets::sheetName('grievances');
        $data = Sheets::read($sheet, 'A2:J');
        foreach ($data as $i => $r) {
            if (($r[0] ?? '') === $caseId) {
                $rowNum = $i + 2;
                // Clicking Acknowledge has to actually acknowledge someone:
                // the complainant gets the case ID and the clock they are owed,
                // whether or not the automatic on-filing mail is switched on.
                $emailed = PublicHandlers::sendGrievanceAcknowledgement([
                    'caseId' => $caseId,
                    'filedAt' => (string) ($r[1] ?? ''),
                    'reviewId' => (string) ($r[4] ?? ''),
                    'category' => (string) ($r[5] ?? ''),
                    'description' => (string) ($r[6] ?? ''),
                    'email' => Validator::email(Crypto::decrypt((string) ($r[7] ?? ''))),
                    'anonymous' => false,
                ], true);
                Sheets::batchWrite($sheet, [
                    ['range' => "C{$rowNum}", 'values' => [[Time::nowIso()]]],
                    ['range' => "I{$rowNum}", 'values' => [['acknowledged']]],
                ]);
                Audit::log([
                    'actionType' => 'grievance_acknowledged',
                    'actor' => 'admin',
                    'reason' => "Case {$caseId} acknowledged" . ($emailed ? ' and emailed' : ' (no mail delivered)'),
                    'relatedId' => $caseId,
                ]);
                Response::ok([
                    'message' => $emailed
                        ? 'Grievance acknowledged and the complainant has been emailed.'
                        : 'Grievance acknowledged. No mail reached the complainant — check the contact address.',
                ]);
            }
        }
        $data = Sheets::read(Sheets::sheetName('anonymousGrievances'), 'A2:A');
        foreach ($data as $i => $r) {
            if (($r[0] ?? '') === $caseId) {
                $rowNum = $i + 2;
                Sheets::batchWrite(Sheets::sheetName('anonymousGrievances'), [
                    ['range' => "C{$rowNum}", 'values' => [[Time::nowIso()]]],
                    ['range' => "H{$rowNum}", 'values' => [['acknowledged']]],
                ]);
                Response::ok(['message' => 'Anonymous grievance acknowledged.']);
            }
        }
        Response::error('Case not found.');
    }

    public static function resolveGrievance(Request $req): never
    {
        Admin::requireKey($req->payload['adminKey'] ?? null);
        $caseId = Validator::sanitize($req->payload['caseId'] ?? '', 20);
        $note   = Validator::sanitize($req->payload['note'] ?? '', 500) ?: 'Resolved.';
        if ($caseId === '') Response::error('Missing caseId.');

        $sheet = Sheets::sheetName('grievances');
        $data = Sheets::read($sheet, 'A2:A');
        foreach ($data as $i => $r) {
            if (($r[0] ?? '') === $caseId) {
                $rowNum = $i + 2;
                Sheets::batchWrite($sheet, [
                    ['range' => "D{$rowNum}", 'values' => [[Time::nowIso()]]],
                    ['range' => "I{$rowNum}", 'values' => [['resolved']]],
                    ['range' => "J{$rowNum}", 'values' => [[$note]]],
                ]);
                Audit::log([
                    'actionType' => 'grievance_resolved',
                    'actor' => 'admin',
                    // The note stays in the case sheet. This log is published on
                    // the transparency page, and a resolution note routinely
                    // names the complainant and the reviewer.
                    'reason' => "Case {$caseId} resolved",
                    'relatedId' => $caseId,
                ]);
                Response::ok(['message' => 'Grievance resolved.']);
            }
        }
        $data = Sheets::read(Sheets::sheetName('anonymousGrievances'), 'A2:A');
        foreach ($data as $i => $r) {
            if (($r[0] ?? '') === $caseId) {
                $rowNum = $i + 2;
                $sheet = Sheets::sheetName('anonymousGrievances');
                Sheets::batchWrite($sheet, [
                    ['range' => "D{$rowNum}", 'values' => [[Time::nowIso()]]],
                    ['range' => "H{$rowNum}", 'values' => [['resolved']]],
                    ['range' => "I{$rowNum}", 'values' => [[$note]]],
                ]);
                Response::ok(['message' => 'Anonymous grievance resolved.']);
            }
        }
        Response::error('Case not found.');
    }

    private static function suggestionStatuses(): array
    {
        return ['New', 'In progress', 'Completed', 'Duplicate', 'Already implemented', 'Declined'];
    }

    private static function suggestionStatus(mixed $status): string
    {
        $status = (string) $status;
        return in_array($status, self::suggestionStatuses(), true) ? $status : 'New';
    }
}
