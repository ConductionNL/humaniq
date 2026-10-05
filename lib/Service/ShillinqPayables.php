<?php

/**
 * Shillinq Payables
 *
 * The shillinq side of the wage tax remittance hand-off
 * (payroll-wage-tax-remittance-shillinq): probes for shillinq, looks up the
 * Belastingdienst payee and writes, or adopts, the draft APTransaction.
 * Split from WageTaxRemittanceService so each class keeps one concern.
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
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reads and writes shillinq's payables, duck-typed (design D5, D6).
 *
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */
class ShillinqPayables {

	/**
	 * Shillinq's app id, probed duck-typed and never required (design D6).
	 *
	 * @var string
	 */
	private const SHILLINQ_APP_ID = 'shillinq';

	/**
	 * Shillinq's register slug.
	 *
	 * @var string
	 */
	private const SHILLINQ_REGISTER = 'shillinq';

	/**
	 * Shillinq's payable schema.
	 *
	 * @var string
	 */
	private const PAYABLE_SCHEMA = 'APTransaction';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container       For the lazy ObjectService.
	 * @param IAppManager        $appManager      To probe for shillinq.
	 * @param SettingsService    $settingsService To check OpenRegister is there.
	 * @param LoggerInterface    $logger          The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Duck-typed probe: shillinq installed and its payables readable (design D6).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-003
	 */
	public function available(): bool {
		if ($this->appManager->isInstalled(self::SHILLINQ_APP_ID) === false) {
			return false;
		}

		try {
			$this->objectService()->setRegister(self::SHILLINQ_REGISTER)->setSchema(self::PAYABLE_SCHEMA)->findAll(['limit' => 1]);
		} catch (\Throwable $e) {
			return false;
		}

		return true;
	}//end available()

	/**
	 * Whether shillinq knows a payee by id or slug.
	 *
	 * @param string $payeeId The configured payee.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-004
	 */
	public function hasPayee(string $payeeId): bool {
		foreach ($this->rows(schema: 'Payee', filters: []) as $payee) {
			if ($this->idOf(row: $payee) === $payeeId || (string)($payee['@self']['slug'] ?? ($payee['slug'] ?? '')) === $payeeId) {
				return true;
			}
		}

		return false;
	}//end hasPayee()

	/**
	 * Adopt a payable written before a crash, or write a new one (design D5).
	 *
	 * @param array<string, mixed> $payload The draft APTransaction.
	 *
	 * @return string The shillinq APTransaction id.
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-002
	 */
	public function createOrAdopt(array $payload): string {
		$filters = ['vendorId' => $payload['vendorId'], 'invoiceNumber' => $payload['invoiceNumber']];
		foreach ($this->rows(schema: self::PAYABLE_SCHEMA, filters: $filters) as $existing) {
			if ((string)($existing['vendorId'] ?? '') === $filters['vendorId'] && (string)($existing['invoiceNumber'] ?? '') === $filters['invoiceNumber']) {
				return $this->idOf(row: $existing);
			}
		}

		$saved = $this->objectService()->saveObject(
			object: $payload,
			register: self::SHILLINQ_REGISTER,
			schema: self::PAYABLE_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return $this->idOf(row: $this->toArray(row: $saved));
	}//end createOrAdopt()

	/**
	 * A row as an array.
	 *
	 * @param mixed $row An entity or array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
	 */
	public function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && (method_exists($row, 'jsonSerialize') === true || method_exists($row, 'getObject') === true)) {
			$data = (method_exists($row, 'jsonSerialize') === true ? (array)$row->jsonSerialize() : (array)$row->getObject());
			if (isset($data['id']) === false && isset($data['@self']['id']) === false && method_exists($row, 'getUuid') === true) {
				$data['id'] = (string)$row->getUuid();
			}

			return $data;
		}

		return [];
	}//end toArray()

	/**
	 * The id of a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
	 */
	public function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()

	/**
	 * Rows of a shillinq schema.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		try {
			$rows = $this->objectService()->setRegister(self::SHILLINQ_REGISTER)->setSchema($schema)->findAll(['filters' => $filters, 'limit' => 10000]);
		} catch (\Throwable $e) {
			$this->logger->warning('ShillinqPayables: could not read shillinq ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return array_map(fn (mixed $row): array => $this->toArray(row: $row), array_values((array)$rows));
	}//end rows()

	/**
	 * The ObjectService, after checking OpenRegister is there (ADR-083).
	 *
	 * @return mixed
	 */
	private function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException('humaniq requires the OpenRegister app, which is not installed on this instance.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()
}//end class
