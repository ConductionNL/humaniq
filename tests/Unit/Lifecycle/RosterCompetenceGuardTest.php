<?php

/**
 * Unit tests for RosterCompetenceGuard.
 *
 * Drives the guard through the REAL RosterCheckService and the REAL
 * CompetenceCheckService, over a fake ObjectService that answers findAll()
 * per schema (the RosterCheckServiceTest fake), so the refusal is decided by
 * the same competence logic `occ humaniq:roster:check` reports with.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\RosterCompetenceGuard;
use OCA\Humaniq\Service\RosterCheckService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishing refuses a roster with an unqualified assignment (humaniq#512).
 */
class RosterCompetenceGuardTest extends TestCase {

	/**
	 * A guard over a real RosterCheckService reading the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
	 * @param string $registerSlug The configured register slug ('' leaves the register unresolved).
	 *
	 * @return RosterCompetenceGuard
	 */
	private function guardWithRows(array $rowsBySchema, string $registerSlug = 'humaniq'): RosterCompetenceGuard {
		$objectService = new class($rowsBySchema) {

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
			 */
			public function __construct(
				private readonly array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($registerSlug);

		return new RosterCompetenceGuard(
			new RosterCheckService($container, $appConfig, $this->createMock(LoggerInterface::class))
		);
	}//end guardWithRows()

	/**
	 * A concept BOA roster with one assignment on a shift requiring
	 * `boa-domein-1`, overridable per test.
	 *
	 * @param array<int, array<string, mixed>> $competences EmployeeCompetence rows.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function boaRoster(array $competences): array {
		return [
			'Roster' => [
				['id' => 'roster-w28', 'period' => '2026-W28', 'status' => 'concept'],
			],
			'RosterAssignment' => [
				['id' => 'ra-1', 'rosterId' => 'roster-w28', 'employeeId' => 'emp-jan', 'shiftId' => 'shift-boa', 'date' => '2026-07-13', 'plannedStart' => '2026-07-13T22:00:00', 'plannedEnd' => '2026-07-14T06:00:00', 'plannedBreakMinutes' => 30],
			],
			'Shift' => [
				['id' => 'shift-boa', 'name' => 'Nachtcontrole horeca', 'startTime' => '22:00', 'endTime' => '06:00', 'breakMinutes' => 30, 'requiredCompetences' => ['boa-domein-1']],
			],
			'EmployeeCompetence' => $competences,
		];
	}//end boaRoster()

	/**
	 * The live-check case of #512: the employee on the BOA shift holds no
	 * `boa-domein-1`, so publiceren is refused and the refusal names the
	 * employee, the date and the competence.
	 *
	 * @return void
	 */
	public function testPublishingARosterWithAnUnqualifiedAssignmentIsRefused(): void {
		$guard = $this->guardWithRows($this->boaRoster([]));

		$result = $guard->check(['id' => 'roster-w28', 'period' => '2026-W28', 'status' => 'gepubliceerd'], 'publiceren', 'planner');

		$this->assertFalse($result->isAllowed());
		$message = (string)$result->getMessage();
		$this->assertStringContainsString('niet worden gepubliceerd', $message);
		$this->assertStringContainsString('emp-jan', $message);
		$this->assertStringContainsString('2026-07-13', $message);
		$this->assertStringContainsString('boa-domein-1', $message);
	}//end testPublishingARosterWithAnUnqualifiedAssignmentIsRefused()

	/**
	 * Control: the same roster with a valid `boa-domein-1` on that date
	 * publishes.
	 *
	 * @return void
	 */
	public function testPublishingARosterWhoseAssignmentsAreQualifiedIsAllowed(): void {
		$guard = $this->guardWithRows(
			$this->boaRoster([['employeeId' => 'emp-jan', 'competenceCode' => 'boa-domein-1', 'issuedOn' => '2025-01-01', 'validUntil' => '2027-01-01']])
		);

		$result = $guard->check(['id' => 'roster-w28', 'status' => 'gepubliceerd'], 'publiceren', 'planner');

		$this->assertTrue($result->isAllowed());
	}//end testPublishingARosterWhoseAssignmentsAreQualifiedIsAllowed()

	/**
	 * A competence that expired the day before the shift refuses too: the
	 * date decides, not the record.
	 *
	 * @return void
	 */
	public function testACompetenceExpiredBeforeTheShiftDateRefuses(): void {
		$guard = $this->guardWithRows(
			$this->boaRoster([['employeeId' => 'emp-jan', 'competenceCode' => 'boa-domein-1', 'issuedOn' => '2025-01-01', 'validUntil' => '2026-07-12']])
		);

		$result = $guard->check(['id' => 'roster-w28'], 'publiceren', 'planner');

		$this->assertFalse($result->isAllowed());
	}//end testACompetenceExpiredBeforeTheShiftDateRefuses()

	/**
	 * Fail closed: no id, an unknown roster and an unresolved register all
	 * deny rather than publish unchecked.
	 *
	 * @return void
	 */
	public function testFailsClosedWhenTheRosterCannotBeChecked(): void {
		$guard = $this->guardWithRows($this->boaRoster([]));
		$this->assertFalse($guard->check(['status' => 'gepubliceerd'], 'publiceren', 'planner')->isAllowed());
		$this->assertFalse($guard->check(['id' => 'roster-unknown'], 'publiceren', 'planner')->isAllowed());

		$unresolved = $this->guardWithRows([], '');
		$result = $unresolved->check(['id' => 'roster-w28'], 'publiceren', 'planner');
		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('niet worden gecontroleerd', (string)$result->getMessage());
	}//end testFailsClosedWhenTheRosterCannotBeChecked()

}//end class
