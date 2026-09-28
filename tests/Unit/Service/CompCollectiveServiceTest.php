<?php

/**
 * Unit tests for CompCollectiveService.
 *
 * Pins the collective raise and the step increase (design.md D2, D3, D5): one
 * proposal per employee in scope, idempotent per cycle and employee, a dry run
 * that writes nothing, the next step of the band for a due contract, and a
 * bulk approval that never approves the caller's own proposals. Every payload
 * the service writes is validated against the CompAdjustment schema in the
 * register fragment. The register reads go through the real
 * HoursRegisterGateway method names; the transition engine is a stand-in with
 * OpenRegister's `TransitionEngine::transition()` signature.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CompCollectiveService;
use OCA\Humaniq\Service\CompCycleApprover;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CompCollectiveService.
 */
class CompCollectiveServiceTest extends TestCase {

	/**
	 * A stable uuid for a readable fixture name: the schema's relations are
	 * `format: uuid`, and a payload with a made-up id would not validate.
	 *
	 * @param string $name Fixture name.
	 *
	 * @return string
	 */
	private static function id(string $name): string {
		$hex = md5($name);
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
	}//end id()

	/**
	 * Rows per schema, the register the fake gateway answers from.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every save the service made, as ['schema' => ..., 'object' => ...].
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Every transition the service asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $transitions = [];

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->transitions = [];
		$this->rows = [
			'CompReviewCycle' => [
				[
					'id' => self::id('cycle-collective'),
					'name' => 'Collectieve verhoging 2026',
					'period' => '2026',
					'effectiveDate' => '2026-07-01',
					'status' => 'open',
					'kind' => 'collective',
					'raisePercentage' => 2,
					'scope' => ['administrationId' => 'ADM-001'],
				],
				[
					'id' => self::id('cycle-steps'),
					'name' => 'Periodieken 2026',
					'period' => '2026',
					'effectiveDate' => '2026-01-01',
					'status' => 'open',
					'kind' => 'step-increase',
				],
				[
					'id' => self::id('cycle-individual'),
					'name' => 'Beloningsronde 2026',
					'period' => '2026',
					'effectiveDate' => '2026-07-01',
					'status' => 'open',
					'kind' => 'individual',
				],
			],
			'Employee' => [
				$this->employee(self::id('emp-jansen'), 'Jansen', 3800.00, 'ADM-001', 'sjansen'),
				$this->employee(self::id('emp-devries'), 'de Vries', 2912.00, 'ADM-001', null),
				$this->employee(self::id('emp-bakker'), 'Bakker', 2600.00, 'ADM-001', 'mbakker'),
				$this->employee(self::id('emp-elders'), 'Elders', 4100.00, 'ADM-002', 'eelders'),
				$this->employee(self::id('emp-vertrokken'), 'Vertrokken', 3000.00, 'ADM-001', null),
			],
			'EmploymentContract' => [
				$this->contract(self::id('ctr-jansen'), self::id('emp-jansen'), '2024-01-01', null, ['hourlyWage' => 24.36, 'salaryBandId' => self::id('band-a'), 'salaryStep' => 2, 'stepDate' => '2026-03-01']),
				$this->contract(self::id('ctr-devries'), self::id('emp-devries'), '2025-02-01', null, ['salaryBandId' => self::id('band-a'), 'salaryStep' => 4, 'stepDate' => '2026-05-01']),
				$this->contract(self::id('ctr-bakker'), self::id('emp-bakker'), '2023-06-01', null, ['salaryBandId' => self::id('band-a'), 'salaryStep' => 1, 'stepDate' => '2027-02-01']),
				$this->contract(self::id('ctr-elders'), self::id('emp-elders'), '2022-01-01', null, []),
				$this->contract(self::id('ctr-vertrokken'), self::id('emp-vertrokken'), '2020-01-01', '2026-03-31', []),
			],
			'SalaryBand' => [
				[
					'id' => self::id('band-a'),
					'bandId' => 'A',
					'title' => 'Schaal A',
					'minSalary' => 300000,
					'maxSalary' => 420000,
					'currency' => 'EUR',
					'steps' => [
						['step' => 1, 'monthlySalaryCents' => 330000],
						['step' => 2, 'monthlySalaryCents' => 360000],
						['step' => 3, 'monthlySalaryCents' => 390000],
						['step' => 4, 'monthlySalaryCents' => 420000],
					],
				],
			],
			'CompAdjustment' => [],
			'OrgAssignment' => [],
		];
	}//end setUp()

	/**
	 * An Employee row.
	 *
	 * @param string $id Id.
	 * @param string $lastName Last name.
	 * @param float $gross Gross monthly salary in euros.
	 * @param string $administrationId Administration.
	 * @param string|null $uid Nextcloud account.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(string $id, string $lastName, float $gross, string $administrationId, ?string $uid): array {
		return [
			'id' => $id,
			'lastName' => $lastName,
			'startDate' => '2020-01-01',
			'grossMonthlySalary' => $gross,
			'administrationId' => $administrationId,
			'nextcloudUserId' => $uid,
		];
	}//end employee()

	/**
	 * An EmploymentContract row.
	 *
	 * @param string $id Id.
	 * @param string $employeeId Employee.
	 * @param string $start Start date.
	 * @param string|null $end End date.
	 * @param array<string, mixed> $extra Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(string $id, string $employeeId, string $start, ?string $end, array $extra): array {
		return array_merge(['id' => $id, 'employeeId' => $employeeId, 'type' => 'permanent', 'startDate' => $start, 'endDate' => $end, 'hoursPerWeek' => 36], $extra);
	}//end contract()

	/**
	 * The service over a gateway double answering from $this->rows.
	 *
	 * @return CompCollectiveService
	 */
	private function service(): CompCollectiveService {
		return new CompCollectiveService($this->gateway(), $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The approver over the same gateway double and a transition engine
	 * stand-in with OpenRegister's `TransitionEngine::transition()` signature.
	 *
	 * @return CompCycleApprover
	 */
	private function approver(): CompCycleApprover {
		$engine = new class($this) {
			/**
			 * @param CompCollectiveServiceTest $test The test recording transitions.
			 */
			public function __construct(private readonly CompCollectiveServiceTest $test) {
			}

			/**
			 * OpenRegister's TransitionEngine::transition() signature.
			 *
			 * @param string $objectId Object id.
			 * @param string $action Transition.
			 * @param array<string, mixed> $data Inputs.
			 *
			 * @return object
			 */
			public function transition(string $objectId, string $action, array $data = []): object {
				$this->test->recordTransition($objectId, $action, $data);
				return (object)['id' => $objectId];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\Lifecycle\TransitionEngine')->willReturn($engine);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return new CompCycleApprover($this->gateway(), $container, $settings, $this->createMock(LoggerInterface::class));
	}//end approver()

	/**
	 * A gateway double answering from $this->rows with the real method names.
	 *
	 * @return HoursRegisterGateway
	 */
	private function gateway(): HoursRegisterGateway {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(fn (string $schema): array => ($this->rows[$schema] ?? []));
		$gateway->method('findObjectData')->willReturnCallback(
			function (string $uuid, string $schema): ?array {
				foreach (($this->rows[$schema] ?? []) as $row) {
					if ($row['id'] === $uuid) {
						return $row;
					}
				}

				return null;
			}
		);
		$gateway->method('findFiltered')->willReturnCallback(
			function (string $schema, array $filters): array {
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if ((string)($row[$key] ?? '') !== (string)$value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$gateway->method('save')->willReturnCallback(
			function (array $payload, string $schema, ?string $uuid = null): object {
				$id = ($uuid ?? ('adj-' . (count($this->rows[$schema] ?? []) + 1)));
				$this->saved[] = ['schema' => $schema, 'object' => $payload];
				$this->rows[$schema][] = array_merge($payload, ['id' => $id]);
				return (object)['id' => $id];
			}
		);

		return $gateway;
	}//end gateway()

	/**
	 * Record a transition the engine stand-in received, and apply it.
	 *
	 * @param string $objectId Object id.
	 * @param string $action Transition.
	 * @param array<string, mixed> $data Inputs.
	 *
	 * @return void
	 */
	public function recordTransition(string $objectId, string $action, array $data): void {
		$this->transitions[] = ['id' => $objectId, 'action' => $action, 'data' => $data];
		foreach (($this->rows['CompAdjustment'] ?? []) as $i => $row) {
			if ($row['id'] === $objectId && $action === 'approve') {
				$this->rows['CompAdjustment'][$i] = array_merge($row, $data, ['status' => 'approved']);
			}
		}
	}//end recordTransition()

	/**
	 * Proposed salary by employee, from what was saved.
	 *
	 * @return array<string, int>
	 */
	private function proposedByEmployee(): array {
		$out = [];
		foreach ($this->saved as $save) {
			$out[$save['object']['employeeId']] = $save['object']['proposedSalary'];
		}

		return $out;
	}//end proposedByEmployee()

	/**
	 * A payroll officer raises ADM-001 by 2 percent: three proposals at
	 * 3876.00, 2970.24 and 2652.00, each linked to the cycle, each valid
	 * against the CompAdjustment schema.
	 *
	 * @return void
	 */
	public function testATwoPercentRaiseProposesOnePerEmployeeInScope(): void {
		$result = $this->service()->proposeForCycle(self::id('cycle-collective'), 'salarisadmin');

		self::assertSame(3, $result['created']);
		self::assertSame(
			[self::id('emp-jansen') => 387600, self::id('emp-devries') => 297024, self::id('emp-bakker') => 265200],
			$this->proposedByEmployee()
		);
		foreach ($this->saved as $save) {
			self::assertSame('CompAdjustment', $save['schema']);
			self::assertSame(self::id('cycle-collective'), $save['object']['cycleId']);
			self::assertSame('proposed', $save['object']['status']);
			self::assertSame('salarisadmin', $save['object']['proposedBy']);
			self::assertSame('collective', $save['object']['adjustmentKind']);
			self::assertSame([], RegisterSchemaValidator::errors('CompAdjustment', $save['object']), json_encode($save['object']));
		}

		$jansen = $this->saved[0]['object'];
		self::assertSame(380000, $jansen['currentSalary']);
		self::assertSame(24.85, $jansen['proposedHourlyWage']);
		self::assertSame('sjansen', $jansen['employeeUserId']);
		self::assertSame(self::id('ctr-jansen'), $jansen['contractId']);
	}//end testATwoPercentRaiseProposesOnePerEmployeeInScope()

	/**
	 * Running the proposal twice changes nothing.
	 *
	 * @return void
	 */
	public function testRunningTheProposalTwiceCreatesNothing(): void {
		$this->service()->proposeForCycle(self::id('cycle-collective'), 'salarisadmin');
		$second = $this->service()->proposeForCycle(self::id('cycle-collective'), 'salarisadmin');

		self::assertSame(0, $second['created']);
		self::assertSame(3, $second['alreadyPresent']);
		self::assertCount(3, $this->saved);
	}//end testRunningTheProposalTwiceCreatesNothing()

	/**
	 * A dry run reports the count and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunCountsAndWritesNothing(): void {
		$result = $this->service()->proposeForCycle(self::id('cycle-collective'), 'salarisadmin', true);

		self::assertTrue($result['dryRun']);
		self::assertSame(3, $result['wouldCreate']);
		self::assertSame(0, $result['created']);
		self::assertCount(3, $result['rows']);
		self::assertSame([], $this->saved);
	}//end testADryRunCountsAndWritesNothing()

	/**
	 * A hand-picked selection from the Employees list replaces the scope.
	 *
	 * @return void
	 */
	public function testAHandPickedSelectionReplacesTheScope(): void {
		$result = $this->service()->proposeForCycle(self::id('cycle-collective'), 'salarisadmin', false, [self::id('emp-elders'), self::id('emp-bakker')]);

		self::assertSame(2, $result['created']);
		self::assertSame([self::id('emp-bakker') => 265200, self::id('emp-elders') => 418200], $this->proposedByEmployee());
	}//end testAHandPickedSelectionReplacesTheScope()

	/**
	 * An individual cycle has no raise to apply and is refused.
	 *
	 * @return void
	 */
	public function testAnIndividualCycleIsRefused(): void {
		$result = $this->service()->proposeForCycle(self::id('cycle-individual'), 'salarisadmin');

		self::assertSame('refused-not-collective', $result['status']);
		self::assertSame([], $this->saved);
	}//end testAnIndividualCycleIsRefused()

	/**
	 * A due step is proposed: Jansen, step 2 of band A, step date March 2026,
	 * moves to step 3 at step 3's salary. De Vries is on the top step and
	 * Bakker's step date is in 2027: neither gets a proposal.
	 *
	 * @return void
	 */
	public function testADueStepIsProposedAndTheTopAndNotDueAreNot(): void {
		$result = $this->service()->proposeForCycle(self::id('cycle-steps'), 'hr-adviseur');

		self::assertSame(1, $result['created']);
		$proposal = $this->saved[0]['object'];
		self::assertSame(self::id('emp-jansen'), $proposal['employeeId']);
		self::assertSame('step-increase', $proposal['adjustmentKind']);
		self::assertSame(2, $proposal['fromStep']);
		self::assertSame(3, $proposal['toStep']);
		self::assertSame(390000, $proposal['proposedSalary']);
		self::assertSame(self::id('band-a'), $proposal['targetBandId']);
		self::assertSame('2026-03-01', $proposal['effectiveDate']);
		self::assertSame([], RegisterSchemaValidator::errors('CompAdjustment', $proposal), json_encode($proposal));

		$reasons = array_column($result['skipped'], 'reason', 'employeeId');
		self::assertSame('top-of-band', $reasons[self::id('emp-devries')]);
		self::assertSame('step-not-due', $reasons[self::id('emp-bakker')]);
	}//end testADueStepIsProposedAndTheTopAndNotDueAreNot()

	/**
	 * The proposer cannot approve their own batch: nothing is transitioned
	 * and every row is reported refused-self-approval.
	 *
	 * @return void
	 */
	public function testTheProposerCannotApproveTheirOwnBatch(): void {
		$this->service()->proposeForCycle(self::id('cycle-collective'), 'hr-adviseur');
		$result = $this->approver()->approveCycle(self::id('cycle-collective'), 'hr-adviseur');

		self::assertSame(0, $result['approved']);
		self::assertSame(3, $result['refusedSelfApproval']);
		self::assertSame(['refused-self-approval'], array_values(array_unique(array_column($result['rows'], 'status'))));
		self::assertSame([], $this->transitions);
	}//end testTheProposerCannotApproveTheirOwnBatch()

	/**
	 * A second person approves the batch, each through its own guarded
	 * `approve` transition that stamps them as approver. An employee in the
	 * batch never approves their own raise.
	 *
	 * @return void
	 */
	public function testASecondPersonApprovesEachThroughItsOwnTransition(): void {
		$this->service()->proposeForCycle(self::id('cycle-collective'), 'hr-adviseur');
		$result = $this->approver()->approveCycle(self::id('cycle-collective'), 'salarisadmin');

		self::assertSame(3, $result['approved']);
		self::assertCount(3, $this->transitions);
		foreach ($this->transitions as $transition) {
			self::assertSame('approve', $transition['action']);
			self::assertSame(['approvedBy' => 'salarisadmin'], $transition['data']);
		}

		$byBakker = $this->approver()->approveCycle(self::id('cycle-collective'), 'mbakker');
		self::assertSame(0, $byBakker['approved'], 'Nothing is left proposed after the approval.');
	}//end testASecondPersonApprovesEachThroughItsOwnTransition()

	/**
	 * An employee in the batch is skipped for their own raise.
	 *
	 * @return void
	 */
	public function testAnEmployeeIsSkippedForTheirOwnRaise(): void {
		$this->service()->proposeForCycle(self::id('cycle-collective'), 'hr-adviseur');
		$result = $this->approver()->approveCycle(self::id('cycle-collective'), 'sjansen');

		self::assertSame(2, $result['approved']);
		self::assertSame(1, $result['refusedSelfApproval']);
	}//end testAnEmployeeIsSkippedForTheirOwnRaise()

}//end class
