<?php

/**
 * Humaniq FieldAccessListener
 *
 * Pre-save listener for the four schemas with field-level authorization
 * (compliance-roles-and-field-access D3 to D5):
 *
 * - Stamps the account uids the authorization matches on:
 *   `EmploymentContract.userId` from the employee and
 *   `PerformanceReview.reviewerUserId` from the reviewer, both from the
 *   Employee's `nextcloudUserId`.
 * - Keeps a protected value that a save would otherwise wipe. OpenRegister
 *   strips a field the reader may not see, and a full save then fills every
 *   absent property with null. A manager who opens an employee and saves it
 *   would so erase the BSN, bank account and salary they were never shown.
 *   When an update nulls a governed field the writer cannot read, the stored
 *   value is carried forward; when OpenRegister cannot be asked, every
 *   governed-looking null is carried forward, because a lost BSN cannot be
 *   restored and a refused clear can be repeated.
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\FieldReadAccess;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stamps subject uids and keeps protected values a save would wipe.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
 */
class FieldAccessListener implements IEventListener {

	/**
	 * Lower-cased slugs of the schemas this listener handles.
	 *
	 * @var list<string>
	 */
	public const SLUGS = ['employee', 'employmentcontract', 'payslip', 'performancereview'];

	/**
	 * The uid stamps: schema slug => [target field, source reference field].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const STAMPS = [
		'employmentcontract' => ['userId', 'employeeId'],
		'performancereview'  => ['reviewerUserId', 'reviewerId'],
	];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Resolves slugs and reads the referenced employee.
	 * @param FieldReadAccess      $access  Which governed fields the writer cannot read.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly FieldReadAccess $access,
	) {

	}//end __construct()

	/**
	 * Stamp and carry forward, for a create or an update.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-003
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
			$slug = strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema()));
			$this->apply($event, $this->stamp($slug, ($entity->getObject() ?? [])));
			return;
		}

		if (($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $event->getNewObject();
		$schemaId = (string)$entity->getSchema();
		$slug = strtolower($this->gateway->resolveSchemaSlug($schemaId));
		if (in_array($slug, self::SLUGS, true) === false) {
			return;
		}

		$new = ($entity->getObject() ?? []);
		$old = ($event->getOldObject()?->getObject() ?? []);
		$carried = $this->access->carriedForward(schemaId: $schemaId, old: $old, new: $new);
		$this->apply($event, array_merge($carried, $this->stamp($slug, array_merge($new, $carried))));
	}//end handle()

	/**
	 * The uid stamp for this object, if its schema has one and its reference
	 * resolves.
	 *
	 * @param string               $slug   The lower-cased schema slug.
	 * @param array<string, mixed> $object The object as it will be saved.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-003
	 */
	private function stamp(string $slug, array $object): array {
		if (isset(self::STAMPS[$slug]) === false) {
			return [];
		}

		[$target, $source] = self::STAMPS[$slug];
		$employeeId = trim((string)($object[$source] ?? ''));
		if ($employeeId === '') {
			return [$target => null];
		}

		$employee = $this->gateway->findObjectData($employeeId, 'Employee');
		$uid = trim((string)($employee['nextcloudUserId'] ?? ''));

		return [$target => ($uid === '' ? null : $uid)];
	}//end stamp()

	/**
	 * Merge the changes into whatever other listeners already modified.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The event.
	 * @param array<string, mixed>                    $changes The fields to set.
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $changes): void {
		if ($changes === []) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $changes));
	}//end apply()

}//end class
