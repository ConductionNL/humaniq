<?php

/**
 * Offer E-sign Service
 *
 * Closes the MVP gap the `Application.aanbieden` transition's own docblock
 * names: generates a real offer-letter (aanbiedingsbrief) PDF from
 * `Application`+`Vacancy` data via docudesk's rendering engine and raises a
 * real docudesk signing request, tracked directly on the `Application`
 * (`offerLetterFileId`/`offerSigningRequestId`/`offerSigningStatus`) rather
 * than a `GeneratedDocument` row -- `GeneratedDocument.employeeId` is
 * required and a candidate at the `aanbod` stage has no Employee yet
 * (offer-esign design.md D1/D2). Re-implements `HrDocumentService`'s
 * duck-typed-probe / config-first-discovery-second-fail-closed-template /
 * render / store pipeline against its own subject model, and additionally
 * calls docudesk's `SigningService::createRequest()` with the VERIFIED real
 * field shape -- `signers` (an array of `{userId, displayName, email,
 * order}`), never a fabricated `signerIds` -- plus the seven optional
 * provenance fields that correlate the request back to humaniq (design.md
 * Context).
 *
 * Availability is duck-typed (ADR-046 philosophy): when docudesk is not
 * installed, or `DocumentService`/`TemplateService`/`SigningService` cannot
 * be resolved, the attempt is recorded `skipped-no-docudesk` and the method
 * NEVER throws (design.md D5, REQ-OFFR-004).
 *
 * The verified auth gap (design.md D5): `SigningService::createRequest()`,
 * `cancelRequest()` throw `RuntimeException('No authenticated user')` when
 * `IUserSession::getUser()` is null -- ALWAYS true for a genuine `occ` CLI
 * process. The entire write sub-pipeline (idempotent supersede + the
 * `createRequest()` call) is wrapped so this exception is caught and turned
 * into a `failed` outcome, never allowed to escape. `getRequest()` (the read
 * path `syncSignatureStatus()` uses) carries no such guard.
 *
 * No-external-signer gap (live-verified 2026-07-16, docudesk 0.0.37):
 * `SigningService::sign()` authorizes strictly on
 * `signer.userId === $user->getUID()` -- there is NO path for a signer who
 * is not an existing Nextcloud user (a documented docudesk Non-Goal). A
 * candidate at the `aanbod` stage is not automatically a Nextcloud user, so
 * `createSigningRequest()` first resolves `Application.email` to an
 * existing user via `IUserManager::getByEmail()` (`resolveCandidateUserId()`)
 * and fails CLEANLY (`offerSigningStatus: 'failed'`, a `no-nextcloud-user-
 * for-candidate` message) when none exists, rather than sending
 * `signers[0].userId = ''` through to docudesk's `signerRecord` (whose
 * `user_id` column is NOT NULL) -- that used to reach
 * `SQLSTATE[23502]` and crash the whole request AFTER
 * `SigningService::createRequest()` had already written the signing-request
 * row (design.md D8, defect-3 below).
 *
 * Orphaned-request recovery (design.md D8): `createRequest()` writes the
 * request row, THEN loops the `signerRecord` writes -- any throw in that
 * loop (the NOT NULL violation above, or any other docudesk-side failure)
 * leaves a signing-request row nobody in humaniq has the id for. Since that
 * row is stamped with `correlationId`/`documentFileId` BEFORE the failing
 * write (`SigningService::PROVENANCE_FIELDS`), `createSigningRequest()`'s
 * catch makes a best-effort recovery via `listRequests()` (like
 * `getRequest()`, session-guard-free) keyed on BOTH fields together; it only
 * persists the recovered id when exactly one candidate matches (no
 * guessing), otherwise the failure message documents the correlationId to
 * search for manually -- never a silent, permanently unreachable orphan.
 *
 * Idempotency (design.md D6): the Application's own `offerSigningStatus` IS
 * the single-slot idempotency state (one Application has at most one active
 * offer-esign attempt) -- `PENDING`/`IN_PROGRESS` triggers a best-effort
 * `cancelRequest()` (fail-soft, logged, never blocks the fresh attempt) then
 * falls through; `COMPLETED` short-circuits to `already-signed`; every other
 * value (incl. null) proceeds straight to a fresh attempt.
 *
 * No-auto-hire boundary (design.md D7, REQ-OFFR-006): neither
 * `requestSignature()` nor `syncSignatureStatus()` EVER writes
 * `Application.status` or invokes the `aannemen` transition, regardless of
 * the observed `offerSigningStatus` -- including `COMPLETED`. Every write
 * carries the resolved Application's CURRENT fields forward via a
 * field-merge save (OpenRegister's `saveObject()` update path is
 * PUT-semantic -- a property absent from the payload is nulled), so
 * `status` (and every other existing field) is never accidentally
 * nulled by a partial offer-esign update.
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

use DateTimeImmutable;
use DateTimeInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates an offer letter and raises a docudesk e-signature request for a
 * recruiting Application at the `aanbod` stage.
 */
