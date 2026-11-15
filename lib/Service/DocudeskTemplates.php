<?php

/**
 * Docudesk Templates
 *
 * The docudesk mechanics `HrDocumentService` and `OfferLetterService` share:
 * resolving docudesk's `DocumentService`/`TemplateService` by string FQCN only
 * (no compile-time import, design.md D2), the both-services-resolve
 * availability probe, the template selection (config-UUID first, then
 * namespace/category discovery, fail closed on a lookup error, zero or
 * multiple matches, design.md D3/D4) and the render options handed to
 * `DocumentService::generateDocument()`.
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
 * @spec openspec/specs/humaniq-docudesk-documents/spec.md#REQ-HDD-003
 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Support\ConvertsRowsToArrays;
use OCA\Humaniq\Support\FleetAppId;
use OCA\Humaniq\Support\NormalisesRows;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Resolves docudesk's services, selects a template and builds render options.
 *
 * @spec openspec/specs/humaniq-docudesk-documents/spec.md#REQ-HDD-003
 */
final class DocudeskTemplates {

	use ConvertsRowsToArrays;
	use NormalisesRows;

	/**
	 * The app docudesk's services live in.
	 *
	 * @var string
	 */
	private const DOCUMENT_APP = 'filinq';

	/**
	 * docudesk's rendering service, resolved by string FQCN only.
	 *
	 * @var string
	 */
	private const DOCUMENT_SERVICE_CLASS = 'Service\DocumentService';

	/**
	 * docudesk's template lookup service, resolved by string FQCN only.
	 *
	 * @var string
	 */
	private const TEMPLATE_SERVICE_CLASS = 'Service\TemplateService';

	/**
	 * The docudesk template namespace humaniq's own templates live under.
	 *
	 * @var string
	 */
	// FROZEN at the old app id: docudesk stores this app's templates under this
	// namespace. Renaming it would make them unreachable and generation would
	// quietly find no template rather than fail loudly.
	private const TEMPLATE_NAMESPACE = 'hrmq';

	/**
	 * @param ContainerInterface $container DI container for lazy docudesk resolution.
	 * @param SettingsService $settingsService Configured template ids and employer block.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
	) {

	}//end __construct()

	/**
	 * Whether docudesk's `DocumentService`/`TemplateService` can both be
	 * resolved from the container (design.md D5).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/humaniq-docudesk-documents/spec.md#REQ-HDD-005
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
	 * Template selection: config-UUID first, then namespace/category
	 * discovery, fail closed on zero/multiple matches (design.md D3).
	 *
	 * @param string $documentType The document type (docudesk template category).
	 *
	 * @return array{templateId: string|null, error: string|null}
	 *
	 * @spec openspec/specs/humaniq-docudesk-documents/spec.md#REQ-HDD-003
	 */
	public function selectTemplate(string $documentType): array {
		$configured = trim($this->settingsService->getDocumentsTemplateId($documentType));
		if ($configured !== '') {
			return ['templateId' => $configured, 'error' => null];
		}

		try {
			$templates = $this->templateService()->getTemplatesByNamespace(self::TEMPLATE_NAMESPACE);
		} catch (\Throwable $e) {
			return $this->refused('Kon docudesk-sjablonen niet opzoeken: ' . $e->getMessage());
		}

		return $this->resolveMatches($this->inCategory($templates, $documentType), $documentType);
	}//end selectTemplate()

