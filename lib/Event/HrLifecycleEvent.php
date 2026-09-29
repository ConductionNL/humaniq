<?php

/**
 * Humaniq HrLifecycleEvent
 *
 * The typed Nextcloud event for one HR moment (platform-hr-lifecycle-events
 * D3, ADR-041): someone joins, leaves or changes job, leave is approved or
 * withdrawn, sickness is reported or ends. Dispatched on the same edge as the
 * matching `nl.conduction.hrmq.*` CloudEvent, so a sibling app can listen in
 * the same request without an HTTP receiver. Each moment has its own
 * subclass; this base carries what every moment has, and `getData()` carries
 * the moment's own minimal fields (design D2): never a leave type, a reason,
 * a percentage, salary, BSN or medical data.
 *
 * @category Event
 * @package  OCA\Humaniq\Event
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
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Event;

use OCP\EventDispatcher\Event;

/**
 * One HR moment, as a typed event.
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
abstract class HrLifecycleEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $eventId The CloudEvent id of the same moment, so a consumer of both deduplicates.
	 * @param string $employeeId The employee.
	 * @param string $nextcloudUserId The employee's account, '' when none.
	 * @param string $administrationId The administration, '' when unknown.
	 * @param string $occurredOn The day of the moment, YYYY-MM-DD.
	 * @param array<string, mixed> $data The moment's own fields.
	 */
	public function __construct(
		private readonly string $eventId,
		private readonly string $employeeId,
		private readonly string $nextcloudUserId,
		private readonly string $administrationId,
		private readonly string $occurredOn,
		private readonly array $data,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The CloudEvent id of the same moment.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function getEventId(): string {
		return $this->eventId;
	}//end getEventId()

	/**
	 * The employee.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function getEmployeeId(): string {
		return $this->employeeId;
	}//end getEmployeeId()

	/**
	 * The employee's Nextcloud account, '' when none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function getNextcloudUserId(): string {
		return $this->nextcloudUserId;
	}//end getNextcloudUserId()

	/**
	 * The administration, '' when unknown.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function getAdministrationId(): string {
		return $this->administrationId;
	}//end getAdministrationId()

	/**
	 * The day of the moment.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function getOccurredOn(): string {
		return $this->occurredOn;
	}//end getOccurredOn()

	/**
	 * The moment's own fields, as in the CloudEvent's data.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-002
	 */
	public function getData(): array {
		return $this->data;
	}//end getData()

}//end class
