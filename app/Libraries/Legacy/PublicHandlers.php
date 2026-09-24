<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

final class PublicHandlers
{
    public static function submitReview(Request $req): never
    {
        $data = $req->payload['data'] ?? [];
        $token = (string)($req->payload['turnstileToken'] ?? '');

        $ts = Turnstile::verify($token, 'submit');
        if (!$ts['ok']) Response::error('Bot protection failed (' . $ts['reason'] . ').');

        if (!RateLimit::checkHourly('submit', $req->submitterToken, (int) Config::get('submit_per_hour'))) {
            Response::error('Too many submissions in the last hour.');
        }

        $parentId = Validator::sanitize($data['parentId'] ?? '', 20);
        $isReply  = $parentId !== '';

        $reviewSheet = Sheets::sheetName('reviews');
        $idempotencyKey = Validator::sanitize($data['idempotencyKey'] ?? '', 64);
        if ($idempotencyKey === '') Response::error('Missing submission token.');
        $reviewRows = Sheets::read($reviewSheet, 'A1:R');
        $header = $reviewRows[0] ?? [];
        if (($header[16] ?? '') !== 'idempotencyKey' || ($header[17] ?? '') !== 'platform') {
            Sheets::batchWrite($reviewSheet, [
                ['range' => 'Q1', 'values' => [['idempotencyKey']]],
                ['range' => 'R1', 'values' => [['platform']]],
            ]);
        }
        $all = array_slice($reviewRows, 1);
        foreach ($all as $existing) {
            if (($existing[15] ?? '') === $req->submitterToken && ($existing[16] ?? '') === $idempotencyKey) {
                Response::ok([
                    'message' => 'This submission was already received.',
                    'reviewId' => (string) ($existing[0] ?? ''),
                    'isReply' => !empty($existing[13]),
                    'duplicate' => true,
                ]);
            }
        }

        if ($isReply) {
            $parent = null;
            foreach ($all as $r) {
                if (($r[0] ?? '') === $parentId) {
                    $parent = $r;
                    break;
                }
            }
            if (!$parent) Response::error('Parent review not found.');
            if (!empty($parent[13])) Response::error('Replies to replies are not supported.');
            $productUrl  = (string)($parent[2] ?? '');
            $productName = (string)($parent[11] ?? '');
            $imageUrl    = (string)($parent[3] ?? '');
            $platform    = (string)($parent[17] ?? '');
        } else {
            $productName = Validator::sanitize($data['productName'] ?? '', (int) Config::get('product_name_max_len'));
            $productUrl  = Validator::url($data['productUrl'] ?? '');
            $imageUrl    = Validator::url($data['imageUrl'] ?? '');
            $platform    = Validator::sanitize($data['platform'] ?? '', 120);
            if (mb_strlen($productName) < 2) Response::error('Product name must be at least 2 characters.');
            if ($productUrl === '') Response::error('Please provide a valid HTTPS product URL.');
            if (mb_strlen($platform) < 2) Response::error('Please select or enter a platform.');
            $platformsSheet = Sheets::sheetName('platforms');
            Sheets::ensureSheet($platformsSheet, ['platform']);
            $known = Sheets::read($platformsSheet, 'A2:A');
            $platformExists = false;
            foreach ($known as $row) {
                if (mb_strtolower(trim((string)($row[0] ?? ''))) === mb_strtolower($platform)) {
                    $platform = trim((string) $row[0]);
                    $platformExists = true;
                    break;
                }
            }
        }

        $stars = Validator::stars($data['stars'] ?? 0);
        if ($stars === 0) Response::error('Please select a rating between 1 and 5.');

        $reviewText = Validator::sanitize($data['reviewText'] ?? '', (int) Config::get('review_plain_max_len'));
        if (mb_strlen($reviewText) < 10) Response::error('Review must be at least 10 characters.');

        $encrypted = Crypto::encrypt($reviewText);
        if (strlen($encrypted) > (int) Config::get('review_enc_max_len')) {
            Response::error('Review is too large to store. Please shorten it.');
        }

        Otp::consume(
            $req,
            (string) ($req->payload['otpChallengeId'] ?? ''),
            (string) ($req->payload['otpVerificationToken'] ?? '')
        );
        if (!$isReply && !$platformExists) {
            Sheets::append($platformsSheet, [$platform]);
        }

        $uniqueId = Ids::make($isReply ? 'RPL' : 'REV');
        $now = Time::nowIso();
        $hash = Crypto::contentHash($uniqueId, $now, $reviewText);

        // Build the row (columns A..P).
        $row = [
            $uniqueId,
            $now,
            $productUrl,
            $imageUrl,
            $stars,
            $encrypted,
            0,
            0,
            0,
            'published',
            0,
            $productName,
            '',                // M: lastVoteAt
            $parentId ?: '',   // N: parentId
            $hash,             // O: contentHash
            $req->submitterToken, // P: submitterToken
            $idempotencyKey,   // Q: idempotency key
            $platform,         // R: platform
        ];
        Sheets::append($reviewSheet, $row);

        Audit::log([
            'reviewId'    => $uniqueId,
            'productName' => $productName,
            'productUrl'  => $productUrl,
            'stars'       => $stars,
            'reviewText'  => mb_substr($reviewText, 0, 240),
            'actionType'  => $isReply ? 'reply_published' : 'published',
            'actor'       => 'community',
            'reason'      => 'Initial submission',
            'relatedId'   => $parentId,
            'contentHash' => $hash,
        ]);

        Response::ok([
            'message'  => $isReply ? 'Reply submitted successfully!' : 'Review submitted successfully!',
            'reviewId' => $uniqueId,
            'isReply'  => $isReply,
        ]);
    }