	/**
	 * The `options` payload for the docudesk render call (design.md D2):
	 * `adHocData` carries only the configured employer block and generation
	 * metadata -- never flattened object field values.
	 *
	 * @param string $documentType The document type.
	 * @param string|null $userId The acting user id, or null for 'system'.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/humaniq-docudesk-documents/spec.md#REQ-HDD-002
	 */
	public function renderOptions(string $documentType, ?string $userId): array {
		return [
			'format' => 'pdf',
			'userId' => ($userId !== null && trim($userId) !== '') ? $userId : 'system',
			'adHocData' => [
				'employer' => $this->settingsService->getDocumentsEmployerBlock(),
				'document' => [
					'type' => $documentType,
					'requestedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				],
			],
		];

	}//end renderOptions()

	/**
	 * @return mixed docudesk's DocumentService, resolved by string FQCN only (design.md D2).
	 *
	 * @spec openspec/specs/humaniq-docudesk-documents/spec.md#REQ-HDD-002
	 */
	public function documentService(): mixed {
		// Throws when no candidate namespace resolves, preserving the
		// container->get() contract the available() probe relies on:
		// a null return here would read as "resolved fine" and let the
		// caller proceed against nothing.
		return FleetAppId::getService($this->container, self::DOCUMENT_APP, self::DOCUMENT_SERVICE_CLASS)
			?? throw new RuntimeException('filinq service '.self::DOCUMENT_SERVICE_CLASS.' is not available under any known namespace.');
	}//end documentService()

	/**
	 * Resolve the discovery-phase template matches -- fail closed on
	 * zero/multiple matches or a match without an id (design.md D3).
	 *
	 * @param array<int, array<string, mixed>> $matches The category-filtered templates.
	 * @param string $documentType The document type the matches were filtered on.
	 *
	 * @return array{templateId: string|null, error: string|null}
	 */
	private function resolveMatches(array $matches, string $documentType): array {
		if (count($matches) === 0) {
			return $this->refused(sprintf('Geen docudesk-sjabloon gevonden voor "%s" in namespace "hrmq"; genereren is geweigerd.', $documentType));
		}

		if (count($matches) > 1) {
			return $this->refused($this->ambiguityError($matches, $documentType));
		}

		$id = (string)($matches[0]['id'] ?? $matches[0]['@self']['id'] ?? '');
		if ($id === '') {
			return $this->refused('Het gevonden docudesk-sjabloon heeft geen id.');
		}

		return ['templateId' => $id, 'error' => null];
	}//end resolveMatches()

	/**
	 * The discovered templates whose `category` is the document type.
	 *
	 * @param mixed $templates The TemplateService answer.
	 * @param string $documentType The document type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function inCategory(mixed $templates, string $documentType): array {
		$matches = [];
		foreach ($this->normaliseRows($templates) as $template) {
			if ((string)($template['category'] ?? '') === $documentType) {
				$matches[] = $template;
			}
		}

		return $matches;
	}//end inCategory()

	/**
	 * The fail-closed message for several templates in one category, naming
	 * each by `name`, `id`, `@self.id` or `?`.
	 *
	 * @param array<int, array<string, mixed>> $matches The category-filtered templates.
	 * @param string $documentType The document type.
	 *
	 * @return string
	 */
	private function ambiguityError(array $matches, string $documentType): string {
		$names = array_map(
			static fn (array $t): string => (string)($t['name'] ?? ($t['id'] ?? ($t['@self']['id'] ?? '?'))),
			$matches
		);

		return sprintf(
			'Meerdere docudesk-sjablonen (%s) voor "%s" in namespace "hrmq"; genereren is geweigerd (nooit gokken tussen sjablonen die officiële documenten opleveren).',
			implode(', ', $names),
			$documentType
		);
	}//end ambiguityError()

	/**
	 * A fail-closed selection result carrying its reason.
	 *
	 * @param string $error Why no template was selected.
	 *
	 * @return array{templateId: null, error: string}
	 */
	private function refused(string $error): array {
		return ['templateId' => null, 'error' => $error];
	}//end refused()

	/**
	 * @return mixed docudesk's TemplateService, resolved by string FQCN only (design.md D2).
	 */
	private function templateService(): mixed {
		// Throws when no candidate namespace resolves -- see documentService().
		return FleetAppId::getService($this->container, self::DOCUMENT_APP, self::TEMPLATE_SERVICE_CLASS)
			?? throw new RuntimeException('filinq service '.self::TEMPLATE_SERVICE_CLASS.' is not available under any known namespace.');
	}//end templateService()
}//end class
