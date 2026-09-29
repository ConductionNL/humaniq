<?php

/**
 * Humaniq SicknessReportedEvent
 *
 * Sickness was reported, or the employee recovered (platform-hr-lifecycle-events D3).
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
 * Sickness was reported, or the employee recovered
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
class SicknessReportedEvent extends HrLifecycleEvent {

	/**
	 * Whether the employee recovered rather than reported sick.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function isRecovered(): bool {
		return (bool)($this->getData()['recovered'] ?? false);
	}//end isRecovered()

}//end class
