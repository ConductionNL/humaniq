<?php

/**
 * Unit tests for ScenarioMutationListener (reporting-personnel-budget-and-scenarios D4).
 *
 * Runs over the in-memory object store with the real HoursRegisterGateway and
 * the real OpenRegister event classes (stubbed only when OpenRegister is absent).
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
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ScenarioMutationListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
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
 * Tests for the fixed-scenario refusal.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
 */
class ScenarioMutationListenerTest extends TestCase {

	private const FIXED = '11111111-2222-4333-8444-555555555555';

	private const DRAFT = '11111111-2222-4333-8444-666666666666';

	private FakeObjectStore $store;

	/**
	 * Seed one fixed and one draft scenario.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('FormationScenario', self::FIXED, ['name' => 'Begroting 2027 vastgesteld', 'year' => 2027, 'status' => 'vastgesteld', 'administrationId' => 'ADM-001']);
		$this->store->seed('FormationScenario', self::DRAFT, ['name' => 'Begroting 2027 groei', 'year' => 2027, 'status' => 'concept', 'administrationId' => 'ADM-001']);
	}//end setUp()

	/**
	 * A mutation on a fixed scenario is refused, on create and on update.
	 *
	 * @return void
	 */
	public function testAFixedScenarioAcceptsNoMutation(): void {
		$create = new ObjectCreatingEvent($this->entity(['scenarioId' => self::FIXED, 'fteDelta' => 1.0, 'effectiveDate' => '2027-03-01']));
		$this->listener()->handle($create);
		self::assertNotSame([], $create->getErrors());

		$entity = $this->entity(['scenarioId' => self::FIXED, 'fteDelta' => 2.0, 'effectiveDate' => '2027-03-01']);
		$update = new ObjectUpdatingEvent($entity, $entity);
		$this->listener()->handle($update);
		self::assertNotSame([], $update->getErrors());
	}//end testAFixedScenarioAcceptsNoMutation()

	/**
	 * A mutation on a draft scenario is saved and takes the scenario's administration.
	 *
	 * @return void
	 */
	public function testADraftScenarioAcceptsAMutation(): void {
		$event = new ObjectCreatingEvent($this->entity(['scenarioId' => self::DRAFT, 'fteDelta' => 2.0, 'effectiveDate' => '2027-03-01']));
		$this->listener()->handle($event);

		self::assertSame([], $event->getErrors());
		self::assertSame(['administrationId' => 'ADM-001'], $event->getModifiedData());
	}//end testADraftScenarioAcceptsAMutation()

	/**
	 * A mutation naming no existing scenario is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownScenarioIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['scenarioId' => 'nope', 'fteDelta' => 1.0, 'effectiveDate' => '2027-03-01']));
		$this->listener()->handle($event);

		self::assertNotSame([], $event->getErrors());
	}//end testAnUnknownScenarioIsRefused()

	/**
	 * A ScenarioMutation entity.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('mut-1');
		$entity->setSchema('ScenarioMutation');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The listener over the store.
	 *
	 * @return ScenarioMutationListener
	 */
	private function listener(): ScenarioMutationListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return new ScenarioMutationListener(
			new HoursRegisterGateway(
				container: new FakeContainer([
					'OCA\OpenRegister\Service\ObjectService' => $this->store,
					'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
				]),
				settingsService: $settings,
				orgResolution: new OrgResolutionService()
			)
		);
	}//end listener()

}//end class
