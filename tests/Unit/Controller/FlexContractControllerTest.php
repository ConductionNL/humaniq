<?php

/**
 * Unit tests for the contract chain and on-call overview endpoints.
 *
 * Real ContractChainService, OnCallAverageService and HumaniqRoles; the
 * register reads, the RBAC reader and the group manager are doubles.
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
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\FlexContractController;
use OCA\Humaniq\Service\ContractChainService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\OnCallAverageService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FlexContractController.
 */
class FlexContractControllerTest extends TestCase {

	/**
	 * The contracts, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $contracts = [];

	/**
	 * Ids the caller may read.
	 *
	 * @var array<int, string>
	 */
	private array $readable = [];

	/**
	 * Build the controller for a caller.
	 *
	 * @param bool $isHr Whether the caller is in the HR group.
	 *
	 * @return FlexContractController
	 */
	private function controller(bool $isHr = true): FlexContractController {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(
			function (string $schema, array $filters): array {
				if ($schema === 'Timesheet') {
					return [['id' => 'ts-ok', 'status' => 'approved']];
				}

				return array_values(
					array_filter(
						$this->contracts,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$gateway->method('loadAll')->willReturn(
			[
				['employeeId' => 'emp-o', 'timesheetId' => 'ts-ok', 'date' => '2026-01-05', 'hours' => 14],
				['employeeId' => 'emp-o', 'timesheetId' => 'ts-ok', 'date' => '2026-01-12', 'hours' => 14],
			]
		);
		$gateway->method('findObjectData')->willReturn(['firstName' => 'Ahmed', 'lastName' => 'El Idrissi', 'employeeNumber' => 'E-77']);

		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(
			fn (string $id): ?array => in_array($id, $this->readable, true) === true ? $this->contracts[$id] : null
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn($isHr);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new FlexContractController(
			$this->createMock(IRequest::class),
			$gateway,
			$rbac,
			new ContractChainService(),
			new OnCallAverageService(),
			new HumaniqRoles($groups),
			$session,
			$l10n
		);
	}//end controller()

	/**
	 * Seed Sanne's three contracts and Ahmed's on-call contract.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->contracts = [
			'c1' => ['id' => 'c1', 'employeeId' => 'emp-s', 'type' => 'temporary', 'startDate' => '2024-03-01', 'endDate' => '2024-12-31'],
			'c2' => ['id' => 'c2', 'employeeId' => 'emp-s', 'type' => 'temporary', 'startDate' => '2025-02-01', 'endDate' => '2025-12-31'],
			'c3' => ['id' => 'c3', 'employeeId' => 'emp-s', 'type' => 'temporary', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
			'p1' => ['id' => 'p1', 'employeeId' => 'emp-p', 'type' => 'permanent', 'startDate' => '2020-01-01'],
			'o1' => ['id' => 'o1', 'employeeId' => 'emp-o', 'type' => 'oproep', 'startDate' => '2025-06-01'],
		];
		$this->readable = ['c3', 'p1', 'o1'];
	}//end setUp()

	/**
	 * The third contract reads 3 of 3 and the renewal date, with one summary row.
	 *
	 * @return void
	 */
	public function testTheThirdContractsChainIsThreeOfThree(): void {
		$response = $this->controller()->chain('c3');
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(3, $data['position']);
		self::assertSame(3, $data['maxContracts']);
		self::assertSame('2027-01-01', $data['turnsPermanentOn']);
		self::assertCount(1, $data['rows']);
	}//end testTheThirdContractsChainIsThreeOfThree()

	/**
	 * A permanent contract has no chain rows; an unreadable contract is 404.
	 *
	 * @return void
	 */
	public function testAPermanentContractHasNoRowsAndAnUnreadableOneIsNotFound(): void {
		self::assertSame([], $this->controller()->chain('p1')->getData()['rows']);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->chain('c1')->getStatus());
	}//end testAPermanentContractHasNoRowsAndAnUnreadableOneIsNotFound()

	/**
	 * HR reads the on-call average; the CSV carries the same figure.
	 *
	 * @return void
	 */
	public function testHrReadsTheOnCallAverageAndTheCsvCarriesIt(): void {
		$response = $this->controller()->onCallAverages('2026-01-05', '2026-01-18');
		self::assertInstanceOf(JSONResponse::class, $response);
		$rows = $response->getData()['rows'];
		self::assertCount(1, $rows);
		self::assertSame('Ahmed El Idrissi', $rows[0]['name']);
		self::assertSame(14.0, $rows[0]['hoursPerWeek']);

		$csv = $this->controller()->onCallAverages('2026-01-05', '2026-01-18', 'csv');
		self::assertInstanceOf(DataDisplayResponse::class, $csv);
		self::assertStringContainsString('"Ahmed El Idrissi","E-77","2026-01-05","2026-01-18","28","14",', $csv->render());
	}//end testHrReadsTheOnCallAverageAndTheCsvCarriesIt()

	/**
	 * Outside HR the overview is refused; a bad window is a 400.
	 *
	 * @return void
	 */
	public function testTheOverviewIsHrOnlyAndNeedsAValidWindow(): void {
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(false)->onCallAverages()->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->onCallAverages('2026-02-01', '2026-01-01')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->onCallAverages('yesterday')->getStatus());
	}//end testTheOverviewIsHrOnlyAndNeedsAValidWindow()
}//end class
