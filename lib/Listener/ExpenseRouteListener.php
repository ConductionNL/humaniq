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
		if ($this->applies($event) === false) {
			return;
		}

		$claim   = $this->claimOf($event);
		$old     = $this->storedOf($event);
		$refusal = $this->refusal(claim: $claim, old: $old);
		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
			return;
		}

		$this->stampRoute(event: $event, route: $this->routeFor(claim: $claim, old: $old));
	}//end handle()

	/**
	 * Whether this is a claim write from outside the app's own writers.
	 *
	 * @param Event $event The event.
	 *
	 * @psalm-assert-if-true ObjectCreatingEvent|ObjectUpdatingEvent $event
	 *
	 * @return bool
	 */
	private function applies(Event $event): bool {
		return $this->marker->isInternal() === false && ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent);
	}//end applies()

	/**
	 * Whether the claim is approved by this write and carries no route yet.
	 *
	 * @param array<string, mixed> $claim The incoming claim.
	 * @param array<string, mixed> $old   The stored claim; empty on create.
	 *
	 * @return bool
	 */
	private function approvedNow(array $claim, array $old): bool {
		return (string)($claim['reimbursementRoute'] ?? '') === '' && (string)($claim['status'] ?? '') === 'approved' && (string)($old['status'] ?? '') !== 'approved';
	}//end approvedNow()

	/**
	 * The incoming claim.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return array<string, mixed>
	 */
	private function claimOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if ($event instanceof ObjectUpdatingEvent) {
			return ($event->getNewObject()->getObject() ?? []);
		}

		return ($event->getObject()->getObject() ?? []);
	}//end claimOf()

	/**
	 * The stored claim; empty on a create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return array<string, mixed>
	 */
	private function storedOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return [];
		}

		return ($event->getOldObject()?->getObject() ?? []);
	}//end storedOf()

	/**
	 * Add the route to the save, when there is one.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param string|null                             $route The route to stamp.
	 *
	 * @return void
	 */
	private function stampRoute(ObjectCreatingEvent|ObjectUpdatingEvent $event, ?string $route): void {
		if ($route === null) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['reimbursementRoute' => $route]));
	}//end stampRoute()

	/**
	 * The route to stamp on a claim that is approved now without one.
	 *
	 * @param array<string, mixed> $claim The incoming claim.
	 * @param array<string, mixed> $old   The stored claim; empty on create.
	 *
	 * @return string|null
	 */
	private function routeFor(array $claim, array $old): ?string {
		if ($this->approvedNow(claim: $claim, old: $old) === false) {
			return null;
		}

		if (self::isTaxable(claim: $claim) === true) {
			return 'direct';
		}

		return $this->settings->getExpenseReimbursementRoute();
	}//end routeFor()


	/**
	 * Why the save is refused, or null.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed> $old   The claim before, or none.
	 *
	 * @return string|null
	 */
	private function refusal(array $claim, array $old): ?string {
		return ($this->taxableRefusal(claim: $claim) ?? $this->lockedRefusal(claim: $claim, old: $old) ?? $this->handPaidRefusal(claim: $claim, old: $old));
	}//end refusal()

	/**
	 * A claim with a taxed part cannot go through the payroll run yet.
	 *
	 * @param array<string, mixed> $claim The incoming claim.
	 *
	 * @return string|null
	 */
	private function taxableRefusal(array $claim): ?string {
		if ((string)($claim['reimbursementRoute'] ?? '') !== 'payroll' || self::isTaxable(claim: $claim) === false) {
			return null;
		}

		return 'Deze declaratie heeft een belaste deel en kan nog niet via de salarisrun worden uitbetaald. Kies de directe route.';
	}//end taxableRefusal()

	/**
	 * A claim inside a payroll run keeps its route.
	 *
	 * @param array<string, mixed> $claim The incoming claim.
	 * @param array<string, mixed> $old   The stored claim; empty on create.
	 *
	 * @return string|null
	 */
	private function lockedRefusal(array $claim, array $old): ?string {
		if ((string)($old['payrollRunId'] ?? '') === '' || (string)($claim['reimbursementRoute'] ?? '') === (string)($old['reimbursementRoute'] ?? '')) {
			return null;
		}

		return 'Deze declaratie zit in een salarisrun; de route kan niet meer worden gewijzigd.';
	}//end lockedRefusal()

	/**
	 * A payroll-route claim is not marked reimbursed by hand.
	 *
	 * @param array<string, mixed> $claim The incoming claim.
	 * @param array<string, mixed> $old   The stored claim; empty on create.
	 *
	 * @return string|null
	 */
	private function handPaidRefusal(array $claim, array $old): ?string {
		$reimbursedByHand = ((string)($claim['status'] ?? '') === 'reimbursed' && (string)($old['status'] ?? '') !== 'reimbursed');
		$payroll = in_array('payroll', [(string)($claim['reimbursementRoute'] ?? ''), (string)($old['reimbursementRoute'] ?? '')], true);
		if ($reimbursedByHand === false || $payroll === false) {
			return null;
		}

		return 'Deze declaratie wordt via de salarisrun uitbetaald en wordt vergoed zodra die run is goedgekeurd.';
	}//end handPaidRefusal()

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
