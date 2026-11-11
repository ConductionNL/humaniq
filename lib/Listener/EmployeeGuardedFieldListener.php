<?php

/**
 * Humaniq EmployeeGuardedFieldListener
 *
 * Refuses a direct update of an Employee field that a ChangeApprovalRule with
 * an approver covers (people-record-change-approval D3), for every caller on
 * every page and API, with a message naming the kind of change. humaniq's
 * own apply step writes under InternalWriteMarker and is exempt. When the
 * rules cannot be read the update is refused: fail closed. Creating an
 * employee is not guarded; HR enters a new employee in one go.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\ChangeApprovalRules;
use OCA\Humaniq\Service\FieldReadAccess;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Keeps guarded employee fields behind a change request.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-002
 */
class EmployeeGuardedFieldListener implements IEventListener {

	/**
	 * Lower-cased slug of the employee schema.
	 *
	 * @var string
	 */
	public const EMPLOYEE_SLUG = 'employee';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Resolves slugs and the stored employee.
	 * @param ChangeApprovalRules  $rules   The rule per kind of change.
	 * @param InternalWriteMarker  $marker  Tells humaniq's own writes apart.
	 * @param LoggerInterface      $logger  Logger.
	 * @param FieldReadAccess      $access  The protected values a save carries forward, which are no change.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ChangeApprovalRules $rules,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
		private readonly FieldReadAccess $access,
	) {

	}//end __construct()

	/**
	 * Refuse the update when it changes a guarded field.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-002
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
	 */
	public function handle(Event $event): void {
		if ($this->applies($event) === false) {
			return;
		}

		$new = ($event->getNewObject()->getObject() ?? []);
		$old = $this->storedOf($event);
		// A protected field the writer was never shown arrives empty and is
		// carried forward by FieldAccessListener: that is no change to guard.
		$carried = $this->access->carriedForward(schemaId: (string)$event->getNewObject()->getSchema(), old: $old, new: $new);
		$changed = array_values(array_diff($this->changedFields(old: $old, new: $new), array_keys($carried)));
		if ($changed === []) {
			return;
		}

		$refusal = $this->refusalFor(changed: $changed, administrationId: (string)($new['administrationId'] ?? ($old['administrationId'] ?? '')));
		$this->refuse(event: $event, message: $refusal);
	}//end handle()

	/**
	 * Whether this is an outside update of an employee.
	 *
	 * @param Event $event The event.
	 *
	 * @psalm-assert-if-true ObjectUpdatingEvent $event
	 *
	 * @return bool
	 */
	private function applies(Event $event): bool {
		return $event instanceof ObjectUpdatingEvent
			&& $this->marker->isInternal() === false
			&& strtolower($this->gateway->resolveSchemaSlug((string)$event->getNewObject()->getSchema())) === self::EMPLOYEE_SLUG;
	}//end applies()

	/**
	 * The employee as stored, read from the register when the event has no
	 * old object.
	 *
	 * @param ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return array<string, mixed>
	 */
	private function storedOf(ObjectUpdatingEvent $event): array {
		return ($event->getOldObject()?->getObject() ?? ($this->gateway->findObjectData((string)$event->getNewObject()->getUuid(), 'Employee') ?? []));
	}//end storedOf()

	/**
	 * The refusal for the first changed field that needs approval; a
	 * refusal too when the rules cannot be read.
	 *
	 * @param list<string> $changed          The changed fields.
	 * @param string       $administrationId The employee's administration.
	 *
	 * @return string|null
	 */
	private function refusalFor(array $changed, string $administrationId): ?string {
		try {
			$rules = $this->rules->forAdministration($administrationId);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: the change approval rules could not be read, an employee update was refused', ['exception' => $e->getMessage()]);
			return 'De goedkeuringsregels voor wijzigingen konden niet worden gelezen, dus deze wijziging is niet opgeslagen. Probeer het later opnieuw.';
		}

		foreach ($changed as $field) {
			$kind = $this->rules->guardingKind($field, $rules);
			if ($kind !== null) {
				return sprintf('Een wijziging van %s (%s) moet worden goedgekeurd. Dien een wijzigingsverzoek in.', $kind, $field);
			}
		}

		return null;
	}//end refusalFor()

	/**
	 * The fields whose value the update changes.
	 *
	 * @param array<string, mixed> $old The stored employee.
	 * @param array<string, mixed> $new The employee as it will be saved.
	 *
	 * @return list<string>
	 */
	private function changedFields(array $old, array $new): array {
		$changed = [];
		foreach ($new as $field => $value) {
			$field = (string)$field;
			if ($field === 'id' || str_starts_with($field, '@') === true) {
				continue;
			}

			if ($this->same($value, ($old[$field] ?? null)) === false) {
				$changed[] = $field;
			}
		}

		return $changed;
	}//end changedFields()

	/**
	 * Whether two values are the same value: 3800 and 3800.0 are, and so are
	 * null and an empty string, so a form that sends a number back as an
	 * integer or an empty field as "" is not taken for a change.
	 *
	 * @param mixed $left  One value.
	 * @param mixed $right The other.
	 *
	 * @return bool
	 */
	private function same(mixed $left, mixed $right): bool {
		if (is_numeric($left) === true && is_numeric($right) === true) {
			return (float)$left === (float)$right;
		}

		if (($left === null || $left === '') && ($right === null || $right === '')) {
			return true;
		}

		return json_encode($left) === json_encode($right);
	}//end same()

	/**
	 * Refuse the write with a user-facing message.
	 *
	 * @param ObjectUpdatingEvent $event   The event.
	 * @param string|null         $message The refusal; none leaves the save alone.
	 *
	 * @return void
	 */
	private function refuse(ObjectUpdatingEvent $event, ?string $message): void {
		if ($message === null) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()

}//end class