class OfferEsignService {

	/**
	 * The app id docudesk registers under (IAppManager::isInstalled probe).
	 *
	 * @var string
	 */
	private const DOCUDESK_APP_ID = 'docudesk';

	/**
	 * docudesk's signing-request lifecycle service, resolved by string FQCN
	 * only -- the VERIFIED real contract (design.md Context): `createRequest`
	 * reads `signers`, NOT `signerIds`.
	 *
	 * @var string
	 */
	private const SIGNING_SERVICE_FQCN = 'OCA\DocuDesk\Service\SigningService';

	/**
	 * @var string
	 */
	private const APPLICATION_SCHEMA = 'Application';

	/**
	 * The ONLY Application.status value `requestSignature()` proceeds from
	 * (design.md D7 -- enforced in the service, not only manifest visibility).
	 *
	 * @var string
	 */
	private const REQUIRED_STAGE = 'aanbod';

	/**
	 * `offerSigningStatus` values that count as "live" for the
	 * supersede-on-re-run idempotency branch (design.md D6).
	 *
	 * @var string[]
	 */
	private const ACTIVE_SIGNING_STATUSES = ['PENDING', 'IN_PROGRESS'];

	/**
	 * @param ContainerInterface $container DI container for lazy SigningService resolution.
	 * @param IAppManager $appManager To duck-type-probe docudesk's presence.
	 * @param SettingsService $settingsService Register slug, offer-deadline config.
	 * @param OfferLetterService $letterService The docudesk-facing template-select/render/store collaborator.
	 * @param OfferApplicationRepository $applications The Application read/write collaborator.
	 * @param OfferSigningRecoveryService $signingRecovery Candidate-user resolution + orphaned-request recovery (see class docblock).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly OfferLetterService $letterService,
		private readonly OfferApplicationRepository $applications,
		private readonly OfferSigningRecoveryService $signingRecovery,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Generate the offer letter and raise a docudesk e-signature request for
	 * one Application (design.md D1-D7). Stage-guards to `aanbod`, applies
	 * the single-slot idempotency pre-check, duck-typed-degrades when
	 * docudesk is absent, and NEVER writes `Application.status` or invokes
	 * the `aannemen` transition.
	 *
	 * @param string $applicationId The Application id.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system' (occ context).
	 *
	 * @return array<string, mixed> Outcome: {applicationId, status, message, offerLetterFileId, offerSigningRequestId, offerSigningStatus}.
	 *
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-002
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-003
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-004
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-005
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-006
	 */
	public function requestSignature(string $applicationId, ?string $userId = null): array {
		$applicationId = trim($applicationId);
		if ($applicationId === '') {
			return $this->outcome('', null, 'usage-error', 'Geen applicationId opgegeven; aanvragen is geweigerd.');
		}

		$application = $this->applications->find($applicationId);
		if ($application === null) {
			return $this->outcome($applicationId, null, 'usage-error', 'Application niet gevonden: ' . $applicationId);
		}

		$stageError = $this->guardStage($application);
		if ($stageError !== null) {
			return $this->outcome($applicationId, $application, 'usage-error', $stageError);
		}

		// Idempotency pre-check (design.md D6): the single-slot state IS
		// Application.offerSigningStatus.
		$idempotencyOutcome = $this->applyIdempotencyPreCheck($applicationId, $application);
		if ($idempotencyOutcome !== null) {
			return $idempotencyOutcome;
		}

		$letter = $this->generateAndStoreLetter($applicationId, $application, $userId);
		if ($letter['outcome'] !== null) {
			return $letter['outcome'];
		}

		return $this->createSigningRequest($applicationId, $letter['application'], $letter['fileId'], $letter['fileName']);
	}//end requestSignature()

