<?php

/**
 * ExpensePayoutFieldsListener unit tests
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/changes/approved-claim-keeps-its-amounts/specs/humaniq-expenses/spec.md#Requirement:-An-approved-expense-claim-SHALL-keep-the-amounts-it-was-approved-for
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ExpensePayoutFieldsListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * A decided claim keeps what payroll pays from.
 *
 * @covers \OCA\Humaniq\Listener\ExpensePayoutFieldsListener
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 */
class ExpensePayoutFieldsListenerTest extends TestCase {

	/**
	 * An approved claim as stored.
	 *
	 * @var array<string, mixed>
	 */
	private const APPROVED = [
		'employeeId' => 'emp-tim', 'status' => 'approved', 'category' => 'travel', 'title' => 'Klantbezoek',
		'amount' => 34.5, 'taxFreeAmount' => 34.5, 'taxableAmount' => 0.0, 'currency' => 'EUR',
		'approvedBy' => 'sanne', 'approvedAt' => '2026-10-01T09:00:00Z', 'reimbursementRoute' => 'payroll',
		'payrollRunId' => null, 'paidInPeriod' => null,
	];

	/**
	 * What a forged write sends.
	 *
	 * @var array<string, mixed>
	 */
	private const FORGED = [
		'amount' => 3450.0, 'taxFreeAmount' => 3450.0, 'taxableAmount' => 0.0, 'currency' => 'USD',
		'approvedBy' => 'tim', 'approvedAt' => '2026-11-30T09:00:00Z', 'payrollRunId' => 'run-x', 'paidInPeriod' => '2026-11',
		'reimbursementRoute' => 'direct',
	];

