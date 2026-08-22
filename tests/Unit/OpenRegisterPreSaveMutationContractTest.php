<?php

/**
 * OpenRegister pre-save mutation contract (hours-process-redesign task V1)
 *
 * The hours-process redesign's Decisions 4 and 5 stand on ONE load-bearing
 * assumption: a listener on OpenRegister's pre-save `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` can hand back modified payload data (via
 * `setModifiedData()`) and that mutation PERSISTS into the saved object.
 * MagicMapper::insertObjectEntity()/updateObjectEntity() read the event's
 * modified data back and merge it into the entity before serialization — but
 * a code read is not a proof, so this test drives a real save through the
 * shipping ObjectService path against the locally installed OpenRegister and
 * asserts the persisted bytes.
 *
 * It carries its own must-fail control: the same write WITHOUT the listener
 * mutation must persist the client value untouched — a check that cannot
 * fail proves nothing.
 *
 * Integration-leaning: it needs a running Nextcloud with OpenRegister and the
 * imported hrmq register, so it SKIPS (loudly, with the reason) in the
 * standalone bare-container CI run. The container run
 * (`docker exec -u www-data -w /var/www/html/custom_apps/humaniq nextcloud
 * php vendor/bin/phpunit tests/Unit/OpenRegisterPreSaveMutationContractTest.php`)
 * is the gate — verify it reports the tests as RUN, not skipped, before
 * trusting it.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Proves (or disproves) that pre-save event payload mutation persists.
 */
class OpenRegisterPreSaveMutationContractTest extends TestCase {

	/**
	 * Register slug the probe object lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'hrmq';

	/**
	 * Schema slug the probe object lives in.
	 *
	 * @var string
	 */
	private const SCHEMA = 'Timesheet';

	/**
	 * Sentinel value the creating-hook rewrites the description to.
	 *
	 * @var string
	 */
	private const CREATE_MUTATED = 'v1-probe MUTATED-ON-CREATING';

	/**
	 * Sentinel value the updating-hook rewrites the description to.
	 *
	 * @var string
	 */
	private const UPDATE_MUTATED = 'v1-probe MUTATED-ON-UPDATING';

	/**
	 * The live ObjectService, resolved from the server container.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * The live event dispatcher, for runtime listener (de)registration.
	 *
	 * @var \OCP\EventDispatcher\IEventDispatcher|null
	 */
	private ?\OCP\EventDispatcher\IEventDispatcher $dispatcher = null;

	/**
	 * Closures registered on the dispatcher, keyed by event class, so
	 * tearDown can remove them even when an assertion throws mid-test.
	 *
	 * @var array<string, callable>
	 */
	private array $registeredListeners = [];

	/**
	 * UUIDs of probe objects created during the test, deleted in tearDown.
	 *
	 * @var array<int, string>
	 */
	private array $createdUuids = [];