	/**
	 * The `aanbod`-only stage guard (design.md D7) -- enforced in the
	 * service, not only manifest visibility.
	 *
	 * @param array<string, mixed> $application The resolved Application.
	 *
	 * @return string|null A human-readable rejection message, or null when the stage is valid.
	 */
	private function guardStage(array $application): ?string {
		$status = (string)($application['status'] ?? '');
		if ($status !== self::REQUIRED_STAGE) {
			return sprintf('Application heeft status "%s"; alleen "%s" komt in aanmerking voor de aanbiedingsbrief/e-handtekening.', $status, self::REQUIRED_STAGE);
		}

		return null;
	}//end guardStage()

	/**
	 * The single-slot idempotency pre-check (design.md D6): `COMPLETED`
	 * short-circuits to an `already-signed` outcome; `PENDING`/`IN_PROGRESS`
	 * triggers a best-effort supersede then falls through (returns null to
	 * let the caller proceed); every other value falls through unchanged.
	 *
	 * @param string $applicationId The Application id.
	 * @param array<string, mixed> $application The resolved Application.
	 *
	 * @return array<string, mixed>|null The `already-signed` outcome, or null to proceed with a fresh attempt.
	 */
	private function applyIdempotencyPreCheck(string $applicationId, array $application): ?array {
		$currentSigningStatus = $application['offerSigningStatus'] ?? null;

		if ($currentSigningStatus === 'COMPLETED') {
			return $this->outcome($applicationId, $application, 'already-signed', 'De aanbiedingsbrief is al ondertekend; geen nieuwe poging nodig.');
		}

		if (in_array($currentSigningStatus, self::ACTIVE_SIGNING_STATUSES, true) === true) {
			$this->supersedeStaleRequest($application);
		}

		return null;
	}//end applyIdempotencyPreCheck()

	/**
	 * The duck-typed-degrade / template-select / render / store pipeline
	 * (design.md D2/D4/D5), delegating the docudesk-facing mechanics to
	 * `OfferLetterService`. Every failure path saves `offerSigningStatus`
	 * and returns a ready-to-return outcome; success returns the (post
	 * file-store) Application, the real file id, and the file name so the
	 * caller can proceed to `createSigningRequest()`.
	 *
	 * @param string $applicationId The Application id.
	 * @param array<string, mixed> $application The resolved Application.
	 * @param string|null $userId The acting user id, or null.
	 *
	 * @return array{outcome: array<string, mixed>|null, application: array<string, mixed>, fileId: int, fileName: string}
	 */
	private function generateAndStoreLetter(string $applicationId, array $application, ?string $userId): array {
		if ($this->docudeskAvailable() === false) {
			$application = $this->applications->save($application, ['offerSigningStatus' => 'skipped-no-docudesk']);

			return $this->letterFailure(
				$applicationId,
				$application,
				'skipped-no-docudesk',
				'Docudesk is niet geïnstalleerd of de renderer/ondertekenservice kon niet worden geladen; deze poging kan later opnieuw worden uitgevoerd.'
			);
		}

		$vacancyId = trim((string)($application['vacancyId'] ?? ''));
		$result = $this->letterService->generateAndStore($applicationId, $vacancyId, $userId);

		if ($result['success'] === false) {
			$application = $this->applications->save($application, ['offerSigningStatus' => 'failed']);
			return $this->letterFailure($applicationId, $application, 'failed', (string)$result['error']);
		}

		// Persist the real file id BEFORE the createRequest() attempt (design.md
		// D5) -- a downstream "No authenticated user" throw must never lose the
		// already-stored PDF.
		$application = $this->applications->save($application, ['offerLetterFileId' => $result['fileId']]);

		return ['outcome' => null, 'application' => $application, 'fileId' => $result['fileId'], 'fileName' => $result['fileName']];
	}//end generateAndStoreLetter()

