<?php

/**
 * Humaniq LeaveApprovedEvent
 *
 * Leave was approved, or an approved request was withdrawn (platform-hr-lifecycle-events D3).
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

/**
 * Leave was approved, or an approved request was withdrawn
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
class LeaveApprovedEvent extends HrLifecycleEvent {

	/**
	 * Whether the leave was withdrawn rather than approved.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function isWithdrawn(): bool {
		return (bool)($this->getData()['withdrawn'] ?? false);
	}//end isWithdrawn()

}//end class
