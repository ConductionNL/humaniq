<?php

/**
 * Offer Letter Service
 *
 * The docudesk-facing half of offer-esign's generation pipeline, extracted
 * from `OfferEsignService` as a plain composed collaborator (not a public
 * surface of its own -- `OfferEsignService` remains the ONE service
 * `OfferController`/the two occ commands depend on, per design.md D1).
 * Owns exactly the render (`DocumentService::generateDocument()`) / store
 * (`FileService::addFile()`, capturing the real `File::getId()`) mechanics,
 * with the template selection (config-first/discovery-second/fail-closed,
 * design.md D4) and render options shared with `HrDocumentService` through
 * `DocudeskTemplates` -- `OfferEsignService` still owns the Application read/write
 * model, the docudesk-installed probe, `SigningService`, and every outcome
 * decision.
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
 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;

/**
 * Selects an `aanbiedingsbrief` template, renders it via docudesk, and
 * stores the resulting PDF via OpenRegister's FileService.
 */
class OfferLetterService {

	/**
	 * OpenRegister's file-storage service (read-only reuse, not docudesk).
	 *
	 * @var string
	 */
	private const OBJECT_FILE_SERVICE_FQCN = 'OCA\OpenRegister\Service\FileService';

	/**
	 * The docudesk template category / documentType for an offer letter --
	 * reuses the EXISTING `aanbiedingsbrief` category (design.md D4).
	 *
	 * @var string
	 */
	private const DOCUMENT_TYPE = 'aanbiedingsbrief';

	/**
	 * @var string
	 */
	private const APPLICATION_SCHEMA = 'job-application';

	/**
	 * @var string
	 */
	private const VACANCY_SCHEMA = 'Vacancy';

	/**
	 * @param ContainerInterface $container DI container for lazy FileService resolution.
	 * @param SettingsService $settingsService Register slug.
	 * @param DocudeskTemplates $docudesk docudesk service resolution, template selection and render options.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly DocudeskTemplates $docudesk,
	) {

	}//end __construct()

	/**
	 * Whether docudesk's `DocumentService`/`TemplateService` can both be
	 * resolved from the container (part of `OfferEsignService::docudeskAvailable()`'s
	 * duck-typed probe, design.md D5).
	 *
	 * @return bool
	 */
	public function available(): bool {
		return $this->docudesk->available();
	}//end available()

	/**
	 * Select the `aanbiedingsbrief` template, render it with `dataRefs =
	 * [Application, Vacancy]` (design.md D3 -- no Employee ref), and store
	 * the returned PDF via OpenRegister's FileService, capturing the real
	 * Nextcloud file id (`File::getId()`, not merely a path).
	 *
	 * @param string $applicationId The Application id.
	 * @param string $vacancyId The Vacancy id (empty when unresolvable -- defensive, Application.vacancyId is required).
	 * @param string|null $userId The acting user id, or null for 'system'.
	 *
	 * @return array{success: bool, fileId: int, fileName: string, error: string|null}
	 *
	 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-002
	 */
	public function generateAndStore(string $applicationId, string $vacancyId, ?string $userId): array {
		$selected = $this->docudesk->selectTemplate(self::DOCUMENT_TYPE);
		if ($selected['error'] !== null) {
			return $this->failure((string)$selected['error']);
		}

		$dataRefs = $this->buildDataRefs($applicationId, $vacancyId);
		$options = $this->docudesk->renderOptions(self::DOCUMENT_TYPE, $userId);

		try {
			$rendered = $this->docudesk->documentService()->generateDocument($selected['templateId'], $dataRefs, $options);
		} catch (\Throwable $e) {
			return $this->failure('Genereren van de aanbiedingsbrief via docudesk is mislukt: ' . $e->getMessage());
		}

		return $this->storeRendered($applicationId, $rendered);
	}//end generateAndStore()

	/**
	 * Store the rendered PDF, failing when docudesk returned no content.
	 *
	 * @param string $applicationId The Application id.
	 * @param mixed $rendered docudesk's `generateDocument()` answer.
	 *
	 * @return array{success: bool, fileId: int, fileName: string, error: string|null}
	 */
	private function storeRendered(string $applicationId, mixed $rendered): array {
		$content = (string)($rendered['content'] ?? '');
		if ($content === '') {
			return $this->failure('Docudesk leverde geen documentinhoud terug.');
		}

		return $this->store($applicationId, $content);
	}//end storeRendered()

	/**
	 * Store the rendered PDF and capture the real Nextcloud file id.
	 *
	 * @param string $applicationId The Application id.
	 * @param string $content The rendered PDF content.
	 *
	 * @return array{success: bool, fileId: int, fileName: string, error: string|null}
	 */
	private function store(string $applicationId, string $content): array {
		$fileName = $this->fileName($applicationId);

		try {
			$file = $this->fileService()->addFile($applicationId, $fileName, $content);
			$fileId = (is_object($file) === true && method_exists($file, 'getId') === true) ? (int)$file->getId() : 0;
		} catch (\Throwable $e) {
			return $this->failure('Opslaan van de aanbiedingsbrief is mislukt: ' . $e->getMessage());
		}

		if ($fileId <= 0) {
			return $this->failure('Het opgeslagen bestand heeft geen geldig Nextcloud file-id.');
		}

		return ['success' => true, 'fileId' => $fileId, 'fileName' => $fileName, 'error' => null];
	}//end store()

	/**
	 * @param string $error The failure message.
	 *
	 * @return array{success: bool, fileId: int, fileName: string, error: string|null}
	 */
	private function failure(string $error): array {
		return ['success' => false, 'fileId' => 0, 'fileName' => '', 'error' => $error];
	}//end failure()

	/**
	 * The `dataRefs` payload for the docudesk render call (design.md D3):
	 * exactly the Application and its Vacancy -- deliberately no Employee ref
	 * (none exists pre-hire).
	 *
	 * @param string $applicationId The Application id.
	 * @param string $vacancyId The Vacancy id.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function buildDataRefs(string $applicationId, string $vacancyId): array {
		$refs = [
			['register' => $this->register(), 'schema' => self::APPLICATION_SCHEMA, 'id' => $applicationId],
		];

		if ($vacancyId !== '') {
			$refs[] = ['register' => $this->register(), 'schema' => self::VACANCY_SCHEMA, 'id' => $vacancyId];
		}

		return $refs;
	}//end buildDataRefs()

	/**
	 * The stored PDF's file name: `aanbiedingsbrief-{applicationId}-{YYYY-MM-DD}.pdf`
	 * -- applicationId, not candidateName, in the filename (AVG-minimisation).
	 *
	 * @param string $applicationId The Application id.
	 *
	 * @return string
	 */
	private function fileName(string $applicationId): string {
		return sprintf('%s-%s-%s.pdf', self::DOCUMENT_TYPE, $applicationId, gmdate('Y-m-d'));
	}//end fileName()

	/**
	 * @return mixed The OpenRegister FileService (read-only reuse -- never docudesk).
	 */
	private function fileService(): mixed {
		return $this->container->get(self::OBJECT_FILE_SERVICE_FQCN);
	}//end fileService()

	/**
	 * @return string The configured humaniq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