	/**
	 * Build a `generateAndStoreLetter()` failure result.
	 *
	 * @param string $applicationId The Application id.
	 * @param array<string, mixed> $application The (post-write) Application.
	 * @param string $status The outcome status.
	 * @param string $message A human-readable outcome message.
	 *
	 * @return array{outcome: array<string, mixed>, application: array<string, mixed>, fileId: int, fileName: string}
	 */
	private function letterFailure(string $applicationId, array $application, string $status, string $message): array {
		return [
			'outcome' => $this->outcome($applicationId, $application, $status, $message),
			'application' => $application,
			'fileId' => 0,
			'fileName' => '',
		];

	}//end letterFailure()

	/**
	 * Best-effort cancel of a stale PENDING/IN_PROGRESS signing request
	 * before superseding it with a fresh attempt (design.md D6). A failure
	 * (including the D5 "No authenticated user" CLI-session gap) is logged
	 * and never blocks the fresh attempt that follows.
	 *
	 * @param array<string, mixed> $application The Application carrying the stale request.
	 *
	 * @return void
	 */
	private function supersedeStaleRequest(array $application): void {
		$oldRequestId = trim((string)($application['offerSigningRequestId'] ?? ''));
		if ($oldRequestId === '' || $this->docudeskAvailable() === false) {
			return;
		}

		try {
			$this->signingService()->cancelRequest($oldRequestId);
		} catch (\Throwable $e) {
			$this->logger->info('OfferEsignService: kon oude signing-request ' . $oldRequestId . ' niet annuleren (wordt genegeerd): ' . $e->getMessage());
		}

	}//end supersedeStaleRequest()

	/**
	 * Call `SigningService::createRequest()` with the VERIFIED real field
	 * shape (design.md Context/D5) and persist the outcome. The whole call
	 * is wrapped so `RuntimeException('No authenticated user')` never
	 * escapes (REQ-OFFR-003).
	 *
	 * @param string $applicationId The Application id.
	 * @param array<string, mixed> $application The Application, current (post file-store) state.
	 * @param int $fileId The stored offer-letter file id.
	 * @param string $fileName The stored offer-letter file name.
	 *
	 * @return array<string, mixed>
	 */
	private function createSigningRequest(string $applicationId, array $application, int $fileId, string $fileName): array {
		$candidateName = (string)($application['candidateName'] ?? '');
		$email = (string)($application['email'] ?? '');

		// No-external-signer gap (class docblock, defect-2): docudesk's
		// SigningService::sign() authorizes strictly on
		// `signer.userId === $user->getUID()`. Resolve BEFORE calling
		// createRequest() -- an unresolvable candidate must never reach
		// docudesk with an empty userId (that used to crash on the
		// signerRecord's NOT NULL `user_id` column, design.md D8).
		$candidateUserId = $this->signingRecovery->resolveCandidateUserId($email);
		if ($candidateUserId === null) {
			$application = $this->applications->save($application, ['offerSigningStatus' => 'failed']);
			return $this->outcome(
				$applicationId,
				$application,
				'failed',
				'no-nextcloud-user-for-candidate — docudesk kan niet ondertekenen voor externe (niet-Nextcloud-)ondertekenaars '
				. '(zie docudesk SigningService::sign, dat autoriseert op signer.userId): geen Nextcloud-gebruiker gevonden voor "' . $email . '".'
			);
		}

		$deadline = (new DateTimeImmutable())
			->modify('+' . $this->settingsService->getOfferSigningDeadlineDays() . ' days')
			->format(DateTimeInterface::ATOM);

		$requestData = [
			'documentFileId' => (string)$fileId,
			'documentName' => $fileName,
			'signatureLevel' => 'SES',
			'signingMode' => 'sequential',
			'deadline' => $deadline,
			// The VERIFIED real field: `signers`, NOT `signerIds` --
			// createRequest() creates the SignerRecord objects itself and
			// derives signerIds internally (design.md Context).
			'signers' => [
				[
					'userId' => $candidateUserId,
					'displayName' => $candidateName,
					'email' => $email,
					'order' => 0,
				],
			],
			// Provenance fields threaded through for cross-app correlation
			// (design.md Context / D3) AND for the orphan-recovery lookup
			// below (design.md D8, defect-3).
			// FROZEN at the old app id: this value is BOTH written onto new docudesk
			// signing requests AND used as the lookup key that recovers orphaned
			// in-flight ones. Renaming it would make every already-raised request
			// unfindable. Not to be "finished" with the rest of the rename.
			'sourceApp' => 'hrmq',
			'subjectRegister' => $this->settingsService->getRegisterSlug(),
			'subjectSchema' => self::APPLICATION_SCHEMA,
			'subjectId' => $applicationId,
			'subjectLabel' => $candidateName,
			'externalReference' => $applicationId,
			'correlationId' => $applicationId,
		];

		$signingService = $this->signingService();
		try {
			$created = $signingService->createRequest($requestData);
		} catch (\Throwable $e) {
			// Never let RuntimeException('No authenticated user') (design.md
			// D5 -- always true for a genuine occ CLI process) escape.
			// createRequest() writes the signing-request row BEFORE the
			// signer-record write that can throw, so a best-effort recovery
			// is attempted (design.md D8, defect-3) rather than silently
			// stranding the orphan.
			$recoveredRequestId = $this->signingRecovery->recoverOrphanedRequestId($signingService, $applicationId, $fileId);

			$fields = ['offerSigningStatus' => 'failed'];
			if ($recoveredRequestId !== null) {
				$fields['offerSigningRequestId'] = $recoveredRequestId;
			}

			$application = $this->applications->save($application, $fields);

			$message = 'Aanvragen van de e-handtekening via docudesk is mislukt: ' . $e->getMessage();
			$message .= ($recoveredRequestId !== null)
				? ' Een gedeeltelijk aangemaakte signing-request (' . $recoveredRequestId . ') is teruggevonden en blijft bereikbaar via syncSignatureStatus/cancelRequest.'
				: ' Kon een eventueel gedeeltelijk aangemaakte signing-request niet eenduidig terugvinden -- zoek zo nodig handmatig in het signingRequest-register op correlationId="' . $applicationId . '".';

			return $this->outcome($applicationId, $application, 'failed', $message);
		}//end try

		$requestId = (string)($created['id'] ?? $created['uuid'] ?? '');
		$newStatus = (string)($created['status'] ?? 'PENDING');

		$application = $this->applications->save(
			$application,
			[
				'offerSigningRequestId' => ($requestId === '' ? null : $requestId),
				'offerSigningStatus' => $newStatus,
			]
		);

		return $this->outcome($applicationId, $application, 'requested', 'Aanbiedingsbrief gegenereerd en e-handtekeningaanvraag aangemaakt.');
	}//end createSigningRequest()

