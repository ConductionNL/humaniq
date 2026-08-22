<?php

/**
 * Offer Signing Recovery Service
 *
 * The two docudesk edge-case collaborators `OfferEsignService::
 * createSigningRequest()` needs (extracted to keep OfferEsignService under
 * phpmd's ExcessiveClassComplexity threshold, live-docudesk-defects
 * 2026-07-16):
 *
 *   1. `resolveCandidateUserId()` — the no-external-signer gap fix
 *      (defect-2): docudesk's `SigningService::sign()` authorizes strictly
 *      on `signer.userId === $user->getUID()`, so a candidate's email must
 *      resolve to a real Nextcloud user BEFORE `createRequest()` is called;
 *      an empty `signers[0].userId` used to reach docudesk and crash the
 *      whole request on the `signerRecord.user_id` NOT NULL column.
 *
 *   2. `recoverOrphanedRequestId()` — the orphaned-signing-request fix
 *      (defect-3): `createRequest()` writes the signing-request row BEFORE
 *      the signer-record write that can throw, so a failure there can leave
 *      a request row nobody has the id for. That row is stamped with
 *      `correlationId`/`documentFileId` before the failing write
 *      (docudesk's `SigningService::PROVENANCE_FIELDS`), so a best-effort
 *      recovery via `listRequests()` — session-guard-free, like
 *      `getRequest()` — keyed on BOTH fields together can find it. Only
 *      persists when EXACTLY ONE candidate matches (never guesses between
 *      ambiguous matches).
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
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Candidate-user resolution and orphaned-signing-request recovery for
 * `OfferEsignService`.
 */
class OfferSigningRecoveryService {

	/**
	 * @param IUserManager $userManager Resolves a candidate's email to an existing Nextcloud user id.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve a candidate's `Application.email` to an existing Nextcloud
	 * user id (see class docblock, defect-2). Sending an empty `userId`
	 * used to reach `createRequest()` and crash the whole request on the
	 * `signerRecord.user_id` NOT NULL column; resolving first and failing
	 * closed keeps that write out of docudesk entirely when no such user
	 * exists.
	 *
	 * @param string $email The candidate's `Application.email`.
	 *
	 * @return string|null The matching Nextcloud user id, or null when no user has this email (or the email is blank).
	 */
	public function resolveCandidateUserId(string $email): ?string {
		$email = trim($email);
		if ($email === '') {
			return null;
		}

		$matches = $this->userManager->getByEmail($email);
		$first = $matches[0] ?? null;

		return $first?->getUID();
	}//end resolveCandidateUserId()

	/**
	 * Best-effort recovery of a signing-request id that docudesk's
	 * `createRequest()` may have partially created before throwing (see
	 * class docblock, defect-3). `listRequests()` — like `getRequest()` —
	 * carries no session guard, so this lookup is genuinely CLI-safe. Only
	 * persists when EXACTLY ONE candidate matches BOTH keys (never guesses
	 * between ambiguous matches); any ambiguity or lookup failure is left
	 * unresolved and surfaced in the caller's failure message instead.
	 *
	 * @param mixed $signingService docudesk's SigningService, already resolved by the caller.
	 * @param string $applicationId The Application id (== the correlationId/subjectId humaniq sent).
	 * @param int $fileId The offer-letter file id sent as `documentFileId`.
	 *
	 * @return string|null The recovered signing-request id, or null when it cannot be determined unambiguously.
	 */
	public function recoverOrphanedRequestId(mixed $signingService, string $applicationId, int $fileId): ?string {
		try {
			$requests = $signingService->listRequests('', true);
		} catch (\Throwable $e) {
			$this->logger->warning('OfferSigningRecoveryService: kon geen orphan-recovery uitvoeren voor ' . $applicationId . ': ' . $e->getMessage());
			return null;
		}

		$documentFileId = (string)$fileId;
		$candidates = array_values(
			array_filter(
				$requests,
				static function (array $request) use ($applicationId, $documentFileId): bool {
					return (string)($request['correlationId'] ?? '') === $applicationId
						&& (string)($request['documentFileId'] ?? '') === $documentFileId;
				}
			)
		);

		if (count($candidates) !== 1) {
			return null;
		}

		$requestId = (string)($candidates[0]['id'] ?? $candidates[0]['uuid'] ?? '');

		return ($requestId === '' ? null : $requestId);
	}//end recoverOrphanedRequestId()

}//end class