    public static function getReviews(): never
    {
        $rows = Sheets::read(Sheets::sheetName('reviews'), 'A2:R');
        $out = [];
        $replyCounts = [];

        foreach ($rows as $r) {
            if (empty($r[0])) continue;
            $host = parse_url((string)($r[2] ?? ''), PHP_URL_HOST) ?: '';
            $rc   = (int)($r[10] ?? 0);
            $isReply = !empty($r[13]);
            $flagged = $rc >= (int) Config::get('report_ceiling');

            $out[] = [
                'id'          => (string)$r[0],
                'timestamp'   => Time::iso($r[1] ?? ''),
                'productUrl'  => $flagged ? '' : (string)($r[2] ?? ''),
                'imageUrl'    => $flagged ? '' : (string)($r[3] ?? ''),
                'stars'       => (int)($r[4] ?? 0),
                'reviewText'  => $flagged ? '' : Crypto::decrypt((string)($r[5] ?? '')),
                'helpful'     => (int)($r[6] ?? 0),
                'notHelpful'  => (int)($r[7] ?? 0),
                'netScore'    => (int)($r[6] ?? 0) - (int)($r[7] ?? 0),
                'status'      => Validator::status($r[9] ?? ''),
                'reportCount' => $rc,
                'flagged'     => $flagged,
                'productName' => (string)($r[11] ?? ''),
                'store'       => self::detectStore($host),
                'parentId'    => (string)($r[13] ?? ''),
                'isReply'     => $isReply,
                'contentHash' => (string)($r[14] ?? ''),
                'platform'    => (string)($r[17] ?? ''),
            ];

            if ($isReply && !empty($r[13])) {
                $pid = (string)$r[13];
                $replyCounts[$pid] = ($replyCounts[$pid] ?? 0) + 1;
            }
        }

        foreach ($out as &$row) {
            if (!$row['isReply']) $row['replyCount'] = $replyCounts[$row['id']] ?? 0;
        }
        unset($row);

        Response::ok(['reviews' => array_reverse($out)]);
    }

    public static function getPlatforms(): never
    {
        $sheet = Sheets::sheetName('platforms');
        Sheets::ensureSheet($sheet, ['platform']);
        $platforms = [];
        foreach (Sheets::read($sheet, 'A2:A') as $row) {
            $value = trim((string)($row[0] ?? ''));
            if ($value !== '') $platforms[mb_strtolower($value)] = $value;
        }
        $values = array_values($platforms);
        natcasesort($values);
        Response::ok(['platforms' => array_values($values)]);
    }

