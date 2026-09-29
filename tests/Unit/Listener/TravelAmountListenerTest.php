<?php

/**
 * TravelAmountListenerTest
 *
 * The pre-save stamping of expenses-travel-calculation, run through the real
 * OpenRegister pre-save events, the real rule corpus rate and the register's
 * own schemas: every stamped payload is validated against the fragment.
 *
 * @category Tests
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\TravelAmountListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TravelAllowanceCalculator;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Claims get their amount from the distance; arrangements their allowance.
 */
class TravelAmountListenerTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	/**
	 * The register double.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The employer rate the settings double answers.
	 *
	 * @var float|null
	 */
	private ?float $employerRate = null;

	/**
	 * Set up the listener over the fake register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('Employee', self::EMPLOYEE, ['firstName' => 'Piet', 'lastName' => 'Jansen', 'nextcloudUserId' => 'pjansen', 'administrationId' => 'ADM-001']);
	}//end setUp()

	/**
	 * The listener under test, with the current employer rate.
	 *
	 * @return TravelAmountListener
	 */
	private function listener(): TravelAmountListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('getMileageRatePerKm')->willReturn($this->employerRate);
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);

		return new TravelAmountListener(
			gateway: $gateway,
			calculator: new TravelAllowanceCalculator(),
			settings: $settings,
			marker: new InternalWriteMarker(),
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * An entity of a schema.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A travel claim with a distance and no amount.
	 *
	 * @return array<string, mixed>
	 */
	private function mileageClaim(): array {
		return ['employeeId' => self::EMPLOYEE, 'title' => 'Klantbezoek', 'category' => 'travel', 'travelType' => 'business', 'distanceKm' => 150, 'transportMode' => 'car', 'fuelType' => 'gasoline', 'status' => 'draft'];
	}//end mileageClaim()

	/**
	 * Scenario: an employee enters only the distance, 34.50 all tax free.
	 *
	 * @return void
	 */
	public function testAClaimWithOnlyADistanceGetsItsAmount(): void {
		$event = new ObjectCreatingEvent($this->entity('Expense', $this->mileageClaim()));
		$this->listener()->handle($event);

		$stamped = $event->getModifiedData();
		self::assertSame(['amount' => 34.5, 'taxFreeAmount' => 34.5, 'taxableAmount' => 0.0, 'ratePerKm' => 0.23, 'amountSource' => 'calculated'], $stamped);
		self::assertSame([], RegisterSchemaValidator::errors('Expense', array_merge($this->mileageClaim(), $stamped)));
	}//end testAClaimWithOnlyADistanceGetsItsAmount()

	/**
	 * Scenario: the employer pays 0.30, 45.00 of which 10.50 is taxable.
	 *
	 * @return void
	 */
	public function testAnEmployerRateAboveTheTaxFreeRateSplitsTheClaim(): void {
		$this->employerRate = 0.30;
		$event = new ObjectUpdatingEvent(
			$this->entity('Expense', array_merge($this->mileageClaim(), ['amount' => 1.0, 'status' => 'submitted'])),
			$this->entity('Expense', $this->mileageClaim())
		);
		$this->listener()->handle($event);

		self::assertSame(['amount' => 45.0, 'taxFreeAmount' => 34.5, 'taxableAmount' => 10.5, 'ratePerKm' => 0.3, 'amountSource' => 'calculated'], $event->getModifiedData());
	}//end testAnEmployerRateAboveTheTaxFreeRateSplitsTheClaim()

	/**
	 * A claim without a distance keeps its typed amount; one without an amount
	 * or a distance is refused.
	 *
	 * @return void
	 */
	public function testAClaimWithoutADistanceKeepsItsTypedAmount(): void {
		$lunch = ['employeeId' => self::EMPLOYEE, 'title' => 'Lunch', 'category' => 'meals', 'amount' => 32.75, 'status' => 'draft'];
		$event = new ObjectCreatingEvent($this->entity('Expense', $lunch));
		$this->listener()->handle($event);
		self::assertSame(['amountSource' => 'entered'], $event->getModifiedData());
		self::assertFalse($event->isPropagationStopped());

		unset($lunch['amount']);
		$refused = new ObjectCreatingEvent($this->entity('Expense', $lunch));
		$this->listener()->handle($refused);
		self::assertTrue($refused->isPropagationStopped());
		self::assertNotEmpty($refused->getErrors());
	}//end testAClaimWithoutADistanceKeepsItsTypedAmount()

	/**
	 * An approved claim keeps the rate it was approved with.
	 *
	 * @return void
	 */
	public function testAnApprovedClaimIsNotRecalculated(): void {
		$this->employerRate = 0.30;
		$approved = array_merge($this->mileageClaim(), ['amount' => 34.5, 'ratePerKm' => 0.23, 'status' => 'approved']);
		$event = new ObjectUpdatingEvent($this->entity('Expense', array_merge($approved, ['status' => 'reimbursed'])), $this->entity('Expense', $approved));
		$this->listener()->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnApprovedClaimIsNotRecalculated()

	/**
	 * Scenario: the four-day commute of 18 km gives 118.13 a month, and the
	 * arrangement carries the employee's account for the approval guard.
	 *
	 * @return void
	 */
	public function testAnArrangementGetsItsMonthlyAllowanceAndTheEmployeesAccount(): void {
		$arrangement = ['employeeId' => self::EMPLOYEE, 'originPostcode' => '2611 AB', 'destinationPostcode' => '2628 CD', 'distanceKmOneWay' => 18, 'daysPerWeek' => 4, 'transportMode' => 'car', 'fuelType' => 'gasoline', 'startDate' => '2026-01-01', 'status' => 'submitted'];
		$event = new ObjectCreatingEvent($this->entity('CommuteArrangement', $arrangement));
		$this->listener()->handle($event);

		$stamped = $event->getModifiedData();
		self::assertSame(118.13, $stamped['monthlyAllowance']);
		self::assertSame(118.13, $stamped['taxFreeMonthly']);
		self::assertSame(0.0, $stamped['taxableMonthly']);
		self::assertSame('pjansen', $stamped['userId']);
		self::assertSame('ADM-001', $stamped['administrationId']);
		self::assertSame('manual', $stamped['distanceSource']);
		self::assertSame([], RegisterSchemaValidator::errors('CommuteArrangement', array_merge($arrangement, $stamped)));
	}//end testAnArrangementGetsItsMonthlyAllowanceAndTheEmployeesAccount()

}//end class