	/**
	 * The write marker the listener reads.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * Set up the marker.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->marker = new InternalWriteMarker();
	}//end setUp()

	/**
	 * The listener.
	 *
	 * @return ExpensePayoutFieldsListener
	 */
	private function listener(): ExpensePayoutFieldsListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => new FakeObjectStore(),
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);

		return new ExpensePayoutFieldsListener(gateway: $gateway, marker: $this->marker);
	}//end listener()

	/**
	 * An entity of a schema.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $data The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ex-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The modified data of an update from one stored claim to a saved one.
	 *
	 * @param array<string, mixed> $stored The stored claim.
	 * @param array<string, mixed> $saved The saved claim.
	 *
	 * @return array<string, mixed>
	 */
	private function updated(array $stored, array $saved): array {
		$event = new ObjectUpdatingEvent($this->entity('Expense', $saved), $this->entity('Expense', $stored));
		$event->setModifiedData(['earlier' => 'kept']);
		$this->listener()->handle($event);

		return $event->getModifiedData();
	}//end updated()

	/**
	 * An edit of an approved or reimbursed claim keeps what is paid, when it
	 * was approved and by whom, and the run fields.
	 *
	 * @return void
	 */
	public function testADecidedClaimKeepsWhatIsPaid(): void {
		foreach (['approved', 'reimbursed', ' approved '] as $status) {
			$stored = array_merge(self::APPROVED, ['status' => $status, 'payrollRunId' => 'run-1', 'paidInPeriod' => '2026-10']);
			$saved = array_merge($stored, self::FORGED, ['status' => trim($status), 'title' => 'Edited']);

			self::assertSame(
				[
					'earlier' => 'kept', 'payrollRunId' => 'run-1', 'paidInPeriod' => '2026-10',
					'amount' => 34.5, 'taxFreeAmount' => 34.5, 'taxableAmount' => 0.0, 'currency' => 'EUR',
					'approvedBy' => 'sanne', 'approvedAt' => '2026-10-01T09:00:00Z', 'reimbursementRoute' => 'payroll',
				],
				$this->updated($stored, $saved),
				$status
			);
		}
	}//end testADecidedClaimKeepsWhatIsPaid()

	/**
	 * A value missing from the stored claim stays empty, never the client's.
	 *
	 * @return void
	 */
	public function testAMissingStoredValueStaysEmpty(): void {
		$stored = ['employeeId' => 'emp-tim', 'status' => 'approved', 'amount' => 10.0];
		$stamped = $this->updated($stored, array_merge($stored, self::FORGED));

		self::assertSame(10.0, $stamped['amount']);
		self::assertNull($stamped['taxableAmount']);
		self::assertNull($stamped['approvedAt']);
		self::assertNull($stamped['payrollRunId']);
		self::assertNull($stamped['reimbursementRoute']);
	}//end testAMissingStoredValueStaysEmpty()

	/**
	 * The approve write keeps the submitted amounts and leaves the decision
	 * stamps to the write itself.
	 *
	 * @return void
	 */
	public function testTheApproveWriteKeepsTheSubmittedAmounts(): void {
		$stored = array_merge(self::APPROVED, ['status' => 'submitted', 'approvedBy' => null, 'approvedAt' => null]);

		self::assertSame(
			[
				'earlier' => 'kept', 'payrollRunId' => null, 'paidInPeriod' => null,
				'amount' => 34.5, 'taxFreeAmount' => 34.5, 'taxableAmount' => 0.0, 'currency' => 'EUR',
			],
			$this->updated($stored, array_merge($stored, self::FORGED, ['status' => 'approved']))
		);
	}//end testTheApproveWriteKeepsTheSubmittedAmounts()

	/**
	 * An open claim may change its amounts; only the run fields are kept.
	 *
	 * @return void
	 */
	public function testAnOpenClaimMayChangeItsAmounts(): void {
		foreach (['draft', 'submitted', 'rejected'] as $status) {
			$stored = array_merge(self::APPROVED, ['status' => $status]);
			$next = ($status === 'submitted' ? 'rejected' : 'submitted');

			self::assertSame(
				['earlier' => 'kept', 'payrollRunId' => null, 'paidInPeriod' => null],
				$this->updated($stored, array_merge($stored, self::FORGED, ['status' => $next])),
				$status
			);
		}
	}//end testAnOpenClaimMayChangeItsAmounts()

	/**
	 * Only humaniq's own writes set the run fields; a new claim starts
	 * without them.
	 *
	 * @return void
	 */
	public function testOnlyHumaniqSetsTheRunFields(): void {
		foreach (['draft', ' submitted ', ''] as $status) {
			$create = new ObjectCreatingEvent($this->entity('Expense', array_merge(self::FORGED, ['status' => $status])));
			$create->setModifiedData(['amountSource' => 'entered']);
			$this->listener()->handle($create);
			self::assertFalse($create->isPropagationStopped(), $status);
			self::assertSame(['amountSource' => 'entered', 'payrollRunId' => null, 'paidInPeriod' => null, 'approvedBy' => null, 'approvedAt' => null], $create->getModifiedData(), $status);
		}

		$internalCreate = new ObjectCreatingEvent($this->entity('Expense', self::APPROVED));
		$this->marker->runInternal(fn () => $this->listener()->handle($internalCreate));
		self::assertFalse($internalCreate->isPropagationStopped());
		self::assertSame([], $internalCreate->getModifiedData());

		$internal = new ObjectUpdatingEvent($this->entity('Expense', array_merge(self::APPROVED, self::FORGED)), $this->entity('Expense', self::APPROVED));
		$this->marker->runInternal(fn () => $this->listener()->handle($internal));
		self::assertSame([], $internal->getModifiedData());

		$other = new ObjectCreatingEvent($this->entity('CommuteArrangement', ['payrollRunId' => 'run-x']));
		$this->listener()->handle($other);
		self::assertSame([], $other->getModifiedData());
	}//end testOnlyHumaniqSetsTheRunFields()

	/**
	 * A create in any status other than draft or submitted is refused.
	 *
	 * @return void
	 */
	public function testACreateInADecidedStatusIsRefused(): void {
		foreach (['approved', 'reimbursed', 'rejected', 'paid'] as $status) {
			$create = new ObjectCreatingEvent($this->entity('Expense', array_merge(self::APPROVED, ['status' => $status])));
			$this->listener()->handle($create);

			self::assertTrue($create->isPropagationStopped(), $status);
			self::assertSame(['message' => 'Een nieuwe declaratie begint als concept of als ingediend.'], $create->getErrors(), $status);
		}
	}//end testACreateInADecidedStatusIsRefused()

	/**
	 * The register import of shipped example claims is not refused and keeps
	 * its values.
	 *
	 * @return void
	 */
	public function testAShippedImportMayCreateADecidedClaim(): void {
		$create = new ObjectCreatingEvent($this->entity('Expense', self::APPROVED));
		$this->marker->runShippedImport(fn () => $this->listener()->handle($create));

		self::assertFalse($create->isPropagationStopped());
		self::assertSame([], $create->getModifiedData());
	}//end testAShippedImportMayCreateADecidedClaim()

	/**
	 * A shipped import only lifts the create check: an update during it
	 * still keeps the stored payout fields of a decided claim.
	 *
	 * @return void
	 */
	public function testAnUpdateDuringAShippedImportKeepsWhatIsPaid(): void {
		$stored = array_merge(self::APPROVED, ['payrollRunId' => 'run-1', 'paidInPeriod' => '2026-10']);
		$event = new ObjectUpdatingEvent($this->entity('Expense', array_merge($stored, self::FORGED)), $this->entity('Expense', $stored));
		$this->marker->runShippedImport(fn () => $this->listener()->handle($event));

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(
			[
				'payrollRunId' => 'run-1', 'paidInPeriod' => '2026-10',
				'amount' => 34.5, 'taxFreeAmount' => 34.5, 'taxableAmount' => 0.0, 'currency' => 'EUR',
				'approvedBy' => 'sanne', 'approvedAt' => '2026-10-01T09:00:00Z', 'reimbursementRoute' => 'payroll',
			],
			$event->getModifiedData()
		);
	}//end testAnUpdateDuringAShippedImportKeepsWhatIsPaid()

}//end class