    public static function vote(Request $req): never
    {
        $reviewId = Validator::sanitize($req->payload['reviewId'] ?? '', 20);
        $voteType = (string)($req->payload['voteType'] ?? '');
        $token    = (string)($req->payload['turnstileToken'] ?? '');

        if ($reviewId === '' || !in_array($voteType, ['helpful', 'not_helpful'], true)) {
            Response::error('Missing parameters.');
        }

        // A Turnstile challenge is now mandatory for BOTH vote directions;
        // previously "helpful" was completely unguarded.
        $ts = Turnstile::verify($token, 'vote_' . $voteType);
        if (!$ts['ok']) Response::error('Verification failed (' . $ts['reason'] . ').');

        if (!RateLimit::checkHourly('vote', $req->submitterToken, (int) Config::get('vote_per_hour', 10))) {
            Response::error('Too many votes from this device recently. Please try again later.', 429);
        }

        service('session');
        $voterKey = hash_hmac(
            'sha256',
            'vote|' . $reviewId . '|' . session_id() . '|' . $req->submitterToken,
            (string) Config::get('encryption_key')
        );

        $sheet     = Sheets::sheetName('reviews');
        $voteSheet = Sheets::sheetName('votes');
        Sheets::ensureSheet($voteSheet, ['vote_key', 'review_id', 'vote_type', 'voted_at']);

        // Serialize everything below per review: closes the read-modify-write
        // race on the counters and on duplicate-vote detection.
        $lock = self::voteLock($reviewId);
        if ($lock === false) Response::error('Vote is busy. Please retry shortly.', 503);

        try {
            foreach (Sheets::read($voteSheet, 'A2:D') as $v) {
                if ((string) ($v[0] ?? '') === $voterKey && (string) ($v[1] ?? '') === $reviewId) {
                    Response::json([
                        'success' => false,
                        'error' => 'You have already voted on this review.',
                        'alreadyVoted' => true,
                    ], 409);
                }
            }

            $data = Sheets::read($sheet, 'A2:P');
            $idx = null;
            foreach ($data as $i => $r) if (($r[0] ?? '') === $reviewId) {
                $idx = $i;
                break;
            }
            if ($idx === null) Response::error('Review not found.');

            $row = $data[$idx];
            $rowNum = $idx + 2;

            // Per-entry cooldown
            $lastVote = $row[12] ?? '';
            if ($lastVote !== '') {
                $t = strtotime((string)$lastVote);
                if ($t !== false && (time() - $t) < (int) Config::get('vote_cooldown_seconds')) {
                    Response::error('A vote was just recorded. Please wait before voting again.');
                }
            }

            $helpful    = (int)($row[6] ?? 0);
            $notHelpful = (int)($row[7] ?? 0);
            $status     = Validator::status($row[9] ?? '');
            $reportCount = (int)($row[10] ?? 0);

            if ($voteType === 'helpful') $helpful++;
            else $notHelpful++;

            $netScore = $helpful - $notHelpful;
            $changed = false;

            if ($status === 'published' && $netScore <= (int) Config::get('unpublish_threshold')) {
                $status = 'archived';
                $changed = true;
                Audit::log([
                    'reviewId' => $reviewId,
                    'productName' => (string)($row[11] ?? ''),
                    'productUrl' => (string)($row[2] ?? ''),
                    'stars' => (int)($row[4] ?? 0),
                    'reviewText' => mb_substr(Crypto::decrypt((string)($row[5] ?? '')), 0, 240),
                    'reportCount' => $reportCount,
                    'actionType' => 'archived',
                    'actor' => 'community',
                    'reason' => 'Net Score reached ' . $netScore,
                    'contentHash' => (string)($row[14] ?? ''),
                ]);
            } elseif (
                $status === 'archived'
                && $netScore >= (int) Config::get('rescue_threshold')
                && $reportCount < (int) Config::get('report_ceiling')
            ) {
                $status = 'published';
                $changed = true;
                Audit::log([
                    'reviewId' => $reviewId,
                    'productName' => (string)($row[11] ?? ''),
                    'productUrl' => (string)($row[2] ?? ''),
                    'stars' => (int)($row[4] ?? 0),
                    'reviewText' => mb_substr(Crypto::decrypt((string)($row[5] ?? '')), 0, 240),
                    'reportCount' => $reportCount,
                    'actionType' => 'rescued',
                    'actor' => 'community',
                    'reason' => 'Net Score recovered to ' . $netScore,
                    'contentHash' => (string)($row[14] ?? ''),
                ]);
            }

            // Ledger entry: one row per (voter, review). Permanent — pruning it
            // would silently re-open the vote, which is the whole point.
            Sheets::append($voteSheet, [$voterKey, $reviewId, $voteType, time()], 'RAW');

            // Write G, H, I, J, M back
            Sheets::batchWrite($sheet, [
                ['range' => "G{$rowNum}:H{$rowNum}", 'values' => [[$helpful, $notHelpful]]],
                ['range' => "I{$rowNum}", 'values' => [[$netScore]]],
                ['range' => "J{$rowNum}", 'values' => [[$status]]],
                ['range' => "M{$rowNum}", 'values' => [[Time::nowIso()]]],
            ]);

            Response::ok([
                'helpful' => $helpful,
                'notHelpful' => $notHelpful,
                'netScore' => $netScore,
                'status' => $status,
                'statusChanged' => $changed,
                'message' => $changed
                    ? ($status === 'archived' ? 'Entry archived by community.' : '🎉 Entry rescued by community!')
                    : 'Vote recorded.',
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource|false Exclusive per-review mutex; carries no state. */
    private static function voteLock(string $reviewId)
    {
        $dir = (string) Config::get('storage_path');
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $handle = @fopen($dir . '/vote_' . hash('sha256', $reviewId) . '.lock', 'c');
        if ($handle === false) return false;
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }
        return $handle;
    }

    public static function report(Request $req): never
    {
        $reviewId = Validator::sanitize($req->payload['reviewId'] ?? '', 20);
        $reason   = (string)($req->payload['reason'] ?? '');
        $text     = Validator::sanitize($req->payload['reasonText'] ?? '', (int) Config::get('report_reason_max_len'));
        $token    = (string)($req->payload['turnstileToken'] ?? '');

        $valid = ['Defamatory', 'Profanity', 'Copyright Violation', 'Spam', 'Hate Speech'];
        if ($reviewId === '' || !in_array($reason, $valid, true)) Response::error('Invalid parameters.');
        if (mb_strlen($text) < 5) Response::error('Please describe the issue (at least 5 characters).');

        if (!RateLimit::checkHourly('report', $req->submitterToken, (int) Config::get('report_per_hour'))) {
            Response::error('Too many reports in the last hour.');
        }

        $sheet = Sheets::sheetName('reviews');
        $data  = Sheets::read($sheet, 'A2:P');

        $idx = null;
        foreach ($data as $i => $r) if (($r[0] ?? '') === $reviewId) {
            $idx = $i;
            break;
        }
        if ($idx === null) Response::error('Review not found.');

        $row = $data[$idx];
        $rowNum = $idx + 2;

        // One global cooldown per review/reply ID prevents mass reporting.
        $reports = Sheets::read(Sheets::sheetName('reports'), 'A2:F');
        $latest = 0;
        foreach ($reports as $r) {
            if (($r[2] ?? '') !== $reviewId) continue;
            $t = strtotime((string)($r[1] ?? ''));
            if ($t !== false && $t > $latest) $latest = $t;
        }
        if ($latest && (time() - $latest) < (int) Config::get('report_cooldown_seconds')) {
            Response::error('This entry was reported recently. Please wait a while before reporting it again.');
        }

        $ts = Turnstile::verify($token, 'report');
        if (!$ts['ok']) Response::error('Verification failed (' . $ts['reason'] . ').');

        $rc = (int)($row[10] ?? 0) + 1;
        $reviewUpdates = [
            ['range' => "K{$rowNum}", 'values' => [[$rc]]],
        ];
        Sheets::append(Sheets::sheetName('reports'), [
            Ids::make('REP'),
            Time::nowIso(),
            $reviewId,
            (string)($row[2] ?? ''),
            $reason,
            $text,
        ]);

        $status = Validator::status($row[9] ?? '');
        $autoArchived = false;
        if ($rc >= (int) Config::get('report_ceiling') && $status !== 'archived') {
            $reviewUpdates[] = ['range' => "J{$rowNum}", 'values' => [['archived']]];
            $autoArchived = true;
        }
        Sheets::batchWrite($sheet, $reviewUpdates);

        Audit::log([
            'reviewId' => $reviewId,
            'productName' => (string)($row[11] ?? ''),
            'productUrl' => (string)($row[2] ?? ''),
            'stars' => (int)($row[4] ?? 0),
            'reviewText' => mb_substr(Crypto::decrypt((string)($row[5] ?? '')), 0, 240),
            'reportCount' => $rc,
            'actionType' => $autoArchived ? 'flagged_and_archived' : 'flag_received',
            'actor' => 'community',
            'reason' => 'Report filed: ' . $reason,
            'contentHash' => (string)($row[14] ?? ''),
        ]);

        Response::ok([
            'message' => $autoArchived
                ? 'Report received. This entry has been archived for admin review.'
                : 'Report received. Our team will review it shortly.',
            'reportCount' => $rc,
        ]);
    }

    public static function fileGrievance(Request $req): never
    {
        $data = $req->payload['data'] ?? [];
        $token = (string)($req->payload['turnstileToken'] ?? '');

        $ts = Turnstile::verify($token, 'grievance');
        if (!$ts['ok']) Response::error('Verification failed (' . $ts['reason'] . ').');

        $reviewId = Validator::sanitize($data['reviewId'] ?? '', 20);
        $category = (string)($data['category'] ?? '');
        $desc     = Validator::sanitize($data['description'] ?? '', (int) Config::get('grievance_desc_max_len'));
        $email    = Validator::email($data['contactEmail'] ?? '');
        $anonymous = !empty($data['anonymous']);

        $valid = ['Defamatory', 'Privacy / Personal Data', 'Copyright Violation', 'Court Order', 'Impersonation', 'Other Legal'];
        if (!in_array($category, $valid, true)) Response::error('Invalid category.');
        if (mb_strlen($desc) < (int) Config::get('grievance_desc_min_len')) {
            Response::error('Please describe the issue (at least ' . Config::get('grievance_desc_min_len') . ' characters).');
        }

        if (!$anonymous && $email === '') Response::error('Please provide a valid contact email or choose anonymous submission.');

        $now = Time::nowIso();
        $caseId = Ids::make('GRV');
        if ($anonymous) {
            $sheet = Sheets::sheetName('anonymousGrievances');
            Sheets::ensureSheet($sheet, [
                'caseId', 'filedAt', 'ackAt', 'resolvedAt', 'reviewId', 'category', 'description', 'status', 'resolutionNote',
            ]);
            Sheets::append($sheet, [$caseId, $now, '', '', $reviewId, $category, $desc, 'received', '']);
        } else {
            $sheet = Sheets::sheetName('grievances');
            Sheets::ensureSheet($sheet, [
            'caseId',
            'filedAt',
            'ackAt',
            'resolvedAt',
            'reviewId',
            'category',
            'description',
            'contactEmail',
            'status',
            'resolutionNote',
            ]);
            Sheets::append($sheet, [
            $caseId,
            $now,
            '',
            '',
            $reviewId,
            $category,
            $desc,
            Crypto::encrypt($email),
            'received',
            '',
            ]);
        }

        Audit::log([
            'reviewId' => $reviewId,
            'actionType' => 'grievance_received',
            'actor' => 'public',
            'reason' => 'Grievance filed — category: ' . $category,
            'relatedId' => $caseId,
        ]);

        // Notify the Grievance Officer (best effort)
        $go = (array) Config::get('grievance_officer', []);
        if (!empty($go['email']) && !str_contains($go['email'], 'yourdomain.com')) {
            @mail(
                $go['email'],
                '[JantaReview] New grievance ' . $caseId . ' (' . $category . ')',
                "A new grievance has been filed.\n\n" .
                    "Case ID:   {$caseId}\n" .
                    "Filed at:  {$now}\n" .
                    "Review ID: " . ($reviewId ?: '(not specified)') . "\n" .
                    "Category:  {$category}\n" .
                    "From:      " . ($anonymous ? '(anonymous request)' : $email) . "\n\n" .
                    "Description:\n{$desc}\n",
                "From: no-reply@yourdomain.com\r\nReply-To: {$email}\r\n"
            );
        }

        Response::ok([
            'message' => 'Your grievance has been received. Please keep your case ID for follow-up.',
            'caseId' => $caseId,
            'anonymous' => $anonymous,
            'acknowledgementSLAHours' => $go['ack_sla_hours'] ?? 24,
            'resolutionSLADays' => $go['resolve_sla_days'] ?? 15,
        ]);
    }

    public static function submitSuggestion(Request $req): never
    {
        $data = $req->payload['data'] ?? [];
        $category = (string) ($data['category'] ?? '');
        $text = Validator::sanitize($data['suggestion'] ?? '', 500);
        $valid = ['Feature request', 'Improvement', 'Bug report', 'Other'];

        if (!in_array($category, $valid, true)) Response::error('Invalid suggestion category.');
        if (mb_strlen($text) < 10) Response::error('Please provide at least 10 characters.');
        if (!RateLimit::checkHourly('suggestion', $req->submitterToken, 5)) {
            Response::error('Too many suggestions. Please try again later.', 429);
        }

        Sheets::ensureSheet(Sheets::sheetName('suggestions'), [
            'suggestionId', 'submittedAt', 'category', 'suggestion', 'submitterToken', 'status',
        ]);
        $id = Ids::make('SUG');
        Sheets::append(Sheets::sheetName('suggestions'), [
            $id, Time::nowIso(), $category, $text, $req->submitterToken, 'New',
        ]);

        Response::ok(['message' => 'Thank you. Your suggestion has been received.']);
    }

    public static function getTransparencyLog(): never
    {
        Response::ok(['log' => Audit::all(500)]);
    }

    public static function getReviewHistory(Request $req): never
    {
        $reviewId = Validator::sanitize($req->payload['reviewId'] ?? '', 20);
        if ($reviewId === '') Response::error('Missing reviewId.');
        Response::ok(['reviewId' => $reviewId, 'history' => Audit::forReview($reviewId)]);
    }

    public static function getTransparencyReport(): never
    {
        $summary = Audit::summary();
        $gSheet = Sheets::sheetName('grievances');

        // Read grievances (if the sheet exists)
        $open = 0;
        $resolved = 0;
        $ackWithinSLA = 0;
        $ackTotal = 0;
        $data = Sheets::read($gSheet, 'A2:J');
        foreach ($data as $r) {
            if (empty($r[0])) continue;
            $status = (string)($r[8] ?? 'received');
            if ($status === 'resolved') $resolved++;
            else $open++;
            if (!empty($r[2])) {
                $ackTotal++;
                $filed = strtotime((string)$r[1]);
                $acked = strtotime((string)$r[2]);
                if ($filed && $acked && ($acked - $filed) <= ((int) Config::get('grievance_officer')['ack_sla_hours'] ?? 24) * 3600) {
                    $ackWithinSLA++;
                }
            }
        }

        Response::ok([
            'generatedAt' => Time::nowIso(),
            'totals' => $summary['totals'],
            'byActor' => $summary['byActor'],
            'grievances' => [
                'open' => $open,
                'resolved' => $resolved,
                'ackWithinSLA' => $ackWithinSLA,
                'ackTotal' => $ackTotal,
                'ackSLAPercent' => $ackTotal ? (int) round(($ackWithinSLA / $ackTotal) * 100) : 100,
                'slaHours' => (int) Config::get('grievance_officer')['ack_sla_hours'],
                'resolveSLADays' => (int) Config::get('grievance_officer')['resolve_sla_days'],
            ],
            'compliance' => [
                'retentionPolicyDays' => (int) Config::get('retention_days'),
            ],
        ]);
    }

    public static function getStats(): never
    {
        $rows = Sheets::read(Sheets::sheetName('reviews'), 'A2:O');
        $total = $published = $archived = $reported = $replies = 0;
        $ceiling = (int) Config::get('report_ceiling');

        foreach ($rows as $r) {
            if (empty($r[0])) continue;
            if (!empty($r[13])) {
                $replies++;
                continue;
            }
            $status = Validator::status($r[9] ?? '');
            $rc = (int)($r[10] ?? 0);
            if ($status === 'published') {
                $published++;
                $total++;
            } elseif ($status === 'archived') {
                $total++;
                if ($rc >= $ceiling) $reported++;
                else $archived++;
            }
        }

        $deleted = 0;
        $dRows = Sheets::read(Sheets::sheetName('deleted'), 'A2:Q');
        foreach ($dRows as $r) {
            if (empty($r[0])) continue;
            // O = parentId, N = restoredAt
            if (empty($r[13]) && empty($r[14])) $deleted++;
        }

        Response::ok(['stats' => [
            'total' => $total,
            'published' => $published,
            'archived' => $archived,
            'reported' => $reported,
            'deleted' => $deleted,
            'replies' => $replies,
        ]]);
    }

    public static function warmUp(): never
    {
        Admin::require();
        try {
            Sheets::read(Sheets::sheetName('reviews'), 'A1:A1');
            Sheets::ensureSheet(Sheets::sheetName('grievances'), [
                'caseId',
                'filedAt',
                'ackAt',
                'resolvedAt',
                'reviewId',
                'category',
                'description',
                'contactEmail',
                'status',
                'resolutionNote',
            ]);
        } catch (\Throwable $e) { /* silent */
        }
        Response::ok(['ts' => Time::nowIso()]);
    }

    public static function getCompliance(): never
    {
        $go = (array) Config::get('grievance_officer', []);
        Response::ok([
            'platform' => (string) Config::get('platform_name'),
            'jurisdiction' => (string) Config::get('jurisdiction'),
            'legal' => [
                'intermediaryStatus' => 'Operates as an intermediary under Section 79 of the IT Act, 2000.',
                'intermediaryRules' => 'Complies with the IT (Intermediary Guidelines and Digital Media Ethics Code) Rules, 2021.',
                'dpdp' => 'Review emails are used only for one-time verification and are not stored with review data.',
                'retentionPolicyDays' => (int) Config::get('retention_days'),
            ],
            'grievanceOfficer' => [
                'name' => $go['name'] ?? '',
                'email' => $go['email'] ?? '',
                'phone' => $go['phone'] ?? '',
                'address' => $go['address'] ?? '',
                'ackSLAHours' => $go['ack_sla_hours'] ?? 24,
                'resolveSLADays' => $go['resolve_sla_days'] ?? 15,
                'priorityTakedownHours' => $go['priority_takedown_hours'] ?? 36,
            ],
        ]);
    }

    public static function health(): never
    {
        Response::ok(['status' => 'ok', 'time' => Time::nowIso(), 'version' => '3.0.0']);
    }

    public static function info(): never
    {
        Response::ok([
            'name' => 'JantaReview API',
            'version' => '3.0.0',
            'jurisdiction' => (string) Config::get('jurisdiction'),
            'features' => ['threaded-replies', 'email-otp-verification', 'turnstile-hostname-check', 'rate-limits', 'at-rest-encryption', 'public-transparency-log', 'grievance-workflow', 'feature-suggestions'],
            'endpoints' => [
                'submitReview',
                'requestReviewOtp',
                'verifyReviewOtp',
                'getReviews',
                'vote',
                'report',
                'getTransparencyLog',
                'getReviewHistory',
                'getTransparencyReport',
                'getStats',
                'fileGrievance',
                'submitSuggestion',
                'adminListSuggestions',
                'adminUpdateSuggestionStatus',
                'warmUp',
                'adminGetDashboard',
                'adminDeleteReview',
                'adminRestoreReview',
                'adminRestoreFromDeleted',
                'adminListGrievances',
                'adminAcknowledgeGrievance',
                'adminResolveGrievance',
            ],
        ]);
    }

    private static function detectStore(string $host): ?string
    {
        if ($host === '') return null;
        $h = strtolower($host);
        $stores = [
            'amazon.' => 'Amazon',
            'amzn.' => 'Amazon',
            'flipkart.' => 'Flipkart',
            'myntra.' => 'Myntra',
            'ajio.' => 'AJIO',
            'meesho.' => 'Meesho',
            'snapdeal.' => 'Snapdeal',
            'nykaa.' => 'Nykaa',
            'croma.' => 'Croma',
            'reliancedigital.' => 'Reliance Digital',
            'tatacliq.' => 'Tata CLiQ',
            'jiomart.' => 'JioMart',
            'paytmmall.' => 'Paytm Mall',
            'shopclues.' => 'ShopClues',
            'bigbasket.' => 'BigBasket',
            'blinkit.' => 'Blinkit',
            'zepto.' => 'Zepto',
            'dmart.' => 'DMart',
            'lenskart.' => 'Lenskart',
            'pepperfry.' => 'Pepperfry',
            'firstcry.' => 'FirstCry',
            'ebay.' => 'eBay',
            'aliexpress.' => 'AliExpress',
            'walmart.' => 'Walmart',
            'etsy.' => 'Etsy',
        ];
        foreach ($stores as $needle => $name) {
            if (str_contains($h, $needle)) return $name;
        }
        return null;
    }
}
