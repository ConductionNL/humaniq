<?php

/**
 * Humaniq LeaveCoverageCheck
 *
 * The state of checking one leave request against its unit's minimum
 * presence: the unit, the requester, the dates asked for, the members and the
 * composed schedule.
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
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * One coverage check, inside LeaveCoverageService.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
 */
final class LeaveCoverageCheck {

	/**
	 * The OrgUnit the requester is placed in.
	 *
	 * @var array<string, mixed>
	 */
	public array $orgUnit = [];

	/**
	 * The requesting employee, left out of `othersAway`.
	 *
	 * @var string
	 */
	public string $requester = '';

	/**
	 * The first day of the request.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $from;

	/**
	 * The last day of the request.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $to;

	/**
	 * The employees placed in the unit.
	 *
	 * @var array<int, string>
	 */
	public array $memberEmployeeIds = [];

	/**
	 * The composed schedule.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $entries = [];
}//end class
