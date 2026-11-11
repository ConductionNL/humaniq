<?php

/**
 * Humaniq ChangeApprovalRules
 *
 * Reads the administered ChangeApprovalRule rows (people-record-change-approval
 * D2): per kind of change the Employee fields it covers and the role that
 * approves it. A rule for the employee's administration wins over a rule
 * without one. A read failure is not swallowed: the callers fail closed.
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
 * The approval rule per kind of change.
 *
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */
class ChangeApprovalRules {

	/**
	 * Approver role that means "no approval needed".
	 *
	 * @var string
	 */
	public const NO_APPROVER = 'none';

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway Reads humaniq's register.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
	) {

	}//end __construct()

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
	public function forAdministration(?string $administrationId): array {
		$rows = $this->gateway->loadAll('ChangeApprovalRule');
		$own = [];
		if ((string)$administrationId !== '') {
			$own = $this->rulesIn(rows: $rows, scope: (string)$administrationId);
		}

		return array_merge($this->rulesIn(rows: $rows, scope: ''), $own);
	}//end forAdministration()

	/**
	 * The rules per change kind of one scope: the general rules for '',
	 * else one administration's own; a later row of a kind wins.
	 *
	 * @param array<int, array<string, mixed>> $rows  The stored rules.
	 * @param string                           $scope The administration, or '' for the general rules.
	 *
	 * @return array<string, array{fields: list<string>, approverRole: string}>
	 */
	private function rulesIn(array $rows, string $scope): array {
		$rules = [];
		foreach ($rows as $row) {
			$kind = trim((string)($row['changeKind'] ?? ''));
			if ($kind === '' || trim((string)($row['administrationId'] ?? '')) !== $scope) {
				continue;
			}

			$rules[$kind] = [
				'fields' => array_values(array_map('strval', (is_array($row['fields'] ?? null) === true ? $row['fields'] : []))),
				'approverRole' => (string)($row['approverRole'] ?? self::NO_APPROVER),
			];
		}

		return $rules;
	}//end rulesIn()

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
	public function guardingKind(string $field, array $rules): ?string {
		foreach ($rules as $kind => $rule) {
			if ($rule['approverRole'] !== self::NO_APPROVER && in_array($field, $rule['fields'], true) === true) {
				return $kind;
			}
		}

		return null;
	}//end guardingKind()

}//end class
