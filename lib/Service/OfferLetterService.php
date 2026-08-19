<?php

/**
 * Offer Letter Service
 *
 * The docudesk-facing half of offer-esign's generation pipeline, extracted
 * from `OfferEsignService` as a plain composed collaborator (not a public
 * surface of its own -- `OfferEsignService` remains the ONE service
 * `OfferController`/the two occ commands depend on, per design.md D1).
 * Owns exactly the template-selection (config-first/discovery-second/
 * fail-closed, design.md D4) / render (`DocumentService::generateDocument()`)
 * / store (`FileService::addFile()`, capturing the real `File::getId()`)
 * mechanics -- `OfferEsignService` still owns the Application read/write
 * model, the docudesk-installed probe, `SigningService`, and every outcome
 * decision.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;

/**
 * Selects an `aanbiedingsbrief` template, renders it via docudesk, and
 * stores the resulting PDF via OpenRegister's FileService.
 */
class OfferLetterService {

	/**
	 * docudesk's rendering service, resolved by string FQCN only (no
	 * compile-time import).
	 *
	 * @var string
	 */
	private const DOCUMENT_SERVICE_FQCN = 'OCA\DocuDesk\Service\DocumentService';

	/**
	 * docudesk's template lookup service, resolved by string FQCN only.
	 *
	 * @var string
	 */
	private const TEMPLATE_SERVICE_FQCN = 'OCA\DocuDesk\Service\TemplateService';

	/**
	 * OpenRegister's file-storage service (read-only reuse, not docudesk).
	 *
	 * @var string
	 */
	private const OBJECT_FILE_SERVICE_FQCN = 'OCA\OpenRegister\Service\FileService';

	/**
	 * The docudesk template namespace hrmq's own templates live under.
	 *
	 * @var string
	 */
	private const TEMPLATE_NAMESPACE = 'hrmq';

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
	private const APPLICATION_SCHEMA = 'Application';

	/**
	 * @var string
	 */
	private const VACANCY_SCHEMA = 'Vacancy';

	/**
	 * @param ContainerInterface $container DI container for lazy docudesk/FileService resolution.
	 * @param SettingsService $settingsService Register slug, employer block, template config.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
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
		try {
			$this->documentService();
			$this->templateService();
		} catch (\Throwable $e) {
			return false;
		}

		return true;
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
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-002
	 */
	public function generateAndStore(string $applicationId, string $vacancyId, ?string $userId): array {
		$selected = $this->selectTemplate();
		if ($selected['error'] !== null) {
			return $this->failure((string)$selected['error']);
		}

		$dataRefs = $this->buildDataRefs($applicationId, $vacancyId);
		$options = $this->buildOptions($userId);

		try {
			$rendered = $this->documentService()->generateDocument($selected['templateId'], $dataRefs, $options);
		} catch (\Throwable $e) {
			return $this->failure('Genereren van de aanbiedingsbrief via docudesk is mislukt: ' . $e->getMessage());
		}

		$content = (string)($rendered['content'] ?? '');
		if ($content === '') {
			return $this->failure('Docudesk leverde geen documentinhoud terug.');
		}

		return $this->store($applicationId, $content);
	}//end generateAndStore()

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
	 * Template selection: config-UUID first, then namespace/category
	 * discovery, fail closed on zero/multiple matches (design.md D4 --
	 * `HrDocumentService::selectTemplate()`'s algorithm, re-implemented
	 * against this leaf's own subject model, reusing the EXISTING
	 * `aanbiedingsbrief` category).
	 *
	 * @return array{templateId: string|null, error: string|null}
	 */
	private function selectTemplate(): array {
		$configured = trim($this->settingsService->getDocumentsTemplateId(self::DOCUMENT_TYPE));
		if ($configured !== '') {
			return ['templateId' => $configured, 'error' => null];
		}

		try {
			$templates = $this->templateService()->getTemplatesByNamespace(self::TEMPLATE_NAMESPACE);
		} catch (\Throwable $e) {
			return ['templateId' => null, 'error' => 'Kon docudesk-sjablonen niet opzoeken: ' . $e->getMessage()];
		}

		$matches = [];
		foreach ((is_array($templates) === true ? $templates : []) as $row) {
			$template = $this->toArray($row);
			if ((string)($template['category'] ?? '') === self::DOCUMENT_TYPE) {
				$matches[] = $template;
			}
		}

		return $this->resolveTemplateMatches($matches);
	}//end selectTemplate()

	/**
	 * Resolve the discovery-phase template matches -- fail closed on
	 * zero/multiple matches (design.md D4).
	 *
	 * @param array<int, array<string, mixed>> $matches The category-filtered templates.
	 *
	 * @return array{templateId: string|null, error: string|null}
	 */
	private function resolveTemplateMatches(array $matches): array {
		if (count($matches) === 0) {
			return [
				'templateId' => null,
				'error' => sprintf('Geen docudesk-sjabloon gevonden voor "%s" in namespace "hrmq"; genereren is geweigerd.', self::DOCUMENT_TYPE),
			];
		}

		if (count($matches) > 1) {
			$names = array_map(
				static fn (array $t): string => (string)($t['name'] ?? ($t['id'] ?? ($t['@self']['id'] ?? '?'))),
				$matches
			);

			return [
				'templateId' => null,
				'error' => sprintf(
					'Meerdere docudesk-sjablonen (%s) voor "%s" in namespace "hrmq"; genereren is geweigerd (nooit gokken tussen sjablonen die officiële documenten opleveren).',
					implode(', ', $names),
					self::DOCUMENT_TYPE
				),
			];
		}

		$id = (string)($matches[0]['id'] ?? $matches[0]['@self']['id'] ?? '');
		if ($id === '') {
			return ['templateId' => null, 'error' => 'Het gevonden docudesk-sjabloon heeft geen id.'];
		}

		return ['templateId' => $id, 'error' => null];
	}//end resolveTemplateMatches()

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
	 * The `options` payload for the docudesk render call (design.md D3):
	 * `adHocData` carries only the configured employer block and generation
	 * metadata -- no candidate PII flattened.
	 *
	 * @param string|null $userId The acting user id, or null for 'system'.
	 *
	 * @return array<string, mixed>
	 */
	private function buildOptions(?string $userId): array {
		return [
			'format' => 'pdf',
			'userId' => ($userId !== null && trim($userId) !== '') ? $userId : 'system',
			'adHocData' => [
				'employer' => $this->settingsService->getDocumentsEmployerBlock(),
				'document' => [
					'type' => self::DOCUMENT_TYPE,
					'requestedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				],
			],
		];

	}//end buildOptions()

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
	 * Normalise a docudesk row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * @return mixed The OpenRegister FileService (read-only reuse -- never docudesk).
	 */
	private function fileService(): mixed {
		return $this->container->get(self::OBJECT_FILE_SERVICE_FQCN);
	}//end fileService()

	/**
	 * @return mixed docudesk's DocumentService, resolved by string FQCN only.
	 */
	private function documentService(): mixed {
		return $this->container->get(self::DOCUMENT_SERVICE_FQCN);
	}//end documentService()

	/**
	 * @return mixed docudesk's TemplateService, resolved by string FQCN only.
	 */
	private function templateService(): mixed {
		return $this->container->get(self::TEMPLATE_SERVICE_FQCN);
	}//end templateService()

	/**
	 * @return string The configured hrmq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