	/**
	 * Read-only poll of the docudesk signing-request status onto
	 * `Application.offerSigningStatus`. NEVER writes `Application.status` or
	 * invokes the `aannemen` transition (design.md D7, REQ-OFFR-006) --
	 * observing `COMPLETED` here is informational only. `getRequest()`
	 * carries no session guard, so this is genuinely CLI-safe (design.md D5
	 * point 3), unlike `requestSignature()`.
	 *
	 * @param string|null $applicationId One Application id, or null for the default scope: every Application whose `offerSigningRequestId` is set and `offerSigningStatus` is PENDING/IN_PROGRESS.
	 *
	 * @return array<int, array<string, mixed>> One outcome per polled Application.
	 *
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-006
	 */
	public function syncSignatureStatus(?string $applicationId = null): array {
		$applicationId = ($applicationId !== null && trim($applicationId) !== '') ? trim($applicationId) : null;

		$targets = $this->resolveSyncTargets($applicationId);
		if (is_array($targets) === false) {
			// Explicit id given but not found.
			return [$this->outcome((string)$applicationId, null, 'usage-error', 'Application niet gevonden: ' . $applicationId)];
		}

		if ($this->docudeskAvailable() === false) {
			$results = [];
			foreach ($targets as $application) {
				$appId = (string)($application['id'] ?? $application['@self']['id'] ?? '');
				$results[] = $this->outcome($appId, $application, 'skipped-no-docudesk', 'Docudesk is niet beschikbaar; status kan niet worden gesynchroniseerd.');
			}

			return $results;
		}

		$results = [];
		foreach ($targets as $application) {
			$results[] = $this->syncOne($application);
		}

		return $results;
	}//end syncSignatureStatus()

