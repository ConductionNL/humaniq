<?php

/**
 * Humaniq ExpenseCategoryGuardListener
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` and
 * `ObjectUpdatingEvent` for `Expense`. It refuses a claim in a category the
 * employer switched off (ExpenseCategoryPolicy): on a create, on a change of
 * the category, and on a submit. The approve, reject and reimburse of a claim
 * that was already submitted pass, so switching a category off never strands
 * a claim halfway. humaniq's own writes pass.
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
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\ExpenseCategoryPolicy;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses a claim in a switched-off category.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */
class ExpenseCategoryGuardListener implements IEventListener {

	/**
	 * Lower-cased slug of the expense schema.
	 *
	 * @var string
	 */
	public const SLUG = 'expense';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Resolves the schema slug.
	 * @param ExpenseCategoryPolicy $policy Which categories are on.
	 * @param InternalWriteMarker $marker Marks humaniq's own writes.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ExpenseCategoryPolicy $policy,
		private readonly InternalWriteMarker $marker,
	) {

	}//end __construct()

	/**
	 * Refuse the write when it makes or submits a claim in a category that is off.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = ($event instanceof ObjectCreatingEvent) ? $event->getObject() : $event->getNewObject();
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::SLUG) {
			return;
		}

		$claim = ($entity->getObject() ?? []);
		$stored = ($event instanceof ObjectUpdatingEvent) ? ($event->getOldObject()?->getObject() ?? []) : null;
		if ($this->checks(claim: $claim, stored: $stored) === false || $this->policy->isAllowed((string)($claim['category'] ?? '')) === true) {
			return;
		}

		$event->setErrors(['message' => 'Declaraties in deze categorie zijn uitgeschakeld. Kies een andere categorie.']);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether this write is one the category setting judges: a create, a
	 * change of the category, or a submit.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed>|null $stored The stored claim, null on create.
	 *
	 * @return bool
	 */
	private function checks(array $claim, ?array $stored): bool {
		if ($stored === null) {
			return true;
		}

		if (trim((string)($claim['category'] ?? '')) !== trim((string)($stored['category'] ?? ''))) {
			return true;
		}

		return trim((string)($claim['status'] ?? '')) === 'submitted' && trim((string)($stored['status'] ?? '')) !== 'submitted';
	}//end checks()

}//end class
