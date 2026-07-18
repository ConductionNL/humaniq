<?php

/**
 * Retention boundary tests for audit-trail-payroll (REQ-AUDP-006).
 *
 * Pins the "reuse, no new logic" boundary: `Payslip.engineInputSnapshot`
 * introduces no new retention field and no new retention derivation.
 * `PayrollRun`/`Payslip` -- including a payslip carrying an
 * `engineInputSnapshot` -- stay governed exactly as before by
 * `AvgDsrRetentionClassifier::PAYROLL_FAMILY_SCHEMAS` (the AWR art. 52 lid 4
 * 7-year fallback, or an explicit `retainedUntil` when populated). This is a
 * checked, tested boundary -- "we verified this, we are not duplicating
 * it" -- rather than a gap nobody noticed.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Hrmq\Service\AvgDsrRetentionClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the audit-trail-payroll retention boundary (REQ-AUDP-006).
 *
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-006
 */
class AvgDsrRetentionClassifierPayrollAuditTest extends TestCase
{


    /**
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-006
     */
    public function testPayslipWithAnInputSnapshotIsStillRetentionLockedByTheAwrFallback(): void
    {
        $classifier = new AvgDsrRetentionClassifier();
        $periodYear = ((int) (new DateTimeImmutable('today'))->format('Y')) - 1;

        $envelope = [
            'object' => [
                'id'                   => 'payslip-1',
                '@self'                => ['schema' => 'Payslip', 'register' => 'hrmq'],
                'period'               => $periodYear.'-02',
                'engineInputSnapshot'  => '{"jurisdiction":"NL","period":"'.$periodYear.'-02"}',
            ],
        ];

        $result = $classifier->classify([$envelope]);

        $this->assertSame([], $result['eligible']);
        $this->assertCount(1, $result['retained']);
        $this->assertSame(AvgDsrRetentionClassifier::RETAINED_LABEL, $result['retained'][0]['label']);

    }//end testPayslipWithAnInputSnapshotIsStillRetentionLockedByTheAwrFallback()


    /**
     * `engineInputSnapshot`'s presence/absence must not change the
     * retention outcome at all -- the classifier operates at object
     * granularity (schema + period), not field granularity, so adding this
     * field changes nothing about how a Payslip classifies.
     *
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-006
     */
    public function testEngineInputSnapshotPresenceDoesNotAlterTheRetentionOutcome(): void
    {
        $classifier = new AvgDsrRetentionClassifier();
        $periodYear = ((int) (new DateTimeImmutable('today'))->format('Y')) - 1;

        $base = [
            'id'     => 'payslip-2',
            '@self'  => ['schema' => 'Payslip', 'register' => 'hrmq'],
            'period' => $periodYear.'-02',
        ];

        $withoutSnapshot = $classifier->classify([['object' => $base]]);
        $withSnapshot     = $classifier->classify(
            [['object' => array_merge($base, ['engineInputSnapshot' => '{"jurisdiction":"NL"}'])]]
        );

        $this->assertSame($withoutSnapshot, $withSnapshot);

    }//end testEngineInputSnapshotPresenceDoesNotAlterTheRetentionOutcome()


    /**
     * A Payslip whose statutory retention window has lapsed (no
     * `retainedUntil`, `period` more than 7 years ago) is erase-eligible
     * exactly as before -- an input snapshot never extends retention.
     *
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-006
     */
    public function testAnExpiredPayslipWithASnapshotIsStillEraseEligible(): void
    {
        $classifier = new AvgDsrRetentionClassifier();
        $periodYear = ((int) (new DateTimeImmutable('today'))->format('Y')) - 9;

        $envelope = [
            'object' => [
                'id'                  => 'payslip-3',
                '@self'               => ['schema' => 'Payslip', 'register' => 'hrmq'],
                'period'              => $periodYear.'-02',
                'engineInputSnapshot' => '{"jurisdiction":"NL"}',
            ],
        ];

        $result = $classifier->classify([$envelope]);

        $this->assertSame([], $result['retained']);
        $this->assertCount(1, $result['eligible']);

    }//end testAnExpiredPayslipWithASnapshotIsStillEraseEligible()


    /**
     * `engineInputSnapshot` is a plain nullable string on the schema -- no
     * new retention-dated field (no `format: date`/`date-time`) was
     * introduced alongside it.
     *
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-006
     */
    public function testEngineInputSnapshotIsAPlainStringNotARetentionDatedField(): void
    {
        $path   = __DIR__.'/../../../lib/Settings/register.d/hr-objects.json';
        $schema = json_decode((string) file_get_contents($path), true);

        $properties = $schema['components']['schemas']['Payslip']['properties'];

        $this->assertArrayHasKey('engineInputSnapshot', $properties);
        $this->assertSame('string', $properties['engineInputSnapshot']['type']);
        $this->assertArrayNotHasKey('format', $properties['engineInputSnapshot']);

    }//end testEngineInputSnapshotIsAPlainStringNotARetentionDatedField()


}//end class