	/**
	 * Resolve the Application(s) `syncSignatureStatus()` polls.
	 *
	 * @param string|null $applicationId One Application id, or null for the default scope.
	 *
	 * @return array<int, array<string, mixed>>|false The target rows, or false when an explicit id does not resolve.
	 */
	private function resolveSyncTargets(?string $applicationId): array|false {
		if ($applicationId !== null) {
			$application = $this->applications->find($applicationId);
			return $application === null ? false : [$application];
		}

		$targets = [];
		foreach ($this->applications->loadAll() as $application) {
			$requestId = trim((string)($application['offerSigningRequestId'] ?? ''));
			$signingStatus = (string)($application['offerSigningStatus'] ?? '');
			if ($requestId === '' || in_array($signingStatus, self::ACTIVE_SIGNING_STATUSES, true) === false) {
				continue;
			}

			$targets[] = $application;
		}

		return $targets;
	}//end resolveSyncTargets()

	/**
	 * Poll one Application's signing request and reflect its status.
	 *
	 * @param array<string, mixed> $application The Application to sync.
	 *
	 * @return array<string, mixed>
	 */
	private function syncOne(array $application): array {
		$appId = (string)($application['id'] ?? $application['@self']['id'] ?? '');
		$requestId = trim((string)($application['offerSigningRequestId'] ?? ''));

		if ($requestId === '') {
			return $this->outcome($appId, $application, 'usage-error', 'Application heeft geen offerSigningRequestId; niets te synchroniseren.');
		}

		try {
			$request = $this->signingService()->getRequest($requestId);
		} catch (\Throwable $e) {
			// Not-found (or any other read failure): log + leave the
			// Application's fields unchanged rather than throwing (REQ-OFFR-006).
			$this->logger->info('OfferEsignService: kon signing-request ' . $requestId . ' niet ophalen (status blijft ongewijzigd): ' . $e->getMessage());
			return $this->outcome($appId, $application, 'not-found', 'Signing-request niet gevonden: ' . $requestId . '; status blijft ongewijzigd.');
		}

		if ($request === null) {
			return $this->outcome($appId, $application, 'not-found', 'Signing-request niet toegankelijk: ' . $requestId . '; status blijft ongewijzigd.');
		}

		$newStatus = (string)($request['status'] ?? '');
		if ($newStatus === '') {
			return $this->outcome($appId, $application, 'not-found', 'Signing-request bevat geen status; status blijft ongewijzigd.');
		}

		$updated = $this->applications->save($application, ['offerSigningStatus' => $newStatus]);

		return $this->outcome($appId, $updated, 'synced', 'offerSigningStatus bijgewerkt naar ' . $newStatus . '.');
	}//end syncOne()

	/**
	 * Duck-typed docudesk availability probe (design.md D5, REQ-OFFR-004):
	 * docudesk must be installed AND all three service FQCNs must resolve
	 * from the container -- `SigningService` here, `DocumentService`/
	 * `TemplateService` delegated to `OfferLetterService::available()`.
	 *
	 * @return bool
	 */
	private function docudeskAvailable(): bool {
		if ($this->appManager->isInstalled(self::DOCUDESK_APP_ID) === false) {
			return false;
		}

		try {
			$this->signingService();
		} catch (\Throwable $e) {
			return false;
		}

		return $this->letterService->available();
	}//end docudeskAvailable()

	/**
	 * Build the outcome array returned by requestSignature()/syncSignatureStatus().
	 *
	 * @param string $applicationId The Application id.
	 * @param array<string, mixed>|null $application The (post-write) Application, if any.
	 * @param string $status The outcome status (a leaf-local verb, distinct from Application.status).
	 * @param string $message A human-readable outcome message.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $applicationId, ?array $application, string $status, string $message): array {
		return [
			'applicationId' => $applicationId,
			'status' => $status,
			'message' => $message,
			'offerLetterFileId' => $application['offerLetterFileId'] ?? null,
			'offerSigningRequestId' => $application['offerSigningRequestId'] ?? null,
			'offerSigningStatus' => $application['offerSigningStatus'] ?? null,
		];

	}//end outcome()

	/**
	 * @return mixed docudesk's SigningService, resolved by string FQCN only.
	 */
	private function signingService(): mixed {
		return $this->container->get(self::SIGNING_SERVICE_FQCN);
	}//end signingService()

}//end class
