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
		$entity = null;
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$entity = $event->getObject();
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::MUTATION_SLUG) {
			return;
		}

		$scenarioId = trim((string)(($entity->getObject() ?? [])['scenarioId'] ?? ''));
		$scenario = ($scenarioId === '') ? null : $this->gateway->findObjectData($scenarioId, 'FormationScenario');
		if ($scenario === null || ($scenario['status'] ?? 'concept') === 'vastgesteld') {
			$event->setErrors(['message' => ($scenario === null) ? 'This change names no existing scenario.' : 'This scenario is fixed and accepts no changes.']);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData(['administrationId' => ($scenario['administrationId'] ?? null)]);
	}//end handle()

}//end class
