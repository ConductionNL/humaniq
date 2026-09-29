<?php

/**
 * HR Document Signing Service
 *
 * Sends a generated HR document about an employee for electronic signature
 * through filinq (people-esign-hr-documents). The signers follow the
 * document type (D2): an employment contract is signed by the
 * administration's signatory and then by the employee; a werkgeversverklaring
 * or getuigschrift by the signatory only; with no signatory set, the acting
 * HR user signs for the employer. A payslip or annual statement is refused.
 * One request at a time per document (D3): a request while one is pending
 * or in progress answers the existing one, a completed one is final, any
 * other state may be retried. The sync (D5) reads statuses without a
 * session and, when an employment contract's signature completes, sets the
 * contract's `writtenContract` to true. Every save carries the stored
 * record's other fields, because OpenRegister's save replaces the object.
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
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Request and sync signatures on generated HR documents.
 *
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
 */
class HrDocumentSigningService {

	/**
	 * The document types that can be signed, and whether the employee signs too.
	 *
	 * @var array<string, bool>
	 */
	private const SIGNABLE = [
		'arbeidsovereenkomst' => true,
		'werkgeversverklaring' => false,
		'getuigschrift' => false,
	];

	/**
	 * States in which a request is still running.
	 *
	 * @var list<string>
	 */
	private const ACTIVE = ['PENDING', 'IN_PROGRESS'];

	/**
	 * The document schema.
	 *
	 * @var string
	 */
	private const DOCUMENT_SCHEMA = 'HrGeneratedDocument';

	/**
	 * OpenRegister's object file service.
	 *
	 * @var string
	 */
	private const FILE_SERVICE = 'OCA\OpenRegister\Service\FileService';

