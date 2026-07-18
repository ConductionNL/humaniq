<?php

/**
 * Unit tests for the below-CAO-minimum checks (NlCaoChecks).
 *
 * Drives the two cao-library predicates through the REAL RuleEngine +
 * RuleCatalogue + CaoRegistry corpus (not the raw closures) so the test also
 * proves the corpus rules exist, are machine-checkable, and are reachable —
 * i.e. NOT an orphaned capability (REQ-CAO-003 / REQ-CAO-004):
 *
 * - nl-cao-minimumloon-schaal (EmploymentContract): a salary below the verified
 *   cao-generiek minimum raises a mandatory violation; at/above passes; a
 *   placeholder cao-metaal-techniek scale, a null cao, and a null caoSchaal are
 *   all vacuous.
 * - nl-cao-verlof-minimum (LeaveBalance): a holiday balance below the verified
 *   cao-generiek minimum raises a mandatory violation; at/above passes; a
 *   non-holiday leaveType, an employee with no resolvable CAO, and a placeholder
 *   CAO are all vacuous.
 *
 * Also pins the read-only Cao seed projection (REQ-CAO-006): seedObjects()
 * yields one row per corpus CAO, keyed on caoId, values read from the corpus.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\CaoRegistry;
use OCA\Hrmq\Standards\Checks\NlCaoChecks;
use OCA\Hrmq\Standards\RuleCatalogue;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlCaoChecks, driven through the real RuleEngine.
 *
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
 */
class NlCaoChecksTest extends TestCase
{


