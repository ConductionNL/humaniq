<?php

/**
 * Humaniq ObjectWeekQuery
 *
 * One request for the hours of an object's week: the object's type and
 * reference, and the day once it has been read.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
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
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;

/**
 * One request for the hours of an object's week.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */
final class ObjectWeekQuery {

	/**
	 * The `<app>:<schema>` literal of the object, trimmed.
	 *
	 * @var string
	 */
	public string $type = '';

	/**
	 * The object's uuid, trimmed.
	 *
	 * @var string
	 */
	public string $ref = '';

	/**
	 * The day asked for, once read.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $day = null;
}//end class
