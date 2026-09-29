<?php

/**
 * Humaniq RightToWorkRecorder
 *
 * Turns a right-to-work check HR enters into a decided record, and carries a
 * pass onto the onboarding case (people-dossier-completeness D4).
 *
 * Before the save, stamp() applies RightToWorkService's rule to what HR
 * entered, against the start date of the onboarding case (or the employee),
 * and removes the machine-readable zone so no document number is stored.
 * After the save, recordPass() ticks the case's WID check and files a
 * residence document that allows work as a PersonnelDocument with its expiry.
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
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Stamps and follows up a right-to-work check.
 *
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
 */
class RightToWorkRecorder {

	/**
	 * The requirement code a residence document is filed under.
	 */
	public const RESIDENCE_REQUIREMENT = 'verblijfsdocument';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Register reads and writes, past RBAC.
	 * @param RightToWorkService $rule The stated rule.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly RightToWorkService $rule,
	) {

	}//end __construct()

	/**
	 * The fields to stamp on a check before it is saved.
	 *
	 * @param array<string, mixed> $check What HR entered.
	 * @param string $userId The caller.
	 * @param string $today Today (Y-m-d).
	 *
	 * @return array<string, mixed> The fields to set.
	 *
	 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function stamp(array $check, string $userId, string $today): array {
		$onboarding = $this->onboardingOf(check: $check);
		$employeeId = trim((string)($check['employeeId'] ?? ''));
		if ($employeeId === '' && $onboarding !== null) {
			$employeeId = (string)($onboarding['employeeId'] ?? '');
		}

		$startDate = (string)($onboarding['startDate'] ?? '');
		if ($startDate === '' && $employeeId !== '') {
			$startDate = (string)($this->gateway->findObjectData($employeeId, 'Employee')['startDate'] ?? '');
		}

		$decision = $this->rule->decide(array_merge($check, ['startDate' => ($startDate !== '' ? $startDate : $today)]));

		return [
			'employeeId' => $employeeId,
			'documentType' => ($decision['documentType'] !== '' ? $decision['documentType'] : 'geen'),
			'nationality' => $this->orNull(value: $decision['nationality']),
			'documentExpiry' => $this->orNull(value: $decision['documentExpiry']),
			'method' => $decision['method'],
			'result' => $decision['result'],
			'reasonCode' => $decision['reasonCode'],
			'reason' => $decision['reason'],
			'mrz' => null,
			'checkedBy' => $this->orNull(value: ($check['checkedBy'] ?? null)) ?? $userId,
			'checkedOn' => $this->orNull(value: ($check['checkedOn'] ?? null)) ?? $today,
		];
	}//end stamp()

	/**
	 * After a passing check is saved: tick the WID check and file a residence document.
	 *
	 * Idempotent: a case already ticked and a document already filed are left alone.
	 *
	 * @param array<string, mixed> $check The saved check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function recordPass(array $check): void {
		if (($check['result'] ?? '') !== RightToWorkService::RESULT_PASS) {
			return;
		}

		$onboarding = $this->onboardingOf(check: $check);
		if ($onboarding !== null && ($onboarding['widCheckDone'] ?? false) !== true) {
			$onboarding['widCheckDone'] = true;
			$onboarding['widCheckDate'] = (string)($check['checkedOn'] ?? '');
			$this->gateway->save($onboarding, 'Onboarding', (string)$onboarding['id']);
		}

		$employeeId = (string)($check['employeeId'] ?? '');
		$expiry = (string)($check['documentExpiry'] ?? '');
		if (($check['reasonCode'] ?? '') !== 'verblijf-arbeid-vrij' || $employeeId === '' || $expiry === '') {
			return;
		}

		foreach ($this->gateway->findFiltered('PersonnelDocument', ['employeeId' => $employeeId]) as $document) {
			if (($document['requirementCode'] ?? '') === self::RESIDENCE_REQUIREMENT && ($document['validUntil'] ?? '') === $expiry) {
				return;
			}
		}

		$this->gateway->save(
			[
				'employeeId' => $employeeId,
				'requirementCode' => self::RESIDENCE_REQUIREMENT,
				'validUntil' => $expiry,
				'verifiedBy' => $this->orNull(value: ($check['checkedBy'] ?? null)),
				'verifiedOn' => $this->orNull(value: ($check['checkedOn'] ?? null)),
				'note' => 'Filed by the right-to-work check.',
				'administrationId' => $this->orNull(value: ($check['administrationId'] ?? null)),
			],
			'PersonnelDocument'
		);
	}//end recordPass()

	/**
	 * The onboarding case the check names, with its id.
	 *
	 * @param array<string, mixed> $check The check.
	 *
	 * @return array<string, mixed>|null
	 */
	private function onboardingOf(array $check): ?array {
		$id = trim((string)($check['onboardingId'] ?? ''));
		if ($id === '') {
			return null;
		}

		$onboarding = $this->gateway->findObjectData($id, 'Onboarding');
		if ($onboarding === null) {
			return null;
		}

		$onboarding['id'] = $id;
		return $onboarding;
	}//end onboardingOf()

	/**
	 * A trimmed string, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function orNull(mixed $value): ?string {
		$text = is_scalar($value) === true ? trim((string)$value) : '';

		return $text === '' ? null : $text;
	}//end orNull()

}//end class
