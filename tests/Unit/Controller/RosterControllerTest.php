<?php

/**
 * Unit tests for RosterController.
 *
 * Runs the REAL RosterCheckService over a fake ObjectService that answers
 * find() for the RBAC probe and findAll() per schema, so the endpoint's rows
 * are the same findings `occ humaniq:roster:check` reports.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\RosterController;
use OCA\Humaniq\Service\RosterCheckService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The roster's leave rows and the ATW check endpoint.
 */
class RosterControllerTest extends TestCase {

	/**
	 * A controller over a fake ObjectService.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
	 * @param bool $readable Whether the caller's RBAC resolves the roster.
	 *
	 * @return RosterController
	 */
	private function controller(array $rowsBySchema, bool $readable = true): RosterController {
		$objectService = new class($rowsBySchema, $readable) {

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
			 * @param bool $readable Whether find() resolves.
			 */
			public function __construct(
				private readonly array $rowsBySchema,
				private readonly bool $readable,
			) {

			}//end __construct()

			/**
			 * @param string $id The object id.
			 * @param string $register The register slug.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string $register = '', string $schema = ''): ?array {
				if ($this->readable === false) {
					throw new \RuntimeException('Forbidden');
				}

				foreach (($this->rowsBySchema[$schema] ?? []) as $row) {
					if (($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

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
		$appConfig->method('getValueString')->willReturn('humaniq');

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		$logger = $this->createMock(LoggerInterface::class);

		return new RosterController(
			$this->createMock(IRequest::class),
			$container,
			new RosterCheckService($container, $appConfig, $logger),
			$settings,
			$logger
		);
	}//end controller()

	/**
	 * A roster with Jan on approved leave on Monday and Piet sick on Tuesday.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function rows(): array {
		return [
			'Roster' => [['id' => 'roster-w28', 'period' => '2026-W28', 'status' => 'concept']],
			'RosterAssignment' => [
				['id' => 'ra-1', 'rosterId' => 'roster-w28', 'employeeId' => 'emp-jan', 'shiftId' => 'shift-1', 'date' => '2026-07-13', 'plannedStart' => '2026-07-13T08:00:00', 'plannedEnd' => '2026-07-13T16:00:00', 'plannedBreakMinutes' => 30],
				['id' => 'ra-2', 'rosterId' => 'roster-w28', 'employeeId' => 'emp-piet', 'shiftId' => 'shift-1', 'date' => '2026-07-14', 'plannedStart' => '2026-07-14T08:00:00', 'plannedEnd' => '2026-07-14T16:00:00', 'plannedBreakMinutes' => 30],
				['id' => 'ra-3', 'rosterId' => 'roster-w28', 'employeeId' => 'emp-kees', 'shiftId' => 'shift-1', 'date' => '2026-07-14', 'plannedStart' => '2026-07-14T08:00:00', 'plannedEnd' => '2026-07-14T16:00:00', 'plannedBreakMinutes' => 30],
			],
			'Shift' => [['id' => 'shift-1', 'name' => 'Dagdienst', 'startTime' => '08:00', 'endTime' => '16:00', 'breakMinutes' => 30]],
			'LeaveRequest' => [['id' => 'lr-1', 'employeeId' => 'emp-jan', 'status' => 'approved', 'startDate' => '2026-07-13', 'endDate' => '2026-07-17', 'leaveType' => 'zorgverlof']],
			'SickLeaveCase' => [['id' => 'sick-1', 'employeeId' => 'emp-piet', 'status' => 'gemeld', 'firstSickDay' => '2026-07-10']],
		];
	}//end rows()

	/**
	 * The leave rows name the date, the employee and whether they are on
	 * leave or absent; nobody without an absence is listed.
	 *
	 * @return void
	 */
	public function testTheRostersLeaveRowsListWhoIsPlannedWhileAway(): void {
		$response = $this->controller($this->rows())->leave('roster-w28');

		$this->assertSame(200, $response->getStatus());
		$rows = $response->getData()['rows'];
		$this->assertCount(2, $rows);
		$this->assertSame(['2026-07-13', '2026-07-14'], array_column($rows, 'date'));
		$this->assertSame(['emp-jan', 'emp-piet'], array_column($rows, 'employeeId'));
		$this->assertSame(['On approved leave', 'Absent'], array_column($rows, 'absence'));
		$this->assertSame(['Blocks publishing', 'Reported'], array_column($rows, 'effect'));
		$this->assertSame(['ra-1', 'ra-2'], array_column($rows, 'assignmentId'));
		$this->assertStringNotContainsString('zorgverlof', (string)json_encode($rows));
	}//end testTheRostersLeaveRowsListWhoIsPlannedWhileAway()

	/**
	 * A roster the caller cannot read answers 404, and a blank id 400.
	 *
	 * @return void
	 */
	public function testAnUnreadableRosterIs404AndABlankIdIs400(): void {
		$this->assertSame(404, $this->controller($this->rows(), false)->leave('roster-w28')->getStatus());
		$this->assertSame(404, $this->controller($this->rows())->leave('roster-unknown')->getStatus());
		$this->assertSame(400, $this->controller($this->rows())->leave('  ')->getStatus());
	}//end testAnUnreadableRosterIs404AndABlankIdIs400()

	/**
	 * The ATW check endpoint carries the leave findings in the same report.
	 *
	 * @return void
	 */
	public function testTheCheckEndpointReportsLeaveBesideWorkingTime(): void {
		$response = $this->controller($this->rows())->check('roster-w28');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['leaveFindings']);
		$this->assertSame(400, $this->controller($this->rows())->check(null)->getStatus());
		$this->assertSame(404, $this->controller($this->rows(), false)->check('roster-w28')->getStatus());
	}//end testTheCheckEndpointReportsLeaveBesideWorkingTime()

}//end class