	/**
	 * Constructor.
	 *
	 * @param FilinqSigningGateway $signing   Raises and reads filinq requests.
	 * @param HoursRegisterGateway $register  Reads and writes the register.
	 * @param ContainerInterface   $container Resolves OpenRegister's file service.
	 * @param IUserManager         $users     Signer names and addresses.
	 * @param SettingsService      $settings  Register slug and signing deadline.
	 */
	public function __construct(
		private readonly FilinqSigningGateway $signing,
		private readonly HoursRegisterGateway $register,
		private readonly ContainerInterface $container,
		private readonly IUserManager $users,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * Raise a signing request for one generated document.
	 *
	 * @param array<string, mixed> $document     The document, with its `id`.
	 * @param string               $actingUserId The HR user asking.
	 *
	 * @return array{status: string, message: string, signingRequestId: string|null, signingStatus: string|null}
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-002
	 */
	public function requestSignature(array $document, string $actingUserId): array {
		$type = (string)($document['documentType'] ?? '');
		if (isset(self::SIGNABLE[$type]) === false || ($document['status'] ?? '') !== 'generated') {
			return $this->outcome($document, 'refused', 'Alleen een gegenereerde arbeidsovereenkomst, werkgeversverklaring of getuigschrift kan worden ondertekend.');
		}

		$current = (string)($document['signingStatus'] ?? '');
		if ($current === 'COMPLETED') {
			return $this->outcome($document, 'already-signed', 'Dit document is al ondertekend.');
		}

		if (in_array($current, self::ACTIVE, true) === true) {
			return $this->outcome($document, 'existing', 'Er loopt al een ondertekenverzoek voor dit document.');
		}

		if ($this->signing->available() === false) {
			$document = $this->saveDocument($document, ['signingStatus' => 'skipped-no-docudesk']);
			return $this->outcome($document, 'skipped-no-docudesk', 'De documentenapp is niet beschikbaar; er is niets verstuurd.');
		}

		$employee = ($this->register->findObjectData((string)($document['employeeId'] ?? ''), 'Employee') ?? []);
		$signers = $this->signers($type, $employee, $actingUserId);
		if ($signers === null) {
			$document = $this->saveDocument($document, ['signingStatus' => 'failed', 'errorMessage' => 'no-nextcloud-user-for-employee: de medewerker heeft geen Nextcloud-account en kan daarom niet ondertekenen.']);
			return $this->outcome($document, 'failed', (string)$document['errorMessage']);
		}

		$fileId = $this->fileId($document);
		if ($fileId === null) {
			$document = $this->saveDocument($document, ['signingStatus' => 'failed', 'errorMessage' => 'Het opgeslagen PDF-bestand van dit document is niet gevonden. Genereer het document opnieuw.']);
			return $this->outcome($document, 'failed', (string)$document['errorMessage']);
		}

		return $this->raise($document, $signers, $fileId, trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? '')));
	}//end requestSignature()

	/**
	 * Read the status of running requests and write it back.
	 *
	 * @param string|null $documentId One document, or null for every running request.
	 *
	 * @return list<array{status: string, message: string, signingRequestId: string|null, signingStatus: string|null}>
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
	 */
	public function syncSignatures(?string $documentId): array {
		if ($documentId !== null && $documentId !== '') {
			$one = $this->register->findObjectData($documentId, self::DOCUMENT_SCHEMA);
			$targets = ($one === null ? [] : [$one]);
		} else {
			$targets = array_filter(
				$this->register->loadAll(self::DOCUMENT_SCHEMA),
				static fn (array $doc): bool => in_array(($doc['signingStatus'] ?? ''), self::ACTIVE, true) === true
			);
		}

		$results = [];
		foreach ($targets as $document) {
			$results[] = $this->syncOne($document);
		}

		return $results;
	}//end syncSignatures()

	/**
	 * Raise the request and store its id and status.
	 *
	 * @param array<string, mixed>             $document The document.
	 * @param list<array<string, string|int>>  $signers  The signers, in order.
	 * @param int                              $fileId   The stored PDF.
	 * @param string                           $subject  The employee's name.
	 *
	 * @return array{status: string, message: string, signingRequestId: string|null, signingStatus: string|null}
	 */
	private function raise(array $document, array $signers, int $fileId, string $subject): array {
		$documentId = (string)($document['id'] ?? '');
		$created = $this->signing->create([
			'documentFileId' => (string)$fileId,
			'documentName' => basename((string)($document['filePath'] ?? 'document.pdf')),
			'signatureLevel' => 'SES',
			'signingMode' => 'sequential',
			'deadline' => (new DateTimeImmutable())->modify('+' . $this->settings->getOfferSigningDeadlineDays() . ' days')->format(DateTimeInterface::ATOM),
			'signers' => $signers,
			// Frozen provenance value, the recovery key of requests already raised (D1).
			'sourceApp' => 'hrmq',
			'subjectRegister' => $this->settings->getRegisterSlug(),
			'subjectSchema' => self::DOCUMENT_SCHEMA,
			'subjectId' => $documentId,
			'subjectLabel' => $subject,
			'externalReference' => $documentId,
			'correlationId' => $documentId,
		]);

		$document = $this->saveDocument(
			$document,
			[
				'signingRequestId' => $created['requestId'],
				'signingStatus' => $created['status'],
				'errorMessage' => ($created['error'] === null ? null : 'Het ondertekenverzoek is mislukt: ' . $created['error']),
			]
		);
		if ($created['status'] === 'failed') {
			return $this->outcome($document, 'failed', (string)$document['errorMessage']);
		}

		return $this->outcome($document, 'requested', 'Het ondertekenverzoek is verstuurd.');
	}//end raise()

	/**
	 * The signers for a document type, or null when the employee must sign and has no account.
	 *
	 * @param string               $type         The document type.
	 * @param array<string, mixed> $employee     The employee.
	 * @param string               $actingUserId The HR user asking.
	 *
	 * @return list<array<string, string|int>>|null
	 */
	private function signers(string $type, array $employee, string $actingUserId): ?array {
		$administration = ($this->register->findFiltered('hrAdministration', ['administrationId' => (string)($employee['administrationId'] ?? '')])[0] ?? []);
		$signatory = trim((string)($administration['signatoryUserId'] ?? ''));
		$uids = [($signatory === '' ? $actingUserId : $signatory)];
		if (self::SIGNABLE[$type] === true) {
			$own = trim((string)($employee['nextcloudUserId'] ?? ''));
			if ($own === '') {
				return null;
			}

			$uids[] = $own;
		}

		$signers = [];
		foreach ($uids as $order => $uid) {
			$user = $this->users->get($uid);
			$signers[] = [
				'userId' => $uid,
				'displayName' => (string)($user?->getDisplayName() ?? $uid),
				'email' => (string)($user?->getEMailAddress() ?? ''),
				'order' => $order,
			];
		}

		return $signers;
	}//end signers()

	/**
	 * The Nextcloud file id of the document's stored PDF.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return int|null
	 */
	private function fileId(array $document): ?int {
		try {
			$file = $this->container->get(self::FILE_SERVICE)->getFile((string)($document['id'] ?? ''), basename((string)($document['filePath'] ?? '')));
		} catch (\Throwable $e) {
			return null;
		}

		$fileId = (is_object($file) === true && method_exists($file, 'getId') === true) ? (int)$file->getId() : 0;

		return ($fileId > 0 ? $fileId : null);
	}//end fileId()

	/**
	 * Read one request and write its status back; close the contract flag on completion.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return array{status: string, message: string, signingRequestId: string|null, signingStatus: string|null}
	 */
	private function syncOne(array $document): array {
		$requestId = trim((string)($document['signingRequestId'] ?? ''));
		if ($requestId === '' || $this->signing->available() === false) {
			return $this->outcome($document, 'skipped', 'Geen ondertekenverzoek om te lezen, of de documentenapp is niet beschikbaar.');
		}

		try {
			$request = $this->signing->read($requestId);
		} catch (\Throwable $e) {
			$request = null;
		}

		$status = (string)($request['status'] ?? '');
		if ($status === '') {
			return $this->outcome($document, 'not-found', 'Het ondertekenverzoek ' . $requestId . ' is niet gevonden; de status blijft ongewijzigd.');
		}

		$writes = ['signingStatus' => $status];
		if ($status === 'COMPLETED') {
			$writes['signingCompletedAt'] = gmdate('Y-m-d\TH:i:s\Z');
			$this->markContractWritten($document);
		}

		return $this->outcome($this->saveDocument($document, $writes), 'synced', 'Status bijgewerkt naar ' . $status . '.');
	}//end syncOne()

	/**
	 * Set the linked contract's writtenContract to true for a signed employment contract.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
	 */
	private function markContractWritten(array $document): void {
		$contractId = trim((string)($document['contractId'] ?? ''));
		if (($document['documentType'] ?? '') !== 'arbeidsovereenkomst' || $contractId === '') {
			return;
		}

		$contract = $this->register->findObjectData($contractId, 'EmploymentContract');
		if ($contract === null || ($contract['writtenContract'] ?? false) === true) {
			return;
		}

		$this->register->save(array_merge($this->withoutMeta($contract), ['writtenContract' => true]), 'EmploymentContract', $contractId);
	}//end markContractWritten()

	/**
	 * Save the document with its stored fields carried forward.
	 *
	 * @param array<string, mixed> $document The document.
	 * @param array<string, mixed> $writes   The fields to write.
	 *
	 * @return array<string, mixed> The document as saved.
	 */
	private function saveDocument(array $document, array $writes): array {
		$id = (string)($document['id'] ?? '');
		$stored = ($this->register->findObjectData($id, self::DOCUMENT_SCHEMA) ?? $document);
		$payload = array_merge($this->withoutMeta($stored), $writes);
		$this->register->save($payload, self::DOCUMENT_SCHEMA, $id);

		return array_merge($payload, ['id' => $id]);
	}//end saveDocument()

	/**
	 * A record without its id and `@self`, ready to save.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutMeta(array $record): array {
		unset($record['id'], $record['@self']);
		return $record;
	}//end withoutMeta()

	/**
	 * The answer for the caller.
	 *
	 * @param array<string, mixed> $document The document as it now stands.
	 * @param string               $status   The outcome.
	 * @param string               $message  For the user.
	 *
	 * @return array{status: string, message: string, signingRequestId: string|null, signingStatus: string|null}
	 */
	private function outcome(array $document, string $status, string $message): array {
		return [
			'status' => $status,
			'message' => $message,
			'signingRequestId' => (isset($document['signingRequestId']) === true ? (string)$document['signingRequestId'] : null),
			'signingStatus' => (isset($document['signingStatus']) === true ? (string)$document['signingStatus'] : null),
		];
	}//end outcome()

}//end class
