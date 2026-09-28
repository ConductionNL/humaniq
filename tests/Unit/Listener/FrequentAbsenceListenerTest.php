<?php

/**
 * Unit tests for FrequentAbsenceListener and FrequentAbsenceService.
 *
 * A new or reopened sickness case is counted against the administration's
 * threshold and window, the count and the signal are stamped on that case,
 * a reopened case counts once, and the listener's own write does not trigger
 * it again. Driven through the real HoursRegisterGateway over the shared
 * FakeObjectStore, with OpenRegister's event classes, and every stamped case
 * validated against the SickLeaveCase schema in the register fragment.
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\FrequentAbsenceListener;
use OCA\Humaniq\Service\FrequentAbsenceService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The frequent-absence signal.
 */
class FrequentAbsenceListenerTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	/**
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * @var FrequentAbsenceListener
	 */
	private FrequentAbsenceListener $listener;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->marker = new InternalWriteMarker();
		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Demo', 'frequentAbsenceThreshold' => 3, 'frequentAbsenceWindowMonths' => 12]);
		$this->store->seed('SickLeaveCase', 'case-jan', $this->sickCase('2026-01-12', 'hersteld'));
		$this->store->seed('SickLeaveCase', 'case-apr', $this->sickCase('2026-04-20', 'hersteld'));
		$this->store->seed('SickLeaveCase', 'case-2024', $this->sickCase('2024-11-02', 'hersteld'));

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

		$this->listener = new FrequentAbsenceListener(
			service: new FrequentAbsenceService($gateway, $this->marker),
			marker: $this->marker,
			gateway: $gateway,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * A sickness case for the employee.
	 *
	 * @param string $firstSickDay First sick day.
	 * @param string $status gemeld or hersteld.
	 *
	 * @return array<string, mixed>
	 */
	private function sickCase(string $firstSickDay, string $status): array {
		return ['employeeId' => self::EMPLOYEE, 'firstSickDay' => $firstSickDay, 'status' => $status, 'administrationId' => 'ADM-001'];
	}//end sickCase()

	/**
	 * An entity as OpenRegister hands it to a listener.
	 *
	 * @param string $uuid Object id.
	 * @param array<string, mixed> $data Case data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema('SickLeaveCase');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The case as stored.
	 *
	 * @param string $uuid Object id.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(string $uuid): array {
		return $this->store->state->objects['SickLeaveCase'][$uuid];
	}//end stored()

	/**
	 * The third case in twelve months is marked, with the count.
	 *
	 * @return void
	 */
	public function testTheThirdAbsenceInAYearIsSignalled(): void {
		$this->store->seed('SickLeaveCase', 'case-oct', $this->sickCase('2026-10-05', 'gemeld'));

		$this->listener->handle(new ObjectCreatedEvent($this->entity('case-oct', $this->stored('case-oct'))));

		$case = $this->stored('case-oct');
		self::assertSame(3, $case['episodesInWindow']);
		self::assertTrue($case['frequentAbsence']);
		$payload = $case;
		unset($payload['id']);
		self::assertSame([], RegisterSchemaValidator::errors('SickLeaveCase', $payload), json_encode($payload));
	}//end testTheThirdAbsenceInAYearIsSignalled()

	/**
	 * A relapse reopens the April case: the count stays 2, no signal.
	 *
	 * @return void
	 */
	public function testARelapseIsNotANewEpisode(): void {
		$old = $this->entity('case-apr', $this->stored('case-apr'));
		$this->store->seed('SickLeaveCase', 'case-apr', array_merge($this->stored('case-apr'), ['status' => 'gemeld', 'firstSickDay' => '2026-04-20']));

		$this->listener->handle(new ObjectUpdatedEvent($this->entity('case-apr', $this->stored('case-apr')), $old));

		$case = $this->stored('case-apr');
		self::assertSame(2, $case['episodesInWindow']);
		self::assertFalse($case['frequentAbsence']);
	}//end testARelapseIsNotANewEpisode()

	/**
	 * An ordinary edit of a case is not counted, and the listener's own
	 * stamp does not trigger it again.
	 *
	 * @return void
	 */
	public function testOrdinaryEditsAndItsOwnWriteAreIgnored(): void {
		$this->store->seed('SickLeaveCase', 'case-oct', $this->sickCase('2026-10-05', 'gemeld'));
		$before = $this->entity('case-oct', $this->stored('case-oct'));
		$after = $this->entity('case-oct', array_merge($this->stored('case-oct'), ['probleemanalyseDone' => '2026-11-10']));

		$this->listener->handle(new ObjectUpdatedEvent($after, $before));
		self::assertSame([], $this->store->state->saves, 'An edit that is not a reopening is not counted.');

		$this->marker->runInternal(fn () => $this->listener->handle(new ObjectCreatedEvent($this->entity('case-oct', $this->stored('case-oct')))));
		self::assertSame([], $this->store->state->saves, 'A write under the internal marker is ignored.');
	}//end testOrdinaryEditsAndItsOwnWriteAreIgnored()

	/**
	 * Without a threshold on the administration the defaults apply: 3 in 12.
	 *
	 * @return void
	 */
	public function testTheDefaultsAreThreeInTwelveMonths(): void {
		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Demo']);
		$this->store->seed('SickLeaveCase', 'case-oct', $this->sickCase('2026-10-05', 'gemeld'));

		$this->listener->handle(new ObjectCreatedEvent($this->entity('case-oct', $this->stored('case-oct'))));

		self::assertTrue($this->stored('case-oct')['frequentAbsence']);
	}//end testTheDefaultsAreThreeInTwelveMonths()

}//end class
