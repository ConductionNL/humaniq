<?php

/**
 * Humaniq HireRun
 *
 * The state of turning one hired application into an employee: the
 * application, the confirmed person and the employee they become.
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
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One hire being carried out.
 *
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 */
final class HireRun {

	/**
	 * The application id.
	 *
	 * @var string
	 */
	public string $applicationId = '';

	/**
	 * The stored application.
	 *
	 * @var array<string, mixed>
	 */
	public array $application = [];

	/**
	 * The confirmed hire.
	 *
	 * @var array<string, mixed>
	 */
	public array $person = [];

	/**
	 * The employee the hire becomes or is attached to.
	 *
	 * @var string
	 */
	public string $employeeId = '';
}//end class
