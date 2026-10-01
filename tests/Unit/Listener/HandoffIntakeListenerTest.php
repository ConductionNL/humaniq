<?php

/**
 * The intake check runs when the bureau's results are received.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\HandoffIntakeListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\PayrollHandoffService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The verzonden to ontvangen edge runs checkIntake over the real service.
 */
class HandoffIntakeListenerTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The subject.
	 *
	 * @var HandoffIntakeListener
	 */
	private HandoffIntakeListener $listener;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$this->listener = new HandoffIntakeListener(handoffs: new PayrollHandoffService(gateway: $gateway, logger: new NullLogger()), logger: new NullLogger());

		$this->store->seed('Employee', 'emp-a', ['firstName' => 'Anna', 'lastName' => 'Smit', 'administrationId' => 'ADM-006', 'startDate' => '2025-01-01', 'nextcloudUserId' => 'anna']);
		$this->store->seed('PayrollHandoff', 'ho-apr', ['administrationId' => 'ADM-006', 'period' => '2026-04', 'status' => 'ontvangen']);
	}//end setUp()

	/**
	 * Receiving the results checks them: Anna's payslip is missing.
	 *
	 * @return void
	 */
	public function testReceivingRunsTheIntakeCheck(): void {
		$this->listener->handle(new ObjectUpdatedEvent(self::handoff('ontvangen'), self::handoff('verzonden')));

		$stored = $this->store->find('ho-apr', schema: 'PayrollHandoff')->getObject();
		self::assertSame(1, $stored['blockingFindings']);
		self::assertSame('missing-payslip', $stored['intakeFindings'][0]['kind']);
	}//end testReceivingRunsTheIntakeCheck()

	/**
	 * Any other edge, a create, or a failing check leaves the handoff alone
	 * and never throws.
	 *
	 * @return void
	 */
	public function testOtherEdgesDoNothing(): void {
		$this->listener->handle(new ObjectUpdatedEvent(self::handoff('verzonden'), self::handoff('klaargezet')));
		$this->listener->handle(new ObjectUpdatedEvent(self::handoff('ontvangen'), self::handoff('ontvangen')));
		$this->listener->handle(new ObjectCreatedEvent(self::handoff('ontvangen')));

		self::assertArrayNotHasKey('blockingFindings', $this->store->find('ho-apr', schema: 'PayrollHandoff')->getObject());

		$failing = $this->createMock(PayrollHandoffService::class);
		$failing->method('checkIntake')->willThrowException(new \RuntimeException('register down'));
		(new HandoffIntakeListener(handoffs: $failing, logger: new NullLogger()))->handle(new ObjectUpdatedEvent(self::handoff('ontvangen'), self::handoff('verzonden')));
		self::assertArrayNotHasKey('blockingFindings', $this->store->find('ho-apr', schema: 'PayrollHandoff')->getObject());
	}//end testOtherEdgesDoNothing()

	/**
	 * A handoff entity.
	 *
	 * @param string $status The status.
	 *
	 * @return ObjectEntity
	 */
	private static function handoff(string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ho-apr');
		$entity->setSchema('payrollhandoff');
		$entity->setObject(['administrationId' => 'ADM-006', 'period' => '2026-04', 'status' => $status]);
		return $entity;
	}//end handoff()

}//end class
