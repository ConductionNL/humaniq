<?php

/**
 * Humaniq HoursWriteRefusedException
 *
 * Thrown inside the hours-process pre-save listeners
 * (TimeEntryStampListener / TimesheetProcessStampListener) to refuse the
 * carrying write with a user-facing Dutch message. The listener catches it
 * and translates it into OpenRegister's structured rejection
 * (`stopPropagation()` + `setErrors()`), which the save path surfaces as the
 * 422 `HookStoppedException` response. A dedicated type — never a bare
 * RuntimeException — so an infrastructure failure's internal message can
 * never be mistaken for a deliberate, user-facing refusal.
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

/**
 * A deliberate, user-facing refusal of an hours-process write.
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 */
class HoursWriteRefusedException extends \RuntimeException {
}//end class
