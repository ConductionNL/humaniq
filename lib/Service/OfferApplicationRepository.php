<?php

/**
 * Offer Application Repository
 *
 * The Application read/write collaborator for offer-esign, extracted from
 * `OfferEsignService` (the `ReceiptExtractionRepository`/`ReceiptExtractionService`
 * precedent): owns resolving one Application by id, the field-merge save that
 * carries every existing field forward unchanged (OpenRegister's
 * `saveObject()` update path is PUT-semantic -- a property absent from the
 * payload is nulled, the exact trap fixed on receipt-ocr), and the
 * sync-all-backlog scan. `OfferEsignService` keeps every business decision
 * (stage guard, idempotency, docudesk calls); this class only ever reads or
 * writes the Application object itself.
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
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reads and writes the recruiting `Application` object for offer-esign.
 */
class OfferApplicationRepository {

	/**
	 * @var string
	 */
	private const APPLICATION_SCHEMA = 'Application';

	/**
	 * Max rows loaded for the sync-all backlog scan.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Find one Application by id, or null when it cannot be loaded/does not
	 * exist.
	 *
	 * @param string $applicationId The Application id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $applicationId): ?array {
		try {
			$row = $this->objectService()->find(id: $applicationId, register: $this->register(), schema: self::APPLICATION_SCHEMA);
		} catch (\Throwable $e) {
			$this->logger->info('OfferApplicationRepository: kon Application ' . $applicationId . ' niet laden: ' . $e->getMessage());
			return null;
		}

		return $row === null ? null : $this->toArray($row);
	}//end find()

	/**
	 * Update an existing Application by merging `$writes` onto its CURRENT
	 * fields. Carries every existing field (esp. `status`) forward unchanged
	 * -- OpenRegister's `saveObject()` update path is PUT-semantic, so a
	 * property absent from the payload would otherwise be nulled.
	 *
	 * @param array<string, mixed> $existing The current Application (pre-write).
	 * @param array<string, mixed> $writes The field values to write (never `status`).
	 *
	 * @return array<string, mixed> The saved Application, normalised to an array.
	 */
	public function save(array $existing, array $writes): array {
		$id = (string)($existing['id'] ?? $existing['@self']['id'] ?? '');

		$payload = array_merge($existing, $writes);
		unset($payload['@self']);

		$saved = $this->objectService()->saveObject(
			object: $payload,
			register: $this->register(),
			schema: self::APPLICATION_SCHEMA,
			uuid: ($id === '' ? null : $id),
			_rbac: false,
			_multitenancy: false
		);

		return $this->toArray($saved);
	}//end save()

	/**
	 * Load every Application (capped), as plain arrays -- the sync-all
	 * backlog scan.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function loadAll(): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema(self::APPLICATION_SCHEMA)->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('OfferApplicationRepository: kon Application niet laden: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end loadAll()

	/**
	 * Normalise a single ObjectService row (entity or array) to an array.
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
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured hrmq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
