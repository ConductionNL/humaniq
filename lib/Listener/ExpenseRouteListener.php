<?php

/**
 * Humaniq ExpenseRouteListener
 *
 * The route an approved claim takes, payroll or direct
 * (payroll-expenses-and-allowances D1). At approval a claim without a route
 * gets the employer's default, or the direct route when it has a taxable part.
 * A claim with a taxable part is refused the payroll route, a claim a payroll
 * run holds keeps its route, and a payroll-route claim is not reimbursed by
 * hand: the run is its only writer of `reimbursed`. humaniq's own writes pass.
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
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stamps and judges the route of a claim before it is saved.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
 */
class ExpenseRouteListener implements IEventListener {

	/**
	 * Lower-cased slug of the expense schema.
	 */
	public const SLUG = 'expense';

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settings The employer's default route.
	 * @param InternalWriteMarker $marker   Marks humaniq's own writes.
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly InternalWriteMarker $marker,
	) {
	}//end __construct()

	/**
	 * Stamp or refuse the claim's route.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		if ($event instanceof ObjectCreatingEvent) {
			$this->judge(event: $event, claim: ($event->getObject()->getObject() ?? []), old: []);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->judge(event: $event, claim: ($event->getNewObject()->getObject() ?? []), old: ($event->getOldObject()?->getObject() ?? []));
		}
	}//end handle()

	/**
	 * Refuse a wrong route or stamp the default one.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 * @param array<string, mixed>                    $claim The claim as it will be saved.
	 * @param array<string, mixed>                    $old   The claim before, or none.
	 *
	 * @return void
	 */
	private function judge(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $claim, array $old): void {
		$refusal = $this->refusal(claim: $claim, old: $old);
		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
			return;
		}

		$route = (string)($claim['reimbursementRoute'] ?? '');
		if ($route !== '' || (string)($claim['status'] ?? '') !== 'approved' || (string)($old['status'] ?? '') === 'approved') {
			return;
		}

		$event->setModifiedData(['reimbursementRoute' => (self::isTaxable(claim: $claim) === true ? 'direct' : $this->settings->getExpenseReimbursementRoute())]);
	}//end judge()

	/**
	 * Why the save is refused, or null.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed> $old   The claim before, or none.
	 *
	 * @return string|null
	 */
	private function refusal(array $claim, array $old): ?string {
		$route = (string)($claim['reimbursementRoute'] ?? '');
		$oldRoute = (string)($old['reimbursementRoute'] ?? '');
		if ($route === 'payroll' && self::isTaxable(claim: $claim) === true) {
			return 'Deze declaratie heeft een belaste deel en kan nog niet via de salarisrun worden uitbetaald. Kies de directe route.';
		}

		if ((string)($old['payrollRunId'] ?? '') !== '' && $route !== $oldRoute) {
			return 'Deze declaratie zit in een salarisrun; de route kan niet meer worden gewijzigd.';
		}

		$reimbursedByHand = ((string)($claim['status'] ?? '') === 'reimbursed' && (string)($old['status'] ?? '') !== 'reimbursed');
		if ($reimbursedByHand === true && ($route === 'payroll' || $oldRoute === 'payroll')) {
			return 'Deze declaratie wordt via de salarisrun uitbetaald en wordt vergoed zodra die run is goedgekeurd.';
		}

		return null;
	}//end refusal()

	/**
	 * Whether a claim has a taxable part.
	 *
	 * @param array<string, mixed> $claim The claim.
	 *
	 * @return bool
	 */
	private static function isTaxable(array $claim): bool {
		$taxable = ($claim['taxableAmount'] ?? null);
		return is_numeric($taxable) === true && (float)$taxable > 0.0;
	}//end isTaxable()

}//end class
