<?php

/**
 * Humaniq LifecycleChange
 *
 * One write that may mark an HR lifecycle moment: the stored and the saved
 * record, the day, the employee and the written record's id.
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
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One write that may mark an HR lifecycle moment.
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
final class LifecycleChange {

	/**
	 * The stored record, null on create.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $old = null;

	/**
	 * The saved record, with its id.
	 *
	 * @var array<string, mixed>
	 */
	public array $new = [];

	/**
	 * The day, YYYY-MM-DD.
	 *
	 * @var string
	 */
	public string $today = '';

	/**
	 * The employee the record is about.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The written record's id, '' when it has none.
	 *
	 * @var string
	 */
	public string $subjectId = '';
}//end class