    /**
     * Reset every statically-memoised layer so each test loads the real
     * catalogue/corpus fresh.
     *
     * @return void
     */
    protected function setUp(): void
    {
        RuleEngine::reset();
        RuleCatalogue::reset();
        CaoRegistry::reset();

    }//end setUp()


    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RuleEngine::reset();
        RuleCatalogue::reset();
        CaoRegistry::reset();

    }//end tearDown()


    /**
     * Whether the evaluated violations contain a given rule id.
     *
     * @param array<int, \OCA\Hrmq\Standards\Violation> $violations The violations.
     * @param string                                    $ruleId     The rule id to look for.
     *
     * @return bool
     */
    private function hasViolation(array $violations, string $ruleId): bool
    {
        foreach ($violations as $violation) {
            if ($violation->ruleId === $ruleId) {
                return true;
            }
        }

        return false;

    }//end hasViolation()


    /**
     * The single violation for a given rule id, or null.
     *
     * @param array<int, \OCA\Hrmq\Standards\Violation> $violations The violations.
     * @param string                                    $ruleId     The rule id.
     *
     * @return \OCA\Hrmq\Standards\Violation|null
     */
    private function violationFor(array $violations, string $ruleId): mixed
    {
        foreach ($violations as $violation) {
            if ($violation->ruleId === $ruleId) {
                return $violation;
            }
        }

        return null;

    }//end violationFor()


    /**
     * A cao context with one employee's salary and one employee->CAO mapping.
     *
     * @param float|null  $salary The employee's grossMonthlySalary, or null.
     * @param string|null $caoId  The employee's active-contract CAO id, or null.
     *
     * @return array<string, mixed>
     */
    private function context(?float $salary=null, ?string $caoId=null): array
    {
        $employeesById = [];
        if ($salary !== null) {
            $employeesById['emp-1'] = ['id' => 'emp-1', 'grossMonthlySalary' => $salary];
        }

        $caoByEmployeeId = [];
        if ($caoId !== null) {
            $caoByEmployeeId['emp-1'] = $caoId;
        }

        return [
            'cao' => [
                'caosById'        => CaoRegistry::availableCaos(),
                'employeesById'   => $employeesById,
                'caoByEmployeeId' => $caoByEmployeeId,
            ],
        ];

    }//end context()


    /**
     * The pay-scale rule is registered against EmploymentContract AND wired to
     * the corpus — i.e. reachable, not an orphaned predicate.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
     */
    public function testPayScaleCheckIsReachableFromTheEngine(): void
    {
        $this->assertArrayHasKey('nl-cao-minimumloon-schaal', (NlCaoChecks::checks()['EmploymentContract'] ?? []));
        $this->assertContains('nl-cao-minimumloon-schaal', RuleEngine::checkedRuleIds());
        $this->assertContains('nl-cao-verlof-minimum', RuleEngine::checkedRuleIds());

    }//end testPayScaleCheckIsReachableFromTheEngine()


    /**
     * Below the verified cao-generiek minimum -> a mandatory violation.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
     */
    public function testSalaryBelowVerifiedMinimumRaisesMandatoryViolation(): void
    {
        $contract   = ['employeeId' => 'emp-1', 'cao' => 'cao-generiek', 'caoSchaal' => 'generiek'];
        $violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context(2000.00, 'cao-generiek'));

        $violation = $this->violationFor($violations, 'nl-cao-minimumloon-schaal');
        $this->assertNotNull($violation, 'A salary below the CAO minimum must raise nl-cao-minimumloon-schaal.');
        $this->assertSame('mandatory', $violation->severity);

    }//end testSalaryBelowVerifiedMinimumRaisesMandatoryViolation()


    /**
     * At or above the verified minimum -> no pay-scale violation.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
     */
    public function testSalaryAtOrAboveMinimumPasses(): void
    {
        $contract   = ['employeeId' => 'emp-1', 'cao' => 'cao-generiek', 'caoSchaal' => 'generiek'];
        $violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context(2500.00, 'cao-generiek'));

        $this->assertFalse($this->hasViolation($violations, 'nl-cao-minimumloon-schaal'));

    }//end testSalaryAtOrAboveMinimumPasses()


    /**
     * A placeholder-marked cao-metaal-techniek scale is advisory: even a salary
     * far below the placeholder figure raises no violation (design.md D5).
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
     */
    public function testPlaceholderScaleIsAdvisory(): void
    {
        $contract   = ['employeeId' => 'emp-1', 'cao' => 'cao-metaal-techniek', 'caoSchaal' => 'B'];
        $violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context(100.00, 'cao-metaal-techniek'));

        $this->assertFalse($this->hasViolation($violations, 'nl-cao-minimumloon-schaal'));

    }//end testPlaceholderScaleIsAdvisory()


    /**
     * A contract with no CAO, or a CAO but no scale, is vacuous for the
     * pay-scale check.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
     */
    public function testNullCaoOrScaleIsVacuous(): void
    {
        $noCao   = RuleEngine::evaluate('EmploymentContract', ['employeeId' => 'emp-1'], $this->context(100.00));
        $noScale = RuleEngine::evaluate('EmploymentContract', ['employeeId' => 'emp-1', 'cao' => 'cao-generiek'], $this->context(100.00, 'cao-generiek'));

        $this->assertFalse($this->hasViolation($noCao, 'nl-cao-minimumloon-schaal'));
        $this->assertFalse($this->hasViolation($noScale, 'nl-cao-minimumloon-schaal'));

    }//end testNullCaoOrScaleIsVacuous()


    /**
     * Leave below the verified cao-generiek minimum (160 h at a 40-hour week)
     * -> a mandatory violation.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    public function testLeaveBelowVerifiedMinimumRaisesMandatoryViolation(): void
    {
        $balance    = [
            'employeeId'           => 'emp-1',
            'leaveType'            => 'holiday',
            'contractHoursPerWeek' => 40,
            'entitledHours'        => 100,
            'bovenwettelijkHours'  => 0,
        ];
        $violations = RuleEngine::evaluate('LeaveBalance', $balance, $this->context(null, 'cao-generiek'));

        $violation = $this->violationFor($violations, 'nl-cao-verlof-minimum');
        $this->assertNotNull($violation, 'Leave below the CAO minimum must raise nl-cao-verlof-minimum.');
        $this->assertSame('mandatory', $violation->severity);

    }//end testLeaveBelowVerifiedMinimumRaisesMandatoryViolation()


    /**
     * Leave at or above the verified minimum -> no leave violation.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    public function testLeaveAtOrAboveMinimumPasses(): void
    {
        $balance    = [
            'employeeId'           => 'emp-1',
            'leaveType'            => 'holiday',
            'contractHoursPerWeek' => 40,
            'entitledHours'        => 160,
            'bovenwettelijkHours'  => 0,
        ];
        $violations = RuleEngine::evaluate('LeaveBalance', $balance, $this->context(null, 'cao-generiek'));

        $this->assertFalse($this->hasViolation($violations, 'nl-cao-verlof-minimum'));

    }//end testLeaveAtOrAboveMinimumPasses()


    /**
     * A non-holiday leave type is out of scope for the CAO leave check.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    public function testNonHolidayLeaveTypeIsVacuous(): void
    {
        $balance    = [
            'employeeId'           => 'emp-1',
            'leaveType'            => 'special',
            'contractHoursPerWeek' => 40,
            'entitledHours'        => 1,
            'bovenwettelijkHours'  => 0,
        ];
        $violations = RuleEngine::evaluate('LeaveBalance', $balance, $this->context(null, 'cao-generiek'));

        $this->assertFalse($this->hasViolation($violations, 'nl-cao-verlof-minimum'));

    }//end testNonHolidayLeaveTypeIsVacuous()


    /**
     * When no CAO resolves for the employee, and when the employee's CAO is a
     * placeholder, the leave check is vacuous.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    public function testLeaveVacuousWhenNoCaoOrPlaceholderCao(): void
    {
        $balance = [
            'employeeId'           => 'emp-1',
            'leaveType'            => 'holiday',
            'contractHoursPerWeek' => 40,
            'entitledHours'        => 1,
            'bovenwettelijkHours'  => 0,
        ];

        $noCao       = RuleEngine::evaluate('LeaveBalance', $balance, $this->context(null, null));
        $placeholder = RuleEngine::evaluate('LeaveBalance', $balance, $this->context(null, 'cao-metaal-techniek'));

        $this->assertFalse($this->hasViolation($noCao, 'nl-cao-verlof-minimum'));
        $this->assertFalse($this->hasViolation($placeholder, 'nl-cao-verlof-minimum'));

    }//end testLeaveVacuousWhenNoCaoOrPlaceholderCao()


    /**
     * seedObjects() projects one Cao row per corpus CAO, keyed on caoId, with
     * values read from the corpus (verification flags surfaced) — the derived
     * read-only display projection (REQ-CAO-006).
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
     */
    public function testSeedObjectsProjectsOneRowPerCaoKeyedOnCaoId(): void
    {
        $seed = NlCaoChecks::seedObjects();
        $this->assertArrayHasKey('Cao', $seed);

        $rows      = $seed['Cao'];
        $available = CaoRegistry::availableCaos();
        $this->assertCount(count($available), $rows, 'One Cao row per corpus CAO.');

        $caoIds = array_column($rows, 'caoId');
        $this->assertSame(count($caoIds), count(array_unique($caoIds)), 'caoId is unique across seeded rows (no duplicates).');
        foreach (array_keys($available) as $id) {
            $this->assertContains($id, $caoIds);
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['caoId']] = $row;
        }

        // Verified anchor vs placeholder sector are surfaced from the corpus.
        $this->assertTrue($byId['cao-generiek']['payScalesVerified']);
        $this->assertFalse($byId['cao-metaal-techniek']['payScalesVerified']);
        $this->assertSame('Metaal en Techniek', $byId['cao-metaal-techniek']['sector']);

    }//end testSeedObjectsProjectsOneRowPerCaoKeyedOnCaoId()


    /**
     * Calling seedObjects() twice yields identical projections — the seed is a
     * deterministic function of the corpus, the precondition for the seeder's
     * upsert convergence (REQ-CAO-006).
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
     */
    public function testSeedObjectsIsDeterministic(): void
    {
        $this->assertSame(NlCaoChecks::seedObjects(), NlCaoChecks::seedObjects());
        $this->assertSame(['Cao' => 'caoId'], NlCaoChecks::upsertKeys());

    }//end testSeedObjectsIsDeterministic()


    /**
     * A contract naming any of the six new sector CAOs + a placeholder scale
     * is vacuous — no nl-cao-minimumloon-schaal violation, regardless of
     * salary — exercising three different scale-naming conventions (numeric,
     * onderwijs letter-schalen, FWG-functiegroep) through the real RuleEngine
     * (cao-sector-datasets REQ-CAOS-002).
     *
     * @return void
     *
     * @spec openspec/changes/cao-sector-datasets/specs/cao-sector-datasets/spec.md#REQ-CAOS-002
     */
    public function testEachNewSectorCaoPlaceholderScaleIsVacuous(): void
    {
        $cases = [
            ['cao-rijk', '11'],
            ['cao-gemeenten', '10'],
            ['cao-onderwijs-po', 'L11'],
            ['cao-onderwijs-vo', 'LB'],
            ['cao-ziekenhuizen', 'FWG-40'],
            ['cao-zorg-vvt', 'FWG-40'],
        ];

        foreach ($cases as [$caoId, $schaal]) {
            $contract   = ['employeeId' => 'emp-1', 'cao' => $caoId, 'caoSchaal' => $schaal];
            $violations = RuleEngine::evaluate('EmploymentContract', $contract, $this->context(1.00, $caoId));

            $this->assertFalse(
                $this->hasViolation($violations, 'nl-cao-minimumloon-schaal'),
                $caoId.'/'.$schaal.' is placeholder -- must never raise a violation even at a EUR 1.00 salary.'
            );
        }

    }//end testEachNewSectorCaoPlaceholderScaleIsVacuous()


    /**
     * A holiday LeaveBalance under any of the six new sector CAOs is vacuous
     * — no nl-cao-verlof-minimum violation, regardless of entitledHours —
     * since every new CAO's leaveEntitlement leaf ships placeholder
     * (cao-sector-datasets REQ-CAOS-002).
     *
     * @return void
     *
     * @spec openspec/changes/cao-sector-datasets/specs/cao-sector-datasets/spec.md#REQ-CAOS-002
     */
    public function testEachNewSectorCaoLeaveBalanceIsVacuous(): void
    {
        $caoIds = [
            'cao-rijk',
            'cao-gemeenten',
            'cao-onderwijs-po',
            'cao-onderwijs-vo',
            'cao-ziekenhuizen',
            'cao-zorg-vvt',
        ];

        foreach ($caoIds as $caoId) {
            $balance    = [
                'employeeId'           => 'emp-1',
                'leaveType'            => 'holiday',
                'contractHoursPerWeek' => 36,
                'entitledHours'        => 1,
                'bovenwettelijkHours'  => 0,
            ];
            $violations = RuleEngine::evaluate('LeaveBalance', $balance, $this->context(null, $caoId));

            $this->assertFalse(
                $this->hasViolation($violations, 'nl-cao-verlof-minimum'),
                $caoId.' leaveEntitlement is placeholder -- must never raise a violation even at 1 entitled hour.'
            );
        }

    }//end testEachNewSectorCaoLeaveBalanceIsVacuous()


    /**
     * seedObjects() covers all nine Cao objects (three existing + six new
     * sector CAOs) with no duplicate caoId keys — idempotency holds at the
     * new corpus size (cao-sector-datasets REQ-CAOS-005).
     *
     * @return void
     *
     * @spec openspec/changes/cao-sector-datasets/specs/cao-sector-datasets/spec.md#REQ-CAOS-005
     */
    public function testSeedObjectsCoversAllNineCaosWithNoDuplicates(): void
    {
        $rows   = NlCaoChecks::seedObjects()['Cao'];
        $caoIds = array_column($rows, 'caoId');

        $this->assertCount(9, $rows, 'Expected one Cao row per corpus CAO (three existing + six sector CAOs).');
        $this->assertSame(9, count(array_unique($caoIds)), 'caoId must be unique across all nine seeded rows.');

        foreach (['cao-rijk', 'cao-gemeenten', 'cao-onderwijs-po', 'cao-onderwijs-vo', 'cao-ziekenhuizen', 'cao-zorg-vvt'] as $id) {
            $this->assertContains($id, $caoIds, $id.' must be seeded as a Cao object.');
        }

    }//end testSeedObjectsCoversAllNineCaosWithNoDuplicates()


}//end class
