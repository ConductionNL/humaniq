<?php

/**
 * Retention Expiry Service
 *
 * compliance-retention-expiry: walks the payroll family and the employee
 * records once a day and hands each to `PayrollRetentionGuardService`, which
 * releases a lapsed humaniq floor hold and marks the record for
 * OpenRegister's destruction list. humaniq deletes nothing itself: the
 * destruction list, its approval by an archivist and the hold re-check at
 * deletion time are OpenRegister's (ADR-022). Nothing is written while the
 * admin switch `retention_expiry_enabled` is off; the walk then only counts
 * what it would release and mark.
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
 * @spec openspec/changes/compliance-retention-expiry/specs/personnel-retention-expiry/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Walks the retained schemas and releases and marks what has expired.
 */
class RetentionExpiryService {

	/**
	 * The schemas whose records carry a humaniq statutory floor hold.
	 *
	 * @var array<int, string>
	 */
	public const HOLD_SCHEMAS = ['Payslip', 'PayrollRun', 'LoonaangifteFiling', 'PensionFiling'];

	/**
	 * Upper bound per schema per run, the fleet's findAll convention.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface           $container       DI container for OpenRegister's ObjectService.
	 * @param SettingsService              $settingsService Register slug and the admin switch.
	 * @param PayrollRetentionGuardService $guard           Releases and marks one record.
	 * @param LoggerInterface              $logger          Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly PayrollRetentionGuardService $guard,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Release lapsed floor holds and mark expired records, or, with the
	 * switch off, count what would be released and marked.
	 *
	 * @param DateTimeImmutable $today The day the walk runs.
	 *
	 * @return array{enabled: bool, released: int, marked: int, wouldRelease: int, wouldMark: int}
	 *
	 * @spec openspec/changes/compliance-retention-expiry/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function run(DateTimeImmutable $today): array {
		$apply = $this->settingsService->isRetentionExpiryEnabled();
		$summary = ['enabled' => $apply, 'released' => 0, 'marked' => 0, 'wouldRelease' => 0, 'wouldMark' => 0];

		foreach (self::HOLD_SCHEMAS as $schema) {
			foreach ($this->loadAll($schema) as $object) {
				$result = $this->guard->releaseLapsedFloorHold($object, $schema, $today, $apply);
				$summary = $this->count($summary, $result['eligible'], $result['released'], 'released', 'wouldRelease');
			}
		}

		foreach ($this->loadAll('Employee') as $object) {
			$result = $this->guard->markEndedEmployee($object, $today, $apply);
			$summary = $this->count($summary, $result['eligible'], $result['marked'], 'marked', 'wouldMark');
		}

		return $summary;
	}//end run()

	/**
	 * Add one record's outcome to the summary.
	 *
	 * @param array<string, bool|int> $summary  The running summary.
	 * @param bool                    $eligible Whether the record had expired.
	 * @param bool                    $done     Whether it was written.
	 * @param string                  $doneKey  The counter for a write.
	 * @param string                  $wouldKey The counter for a dry run.
	 *
	 * @return array{enabled: bool, released: int, marked: int, wouldRelease: int, wouldMark: int}
	 */
	private function count(array $summary, bool $eligible, bool $done, string $doneKey, string $wouldKey): array {
		if ($eligible === false) {
			return $summary;
		}

		if ($summary['enabled'] === false) {
			$summary[$wouldKey]++;
			return $summary;
		}

		if ($done === true) {
			$summary[$doneKey]++;
		}

		return $summary;
	}//end count()

	/**
	 * Load every object of one schema as OpenRegister entities, without RBAC
	 * or multitenancy (a background job has no user).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, object>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT], false, false);
		} catch (\Throwable $e) {
			$this->logger->warning('RetentionExpiryService: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		return array_values(array_filter((is_array($rows) === true ? $rows : []), 'is_object'));
	}//end loadAll()

	/**
	 * @return mixed OpenRegister's ObjectService.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

}//end class
