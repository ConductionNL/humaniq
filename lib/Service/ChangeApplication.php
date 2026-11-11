<?php

/**
 * Humaniq ChangeApplication
 *
 * One approved change request being applied: the request, the employee it
 * changes and the fields found stale.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One approved change request being applied.
 *
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */
final class ChangeApplication {

	/**
	 * The change request id.
	 *
	 * @var string
	 */
	public string $requestId = '';

	/**
	 * The request as it will be stored, without its identity.
	 *
	 * @var array<string, mixed>
	 */
	public array $record = [];

	/**
	 * The employee id.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The stored employee; null when it cannot be found.
	 *
	 * @var array<string, mixed>|null
	 */
	public array|null $employee = null;

	/**
	 * The requested values per field.
	 *
	 * @var array<string, mixed>
	 */
	public array $changes = [];

	/**
	 * The fields changed since the request was made.
	 *
	 * @var list<string>
	 */
	public array $stale = [];

}//end class
