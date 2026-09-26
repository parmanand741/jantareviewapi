<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Legacy\Audit;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The transparency page is public, so what it may carry is decided here rather
 * than by whatever the moderation log happens to hold.
 */
final class AuditVisibilityTest extends CIUnitTestCase
{
    /** @return array<string, mixed> A row as mapRow() produces it. */
    private function row(array $override = []): array
    {
        return array_merge([
            'actionId'        => 'ACT-AAAAAA',
            'timestamp'       => '2026-09-26T01:05:00+00:00',
            'deletedReviewId' => 'REV-1',
            'reviewId'        => 'REV-1',
            'productName'     => 'Bosch GSO 8',
            'productUrl'      => 'https://www.amazon.in/dp/B07WLVV8Y3',
            'stars'           => 1,
            'reportCount'     => 15,
            'ruleBroken'      => 'defamation',
            'actionType'      => 'grievance_resolved',
            'actor'           => 'admin',
            'reason'          => 'Case GRV-ABC123 — complainant is the reviewer’s employer',
            'relatedId'       => 'GRV-ABC123',
            'contentHash'     => '0f2c1b3a4d5e6f70',
        ], $override);
    }

    public function testPublicRowCarriesNoAdminOnlyFields(): void
    {
        $public = Audit::publicRow($this->row());

        foreach (['productUrl', 'relatedId', 'contentHash', 'stars', 'reportCount', 'ruleBroken', 'deletedReviewId'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $public, $forbidden . ' must not leave the server unprompted');
        }
        $this->assertArrayNotHasKey('reviewText', $public);
    }

    public function testPublicRowKeepsWhatThePageRenders(): void
    {
        $public = Audit::publicRow($this->row());

        $this->assertSame(
            ['actionId', 'timestamp', 'reviewId', 'productName', 'actionType', 'actor', 'reason', 'integrityRef'],
            array_keys($public)
        );
        $this->assertSame('ACT-AAAAAA', $public['actionId']);
        $this->assertSame('REV-1', $public['reviewId']);
        $this->assertSame('grievance_resolved', $public['actionType']);
    }

    /** Rows logged before resolution notes moved out of the audit trail. */
    public function testLegacyGrievanceNoteIsRedacted(): void
    {
        $public = Audit::publicRow($this->row());

        $this->assertSame('Case GRV-ABC123 resolved', $public['reason']);
        $this->assertStringNotContainsString('employer', $public['reason']);
    }

    public function testMachineReasonsPassThrough(): void
    {
        $this->assertSame(
            'Net Score reached -18',
            Audit::publicRow($this->row(['reason' => 'Net Score reached -18', 'actor' => 'community']))['reason']
        );
        $this->assertSame(
            'Case GRV-ABC123 acknowledged and emailed',
            Audit::publicRow($this->row(['reason' => 'Case GRV-ABC123 acknowledged and emailed']))['reason']
        );
    }

    public function testPublicReasonIsBounded(): void
    {
        $public = Audit::publicRow($this->row(['reason' => str_repeat('अ', 400), 'actor' => 'community']));

        $this->assertLessThanOrEqual(200, mb_strlen($public['reason']));
    }

    /**
     * The stored hash is an unkeyed digest of the review text, so publishing it
     * lets anyone confirm what a removed review said. The reference is keyed.
     */
    public function testIntegrityReferenceIsNotTheStoredHash(): void
    {
        $public = Audit::publicRow($this->row());

        $this->assertSame(12, strlen($public['integrityRef']));
        $this->assertNotSame('0f2c1b3a4d5e6f70', $public['integrityRef']);
        $this->assertSame($public['integrityRef'], Audit::publicRow($this->row())['integrityRef']);
        $this->assertNotSame(
            $public['integrityRef'],
            Audit::publicRow($this->row(['contentHash' => '99']))['integrityRef']
        );
    }
}
