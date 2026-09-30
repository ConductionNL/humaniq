<?php

/**
 * Overtime to be taken off lands on a compensation leave balance once, when
 * the run that settled it is approved.
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
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Listener\PayrollRunApprovedListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OvertimeCreditService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Credit once, create the balance when missing, and fire on draft to approved only.
 */
class OvertimeCreditServiceTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	/** @var array<string, array<string, mixed>> */
	private array $timesheets = [];

	/** @var array<string, array<string, mixed>> */
	private array $balances = [];

	/** @var list<array{payload: array<string, mixed>, schema: string, uuid: ?string}> */
	private array $saved = [];

	protected function setUp(): void {
		$this->timesheets = [
			'ts-1' => ['id' => 'ts-1', 'employeeId' => self::EMPLOYEE, 'period' => '2026-05', 'hours' => 40, 'status' => 'approved', 'payrollRunId' => 'run-may', 'paidInPeriod' => '2026-05', 'overtimeCreditHours' => 6.0],
		];
		$this->balances = [];
	}//end setUp()

	/**
	 * The service over the in-memory store.
	 *
	 * @return OvertimeCreditService
	 */
	private function service(): OvertimeCreditService {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(function (string $schema, array $filters): array {
			$rows = ($schema === 'Timesheet' ? $this->timesheets : $this->balances);
			return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
				foreach ($filters as $key => $value) {
					if (($row[$key] ?? null) !== $value) {
						return false;
					}
				}

				return true;
			}));
		});
		$gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null): object {
			$this->saved[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid];
			$id = ($uuid ?? 'bal-' . count($this->saved));
			if ($schema === 'Timesheet') {
				$this->timesheets[$id] = array_merge($payload, ['id' => $id]);
			} else {
				$this->balances[$id] = array_merge($payload, ['id' => $id]);
			}

			return new \stdClass();
		});

		return new OvertimeCreditService($gateway, new InternalWriteMarker());
	}//end service()

	/**
	 * The first credit creates the compensation balance; a replay credits nothing.
	 *
	 * @return void
	 */
	public function testACreditLandsOnce(): void {
		$service = $this->service();

		self::assertSame(1, $service->creditForRun('run-may'));
		self::assertSame(0, $service->creditForRun('run-may'));

		$balances = array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'LeaveBalance'));
		self::assertCount(1, $balances);
		self::assertSame(['employeeId' => self::EMPLOYEE, 'year' => 2026, 'leaveType' => 'compensation', 'entitledHours' => 6.0], $balances[0]['payload']);
		self::assertSame([], RegisterSchemaValidator::errors('LeaveBalance', $balances[0]['payload']));
		self::assertNotSame('', (string)$this->timesheets['ts-1']['overtimeCreditedAt']);
	}//end testACreditLandsOnce()

	/**
	 * An existing compensation balance grows by the credit.
	 *
	 * @return void
	 */
	public function testAnExistingBalanceGrows(): void {
		$this->balances['bal-c'] = ['id' => 'bal-c', 'employeeId' => self::EMPLOYEE, 'year' => 2026, 'leaveType' => 'compensation', 'entitledHours' => 4.0, 'usedHours' => 1.0];

		$this->service()->creditForRun('run-may');

		self::assertSame('bal-c', $this->saved[0]['uuid']);
		self::assertSame(10.0, $this->saved[0]['payload']['entitledHours']);
		self::assertSame(1.0, $this->saved[0]['payload']['usedHours']);
	}//end testAnExistingBalanceGrows()

	/**
	 * The listener credits when a run moves from draft to approved, and only then.
	 *
	 * @return void
	 */
	public function testTheListenerFiresOnApprovalOnly(): void {
		$credit = $this->createMock(OvertimeCreditService::class);
		$credit->expects(self::once())->method('creditForRun')->with('run-may');
		$listener = new PayrollRunApprovedListener($credit, new NullLogger());

		$listener->handle(new ObjectUpdatedEvent(self::runEntity('approved'), self::runEntity('draft')));
		$listener->handle(new ObjectUpdatedEvent(self::runEntity('approved'), self::runEntity('approved')));
		$listener->handle(new ObjectUpdatedEvent(self::runEntity('draft'), self::runEntity('draft')));
	}//end testTheListenerFiresOnApprovalOnly()

	/**
	 * A payroll run entity.
	 *
	 * @param string $status The status.
	 *
	 * @return ObjectEntity
	 */
	private static function runEntity(string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('run-may');
		$entity->setSchema('payrollrun');
		$entity->setObject(['period' => '2026-05', 'status' => $status]);
		return $entity;
	}//end runEntity()

}//end class
