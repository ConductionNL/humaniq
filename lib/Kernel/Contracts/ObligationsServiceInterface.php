<?php

/**
 * Contract of ObligationsService
 *
 * The public surface of ObligationsService that other components call, so they depend
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
 * Contract of ObligationsService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObligationsServiceInterface {
	/**
	 * The Obligations list: every due-and-not-done WVP milestone, expiring
	 * temporary contract, and expiring BHV certificate for the caller's
	 * administration, merged into one list sorted by nearest `dueDate`,
	 * capped at OBLIGATIONS_LIMIT, each carrying a best-effort mandatory
	 * rule-violation badge (REQ-DSI-008/009).
	 *
	 * @param string $administrationId The caller's ALREADY-AUTHORIZED active administration (REQ-DSI-005) — never resolved here.
	 *
	 * @return array<int, array{type: string, employeeId: string, subject: string, dueDate: string, route: string, violations: array<int, string>}>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-008
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-009
	 */
	public function getObligations(string $administrationId): array;
}//end interface
