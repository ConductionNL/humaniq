<?php

/**
 * Contract of ChangeRequestService
 *
 * The public surface of ChangeRequestService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of ChangeRequestService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ChangeRequestServiceInterface {
	/**
	 * The stamps for a new request, or the reason it is refused.
	 *
	 * @param array<string, mixed> $request The request as it will be saved.
	 *
	 * @return array{stamps: array<string, mixed>, error: string|null}
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-003
	 */
	public function prepare(array $request): array;

	/**
	 * The stamps for a decision, or the reason it is refused.
	 *
	 * @param array<string, mixed> $old The request before.
	 * @param array<string, mixed> $new The request as it will be saved.
	 *
	 * @return array{stamps: array<string, mixed>, error: string|null}
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
	 */
	public function decide(array $old, array $new): array;
}//end interface
