<?php

/**
 * Humaniq ScenarioMutationListener
 *
 * A fixed (vastgesteld) formation scenario accepts no new or changed
 * mutations, and a mutation takes its scenario's administration
 * (reporting-personnel-budget-and-scenarios D4). The ResourceBookingOverlapListener
 * shape: a pre-save refusal on the mutation's own write.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses mutations on a fixed scenario.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
 */
class ScenarioMutationListener implements IEventListener {

	public const MUTATION_SLUG = 'scenariomutation';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Register reads, past RBAC.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
	) {

	}//end __construct()

	/**
	 * Handle a pre-save event of a mutation.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$this->check(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$this->check(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Refuse a change to a fixed scenario, else stamp the scenario's administration.
	 *
	 * @param \OCA\OpenRegister\Event\ObjectCreatingEvent|\OCA\OpenRegister\Event\ObjectUpdatingEvent $event The pre-save event.
	 * @param object $entity The mutation entity.
	 *
	 * @return void
	 */
	private function check(object $event, object $entity): void {
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::MUTATION_SLUG) {
			return;
		}

		$scenarioId = trim((string)(($entity->getObject() ?? [])['scenarioId'] ?? ''));
		$scenario = ($scenarioId === '') ? null : $this->gateway->findObjectData($scenarioId, 'FormationScenario');
		if ($scenario === null) {
			// The register's own $ref check decides whether the scenario must exist;
			// this rule is only about a fixed one.
			return;
		}

		if (($scenario['status'] ?? 'concept') === 'vastgesteld') {
			$event->setErrors(['message' => 'This scenario is fixed and accepts no changes.']);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData(['administrationId' => ($scenario['administrationId'] ?? null)]);
	}//end check()

}//end class
