<?php

/**
 * An employee's referral of a candidate, and the application it becomes.
 *
 * The employee writes a Referral; the server decides everything that matters
 * (hiring-candidate-assessment D4): it refuses a referral the candidate did not
 * agree to or for a vacancy that is not published, stamps the referrer from the
 * session, creates the job-application HR works with at nieuw with source
 * referral, and keeps the referral's status in step with that application. The
 * referrer so sees the name, the vacancy and the status, and nothing else.
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
 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Referral checks, stamps, the application and the status mirror.
 */
class ReferralService {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads and writes the register.
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
	) {
	}//end __construct()

	/**
	 * Why a referral cannot be saved, or null when it can.
	 *
	 * @param array<string, mixed> $referral The referral as sent.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function refusal(array $referral): ?string {
		if (($referral['candidateConsented'] ?? false) !== true) {
			return 'The candidate has to agree before you refer them. Tick that they agreed.';
		}

		$vacancy = $this->vacancy(referral: $referral);
		if ($vacancy === null || ($vacancy['status'] ?? '') !== 'gepubliceerd') {
			return 'Only a published vacancy takes referrals.';
		}

		return null;
	}//end refusal()

	/**
	 * The fields the server sets on a new referral.
	 *
	 * @param array<string, mixed> $referral The referral as sent.
	 * @param string               $uid      The signed-in referrer.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function stamp(array $referral, string $uid): array {
		return [
			'referrerUserId' => $uid,
			'vacancyTitle' => (string)($this->vacancy(referral: $referral)['title'] ?? ''),
			'status' => 'nieuw',
		];
	}//end stamp()

	/**
	 * Create the application HR works with and link it on the referral.
	 *
	 * @param array<string, mixed> $referral   The stored referral.
	 * @param string               $referralId The referral's id.
	 *
	 * @return string The new application's id.
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function createApplication(array $referral, string $referralId): string {
		$vacancy = ($this->vacancy(referral: $referral) ?? []);
		$application = array_filter(
			[
				'vacancyId' => (string)($referral['vacancyId'] ?? ''),
				'candidateName' => (string)($referral['candidateName'] ?? ''),
				'email' => (string)($referral['email'] ?? ''),
				'phone' => ($referral['phone'] ?? null),
				'motivation' => ($referral['motivation'] ?? null),
				'status' => 'nieuw',
				'talentPoolOptIn' => false,
				'source' => 'referral',
				'referredByUserId' => (string)($referral['referrerUserId'] ?? ''),
				'candidateConsented' => true,
				'administrationId' => ($vacancy['administrationId'] ?? null),
			],
			static fn ($value): bool => $value !== null && $value !== ''
		);

		$applicationId = (string)$this->gateway->save($application, 'job-application')->getUuid();

		// A save replaces the whole object, so the stored referral is carried in full.
		$stored = ($this->gateway->findObjectData($referralId, 'Referral') ?? $referral);
		unset($stored['id']);
		$this->gateway->save(array_merge($stored, ['applicationId' => $applicationId]), 'Referral', $referralId);

		return $applicationId;
	}//end createApplication()

	/**
	 * Carry an application's status onto the referral it came from.
	 *
	 * @param string $applicationId The application.
	 * @param string $status        Its status now.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function followStatus(string $applicationId, string $status): void {
		foreach ($this->gateway->findFiltered('Referral', ['applicationId' => $applicationId]) as $referral) {
			$referralId = (string)($referral['id'] ?? '');
			if ($referralId === '' || ($referral['status'] ?? '') === $status) {
				continue;
			}

			unset($referral['id']);
			$this->gateway->save(array_merge($referral, ['status' => $status]), 'Referral', $referralId);
		}
	}//end followStatus()

	/**
	 * The vacancy a referral names.
	 *
	 * @param array<string, mixed> $referral The referral.
	 *
	 * @return array<string, mixed>|null
	 */
	private function vacancy(array $referral): ?array {
		$vacancyId = trim((string)($referral['vacancyId'] ?? ''));
		if ($vacancyId === '') {
			return null;
		}

		return $this->gateway->findObjectData($vacancyId, 'Vacancy');
	}//end vacancy()

}//end class
