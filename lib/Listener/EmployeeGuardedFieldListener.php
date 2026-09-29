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
		if (($event instanceof ObjectUpdatingEvent) === false || $this->marker->isInternal() === true) {
			return;
		}

		$entity = $event->getNewObject();
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::EMPLOYEE_SLUG) {
			return;
		}

		$new = ($entity->getObject() ?? []);
		$old = ($event->getOldObject()?->getObject() ?? ($this->gateway->findObjectData((string)$entity->getUuid(), 'Employee') ?? []));
		// A protected field the writer was never shown arrives empty and is
		// carried forward by FieldAccessListener: that is no change to guard.
		$carried = $this->access->carriedForward(schemaId: (string)$entity->getSchema(), old: $old, new: $new);
		$changed = array_values(array_diff($this->changedFields(old: $old, new: $new), array_keys($carried)));
		if ($changed === []) {
			return;
		}

		try {
			$rules = $this->rules->forAdministration((string)($new['administrationId'] ?? ($old['administrationId'] ?? '')));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: the change approval rules could not be read, an employee update was refused', ['exception' => $e->getMessage()]);
			$this->refuse($event, 'De goedkeuringsregels voor wijzigingen konden niet worden gelezen, dus deze wijziging is niet opgeslagen. Probeer het later opnieuw.');
			return;
		}

		foreach ($changed as $field) {
			$kind = $this->rules->guardingKind($field, $rules);
			if ($kind !== null) {
				$this->refuse($event, sprintf('Een wijziging van %s (%s) moet worden goedgekeurd. Dien een wijzigingsverzoek in.', $kind, $field));
				return;
			}
		}
	}//end handle()

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
	 * @param string              $message The message.
	 *
	 * @return void
	 */
	private function refuse(ObjectUpdatingEvent $event, string $message): void {
		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()

}//end class
