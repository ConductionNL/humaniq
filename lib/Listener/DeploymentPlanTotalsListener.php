<?php

/**
 * Humaniq DeploymentPlanTotalsListener
 *
 * Every save of a deployment plan gets its line hours and planned total from
 * the server (DeploymentPlanService::totals), so the difference with the norm,
 * which the schema calculates from them, cannot be typed in. A norm entered
 * without a source is marked as entered by hand.
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
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-each-teacher-shall-have-a-yearly-deployment-plan-against-the-norm-for-their-contract-req-edu-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\DeploymentPlanService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stamps a deployment plan's line hours, planned total and norm source.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-each-teacher-shall-have-a-yearly-deployment-plan-against-the-norm-for-their-contract-req-edu-001
 */
class DeploymentPlanTotalsListener implements IEventListener {

	/**
	 * The schema this listener stamps, as the registration filter compares it.
	 */
	public const SLUG = 'deploymentplan';

	/**
	 * Constructor.
	 *
	 * @param DeploymentPlanService $plans The plan arithmetic.
	 */
	public function __construct(
		private readonly DeploymentPlanService $plans,
	) {
	}//end __construct()

	/**
	 * Stamp the plan being written.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-school-leader-sees-an-overload
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$plan = ($event->getObject()->getObject() ?? []);
		} else if ($event instanceof ObjectUpdatingEvent === true) {
			$plan = ($event->getNewObject()->getObject() ?? []);
		} else {
			return;
		}

		$totals = $this->plans->totals(plan: $plan);
		$stamp = ['lines' => $totals['lines'], 'plannedHours' => $totals['plannedHours']];
		if (is_numeric($plan['normHours'] ?? null) === true && trim((string)($plan['normSource'] ?? '')) === '') {
			$stamp['normSource'] = 'handmatig';
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $stamp));
	}//end handle()
}//end class