	/**
	 * Resolve the live services or skip when this is a standalone run.
	 *
	 * The bootstrap declares a NAME-ONLY ObjectService stub in standalone
	 * mode, so `class_exists` alone cannot tell a live install from a stub —
	 * probe for the real `saveObject` method instead.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists('\\OC') === false || method_exists('\\OCP\\Server', 'get') === false) {
			$this->markTestSkipped('No Nextcloud server bootstrapped — pre-save mutation contract needs the container run.');
		}

		try {
			$service = \OCP\Server::get('OCA\\OpenRegister\\Service\\ObjectService');
			$this->dispatcher = \OCP\Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
		} catch (\Throwable $e) {
			$this->markTestSkipped('OpenRegister ObjectService unresolvable: ' . $e->getMessage());
		}

		if (method_exists($service, 'saveObject') === false) {
			$this->markTestSkipped('Resolved ObjectService is the name-only test stub — container run required.');
		}

		$this->objectService = $service;
	}//end setUp()

	/**
	 * Remove runtime listeners and delete probe objects — even on failure.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->registeredListeners as $eventClass => $listener) {
			try {
				$this->dispatcher?->removeListener($eventClass, $listener);
			} catch (\Throwable $e) {
				// Removal is best-effort; the closures no-op without their sentinel.
			}
		}

		$this->registeredListeners = [];

		foreach ($this->createdUuids as $uuid) {
			try {
				$this->objectService?->deleteObject(
					uuid: $uuid,
					register: self::REGISTER,
					schema: self::SCHEMA,
					_rbac: false
				);
			} catch (\Throwable $e) {
				// Best-effort cleanup of the throwaway probe row.
			}
		}

		$this->createdUuids = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * The full proof, in one ordered test: creating-hook mutation persists,
	 * updating-hook mutation persists, and the control write WITHOUT a
	 * mutation persists the client value untouched (must-fail control).
	 *
	 * One test on purpose: the three phases share one probe object and their
	 * ORDER is the argument — a control that ran against a different object
	 * would not prove the sentinel of the mutated phase came from the hook.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
	 */
	public function testPreSaveMutationPersistsAndControlWriteDoesNot(): void {
		// Phase 1 — CREATE: hook rewrites description; assert the PERSISTED row carries it.
		$creating = function (object $event): void {
			if (($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) === false) {
				return;
			}

			$data = $event->getObject()->getObject() ?? [];
			if (($data['description'] ?? '') === 'v1-probe-create') {
				$event->setModifiedData(['description' => self::CREATE_MUTATED]);
			}
		};
		$this->dispatcher->addListener(\OCA\OpenRegister\Event\ObjectCreatingEvent::class, $creating);
		$this->registeredListeners[\OCA\OpenRegister\Event\ObjectCreatingEvent::class] = $creating;

		$saved = $this->objectService->saveObject(
			object: [
				'employeeId' => '00000000-0000-4000-8000-0000000000a1',
				'period' => '1999-01',
				'hours' => 1,
				'status' => 'draft',
				'description' => 'v1-probe-create',
			],
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_validation: false
		);
		$uuid = (string) $saved->getUuid();
		$this->assertNotSame('', $uuid, 'Probe object did not save.');
		$this->createdUuids[] = $uuid;

		$persisted = $this->readBack($uuid);
		$this->assertSame(
			self::CREATE_MUTATED,
			(string) ($persisted['description'] ?? ''),
			'ObjectCreatingEvent setModifiedData() did NOT propagate into the persisted object — Decision 4 fallback required.'
		);

		// Phase 2 — UPDATE: hook rewrites description; assert persisted.
		$updating = function (object $event): void {
			if (($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) === false) {
				return;
			}

			$data = $event->getNewObject()->getObject() ?? [];
			if (($data['description'] ?? '') === 'v1-probe-update') {
				$event->setModifiedData(['description' => self::UPDATE_MUTATED]);
			}
		};
		$this->dispatcher->addListener(\OCA\OpenRegister\Event\ObjectUpdatingEvent::class, $updating);
		$this->registeredListeners[\OCA\OpenRegister\Event\ObjectUpdatingEvent::class] = $updating;

		$this->objectService->saveObject(
			object: [
				'employeeId' => '00000000-0000-4000-8000-0000000000a1',
				'period' => '1999-01',
				'hours' => 2,
				'status' => 'draft',
				'description' => 'v1-probe-update',
			],
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_validation: false
		);

		$persisted = $this->readBack($uuid);
		$this->assertSame(
			self::UPDATE_MUTATED,
			(string) ($persisted['description'] ?? ''),
			'ObjectUpdatingEvent setModifiedData() did NOT propagate into the persisted object — Decision 4 fallback required.'
		);

		// Phase 3 — MUST-FAIL CONTROL: same write shape, sentinel the hooks do
		// not match. If this phase saw a MUTATED value, phases 1/2 proved
		// nothing (ambient state, not the hook).
		$this->objectService->saveObject(
			object: [
				'employeeId' => '00000000-0000-4000-8000-0000000000a1',
				'period' => '1999-01',
				'hours' => 3,
				'status' => 'draft',
				'description' => 'v1-probe-control',
			],
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_validation: false
		);

		$persisted = $this->readBack($uuid);
		$this->assertSame(
			'v1-probe-control',
			(string) ($persisted['description'] ?? ''),
			'Control write was mutated although no hook matched — the mutation evidence is contaminated.'
		);
		$this->assertStringNotContainsString('MUTATED', (string) ($persisted['description'] ?? ''));
	}//end testPreSaveMutationPersistsAndControlWriteDoesNot()

	/**
	 * Read the probe object back through the shipping read path — the
	 * persisted bytes, never the in-memory entity the save returned.
	 *
	 * @param string $uuid The probe object uuid.
	 *
	 * @return array<string, mixed> The persisted object payload.
	 */
	private function readBack(string $uuid): array {
		$entity = $this->objectService->find(
			id: $uuid,
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false
		);
		$data = $entity->getObject();

		return is_array($data) === true ? $data : [];
	}//end readBack()

}//end class
