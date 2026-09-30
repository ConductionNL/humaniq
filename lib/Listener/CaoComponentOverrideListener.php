<?php

/**
 * Refuses a contract whose CAO component override has no reason or is
 * below the agreement.
 *
 * The same rule EmploymentTermsResolver applies when the run resolves the
 * components, applied at the save so a wrong term never becomes part of the
 * contract (payroll-cao-components D2).
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use InvalidArgumentException;
use OCA\Humaniq\Service\EmploymentTermsResolver;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Judges the CAO component overrides of a contract before it is saved.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
 */
class CaoComponentOverrideListener implements IEventListener {

	/**
	 * Lower-cased slug of the contract schema.
	 */
	public const SLUG = 'employmentcontract';

	/**
	 * Constructor.
	 *
	 * @param EmploymentTermsResolver $terms The override rules.
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
	 */
	public function __construct(
		private readonly EmploymentTermsResolver $terms,
	) {
	}//end __construct()

	/**
	 * Refuse the save when the resolver refuses the overrides.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$contract = ($event->getObject()->getObject() ?? []);
		} else if ($event instanceof ObjectUpdatingEvent) {
			$contract = ($event->getNewObject()->getObject() ?? []);
		} else {
			return;
		}

		try {
			$this->terms->resolveComponents(contract: $contract);
		} catch (InvalidArgumentException $e) {
			$event->setErrors(['message' => $e->getMessage()]);
			$event->stopPropagation();
		}
	}//end handle()

}//end class
