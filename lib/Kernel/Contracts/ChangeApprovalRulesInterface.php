<?php

/**
 * Contract of ChangeApprovalRules
 *
 * The public surface of ChangeApprovalRules that other components call, so they depend
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
 * Contract of ChangeApprovalRules.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ChangeApprovalRulesInterface {
	/**
	 * The rules that apply in an administration, keyed by kind of change.
	 *
	 * @param string|null $administrationId The employee's administration.
	 *
	 * @return array<string, array{fields: list<string>, approverRole: string}>
	 *
	 * @throws \Throwable When the rules cannot be read; the caller fails closed.
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
	 */
	public function forAdministration(?string $administrationId): array;

	/**
	 * The kind of change that guards a field: covered by a rule with an approver.
	 *
	 * @param string                                                          $field The Employee property.
	 * @param array<string, array{fields: list<string>, approverRole: string}> $rules The rules.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-002
	 */
	public function guardingKind(string $field, array $rules): ?string;
}//end interface
