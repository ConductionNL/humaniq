<?php

/**
 * Humaniq ChangePlacement
 *
 * One change request being placed: the request, the requester, the employee
 * it is for, the proposed values and the approver role.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One change request being placed.
 *
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-003
 */
final class ChangePlacement {

	/**
	 * The request as it will be saved.
	 *
	 * @var array<string, mixed>
	 */
	public array $request = [];

	/**
	 * The requester's uid, or ''.
	 *
	 * @var string
	 */
	public string $uid = '';

	/**
	 * The employee the request is for, or null when there is none.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $employee = null;

	/**
	 * The proposed values.
	 *
	 * @var array<string, mixed>
	 */
	public array $changes = [];

	/**
	 * The approver role.
	 *
	 * @var string
	 */
	public string $role = '';
}//end class
