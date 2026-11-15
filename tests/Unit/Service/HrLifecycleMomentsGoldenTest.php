<?php

/**
 * Golden test for HrLifecycleMoments: every moment each kind of write marks.
 *
 * Each schema's edges against a fixed day on the real gateway over the
 * in-memory store, recorded once in
 * tests/fixtures/golden/hr-lifecycle-moments.json.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HrLifecycleMoments;
use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use PHPUnit\Framework\TestCase;

/**
 * Every moment each kind of write marks.
 */
class HrLifecycleMomentsGoldenTest extends TestCase {

	use ApprovalsFixture;

	private const TODAY = '2026-06-15';

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/hr-lifecycle-moments.json';

	/**
	 * Seed the team, a second contract and a second placement.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
		$this->store->seed('EmploymentContract', 'ct-sam', ['employeeId' => 'emp-sam', 'startDate' => '2024-01-01', 'normfunctieId' => 'nf-a']);
		$this->store->seed('EmploymentContract', 'ct-noor-old', ['employeeId' => 'emp-noor', 'startDate' => '2020-01-01', 'endDate' => '2023-12-31']);
		$this->store->seed('EmploymentContract', 'ct-noor', ['employeeId' => 'emp-noor', 'startDate' => '2024-01-01']);
	}//end setUp()

	/**
	 * The writes, by name: slug, old record, new record.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>|null, 2: array<string, mixed>}>
	 */
	private function writes(): array {
		$sam = ['id' => 'ct-sam', 'employeeId' => 'emp-sam', 'startDate' => '2024-01-01', 'normfunctieId' => 'nf-a'];
		$noor = ['id' => 'ct-noor', 'employeeId' => 'emp-noor', 'startDate' => '2024-01-01'];
		$leave = ['id' => 'lv-1', 'employeeId' => 'emp-sam', 'startDate' => '2026-08-03', 'endDate' => '2026-08-07T00:00:00Z', 'hours' => '16', 'status' => 'submitted'];
		$sick = ['id' => 'sk-1', 'employeeId' => 'emp-sam', 'firstSickDay' => '2026-06-01', 'status' => 'gemeld'];

		return [
			'no-employee' => ['onboarding', null, ['employeeId' => '  ', 'status' => 'afgerond']],
			'unknown-slug' => ['payslip', null, ['employeeId' => 'emp-sam']],
			'onboarding-done' => ['onboarding', ['employeeId' => 'emp-sam', 'status' => 'lopend'], ['employeeId' => 'emp-sam', 'status' => 'afgerond', 'startDate' => '2026-07-01']],
			'onboarding-no-date' => ['onboarding', null, ['employeeId' => 'emp-sam', 'status' => 'afgerond']],
			'onboarding-again' => ['onboarding', ['employeeId' => 'emp-sam', 'status' => 'afgerond'], ['employeeId' => 'emp-sam', 'status' => 'afgerond']],
			'offboarding-done' => ['offboarding', ['employeeId' => 'emp-sam', 'status' => 'x'], ['employeeId' => 'emp-sam', 'status' => 'afgerond', 'lastWorkingDay' => '2026-07-31']],
			'offboarding-open' => ['offboarding', null, ['employeeId' => 'emp-sam', 'status' => 'lopend']],
			'contract-first' => ['employmentcontract', null, ['id' => 'ct-new', 'employeeId' => 'emp-new', 'startDate' => '2026-09-01']],
			'contract-second' => ['employmentcontract', null, ['id' => 'ct-sam-2', 'employeeId' => 'emp-sam', 'startDate' => '2026-09-01']],
			'contract-function' => ['employmentcontract', $sam, array_merge($sam, ['normfunctieId' => 'nf-b'])],
			'contract-same-function' => ['employmentcontract', $sam, array_merge($sam, ['hoursPerWeek' => 32])],
			'contract-ended-last' => ['employmentcontract', $noor, array_merge($noor, ['endDate' => '2026-05-31'])],
			'contract-ended-future' => ['employmentcontract', $noor, array_merge($noor, ['endDate' => '2026-12-31'])],
			'contract-ended-same' => ['employmentcontract', array_merge($noor, ['endDate' => '2026-05-31']), array_merge($noor, ['endDate' => '2026-05-31', 'x' => 1])],
			'contract-ended-only' => ['employmentcontract', $sam, array_merge($sam, ['endDate' => '2026-05-31'])],
			'placement-second' => ['orgassignment', null, ['id' => 'as-new', 'employeeId' => 'emp-sam', 'orgUnitId' => 'unit-ops', 'startDate' => '2026-07-01']],
			'placement-first' => ['orgassignment', null, ['id' => 'as-x', 'employeeId' => 'emp-new', 'orgUnitId' => 'unit-ops']],
			'placement-update' => ['orgassignment', ['id' => 'as-sam'], ['id' => 'as-sam', 'employeeId' => 'emp-sam', 'orgUnitId' => 'unit-ops']],
			'leave-approved' => ['leaverequest', $leave, array_merge($leave, ['status' => 'approved'])],
			'leave-approved-on-create' => ['leaverequest', null, ['id' => 'lv-2', 'employeeId' => 'emp-sam', 'status' => 'approved']],
			'leave-withdrawn' => ['leaverequest', array_merge($leave, ['status' => 'approved']), array_merge($leave, ['status' => 'cancelled'])],
			'leave-unchanged' => ['leaverequest', $leave, $leave],
			'sick-reported' => ['sickleavecase', null, $sick],
			'sick-reported-no-date' => ['sickleavecase', null, ['id' => 'sk-2', 'employeeId' => 'emp-sam']],
			'sick-recovered' => ['sickleavecase', $sick, array_merge($sick, ['status' => 'hersteld', 'recoveredDate' => '2026-06-10'])],
			'sick-recovered-no-date' => ['sickleavecase', $sick, array_merge($sick, ['status' => 'hersteld'])],
			'sick-update' => ['sickleavecase', $sick, array_merge($sick, ['note' => 'x'])],
		];
	}//end writes()

	/**
	 * Every write's moments, against the golden record.
	 *
	 * @return void
	 */
	public function testEveryMomentOfEveryWrite(): void {
		$moments = new HrLifecycleMoments($this->gateway());
		$got = [];
		foreach ($this->writes() as $name => [$slug, $old, $new]) {
			$got[$name] = $moments->moments(slug: $slug, old: $old, new: $new, today: self::TODAY);
		}

		self::assertSame((string)file_get_contents(self::GOLDEN), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
	}//end testEveryMomentOfEveryWrite()
}//end class
