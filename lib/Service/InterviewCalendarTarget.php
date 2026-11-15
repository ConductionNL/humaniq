<?php

/**
 * Interview Calendar Target
 *
 * The resolved sync target for one interview-scheduling run (design.md D1):
 * the duck-typed CalDavBackend, the resolved calendar id, and the
 * configured principal/URI, bundled so callers pass one value object
 * instead of four separate parameters.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The calendar target of one interview-scheduling run.
 *
 * @spec openspec/specs/interview-scheduling/spec.md#REQ-INTV-002
 */
final class InterviewCalendarTarget extends CalendarTarget {
}//end class
