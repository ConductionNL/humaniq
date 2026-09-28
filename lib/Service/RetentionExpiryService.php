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
 * @spec openspec/specs/personnel-retention-expiry/spec.md
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
	 * The appraisal OpenRegister's destruction check reads as destroy
	 * (`Appraisal::DESTROY_ALIASES`).
	 *
	 * @var string
	 */
	private const DESTROY_APPRAISAL = 'vernietigen';

	/**
	 * Upper bound per schema per run, the fleet's findAll convention.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface           $container       DI container for OpenRegister's ObjectService.
	 * @param SettingsService              $settingsService Register slug and the admin switch.
	 * @param LoggerInterface              $logger          Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
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
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function run(DateTimeImmutable $today): array {
		$apply = $this->settingsService->isRetentionExpiryEnabled();
		$summary = ['enabled' => $apply, 'released' => 0, 'marked' => 0, 'wouldRelease' => 0, 'wouldMark' => 0];

		foreach (self::HOLD_SCHEMAS as $schema) {
			foreach ($this->loadAll($schema) as $object) {
				$result = $this->releaseLapsedFloorHold($object, $schema, $today, $apply);
				$summary = $this->count($summary, $result['eligible'], $result['released'], 'released', 'wouldRelease');
			}
		}

		foreach ($this->loadAll('Employee') as $object) {
			$result = $this->markEndedEmployee($object, $today, $apply);
			$summary = $this->count($summary, $result['eligible'], $result['marked'], 'marked', 'wouldMark');
		}

		return $summary;
	}//end run()

	/**
	 * Release a humaniq statutory floor hold whose floor date has passed, and
	 * mark the record for OpenRegister's destruction list with that date
	 * (compliance-retention-expiry D2, D3). Only a hold whose reason starts
	 * with `HOLD_REASON_MARKER` and names a `tot YYYY-MM-DD` floor before
	 * `$today` is released; any other hold (placed by a person, or inherited
	 * by a generated document) is left alone and the record is not marked.
	 * With `$apply` false nothing is changed and the result says what would
	 * have happened (the default-off switch, D4).
	 *
	 * @param mixed             $object The OpenRegister ObjectEntity.
	 * @param string            $schema The schema name (for the warning log message only).
	 * @param DateTimeImmutable $today  The day the job runs.
	 * @param bool              $apply  Whether to write, or only report.
	 *
	 * @return array{eligible: bool, released: bool, floor: string|null}
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function releaseLapsedFloorHold(mixed $object, string $schema, DateTimeImmutable $today, bool $apply): array {
		$none = ['eligible' => false, 'released' => false, 'floor' => null];
		if (is_object($object) === false) {
			return $none;
		}

		$floor = $this->lapsedHumaniqFloor($object, $today);
		if ($floor === null) {
			return $none;
		}

		if ($apply === false) {
			return ['eligible' => true, 'released' => false, 'floor' => $floor];
		}

		$released = $this->retentionService()->releaseLegalHold(
			object: $object,
			reason: 'Statutaire bewaartermijn verstreken op ' . $floor . ' (compliance-retention-expiry).'
		);
		$this->markForDestruction($released, $floor);

		return ['eligible' => true, 'released' => $this->persist(held: $released, schema: $schema), 'floor' => $floor];
	}//end releaseLapsedFloorHold()

	/**
	 * Mark an ended employee's record for OpenRegister's destruction list once
	 * 31 December of (end year + 7) has passed (compliance-retention-expiry
	 * D2). A record under an active legal hold, one without an `endDate`, or
	 * one that already carries an appraisal is left alone.
	 *
	 * @param mixed             $object The OpenRegister ObjectEntity of an Employee.
	 * @param DateTimeImmutable $today  The day the job runs.
	 * @param bool              $apply  Whether to write, or only report.
	 *
	 * @return array{eligible: bool, marked: bool, floor: string|null}
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-001
	 */
	public function markEndedEmployee(mixed $object, DateTimeImmutable $today, bool $apply): array {
		$none = ['eligible' => false, 'marked' => false, 'floor' => null];
		if (is_object($object) === false) {
			return $none;
		}

		$endYear = $this->endYear($object);
		if ($endYear === null) {
			return $none;
		}

		$floor = sprintf('%04d-12-31', ($endYear + PayrollRetentionGuardService::AWR_RETENTION_YEARS));
		if ($floor >= $today->format('Y-m-d')
			|| $this->hasAppraisal($object) === true
			|| $this->retentionService()->hasActiveLegalHold(object: $object) === true
		) {
			return $none;
		}

		if ($apply === false) {
			return ['eligible' => true, 'marked' => false, 'floor' => $floor];
		}

		$this->markForDestruction($object, $floor);

		return ['eligible' => true, 'marked' => $this->persist(held: $object, schema: 'Employee'), 'floor' => $floor];
	}//end markEndedEmployee()

	/**
	 * The floor date of an active humaniq statutory hold on `$object` when it
	 * lies before `$today`, else null.
	 *
	 * @param mixed             $object The OpenRegister ObjectEntity.
	 * @param DateTimeImmutable $today  The day the job runs.
	 *
	 * @return string|null
	 */
	private function lapsedHumaniqFloor(mixed $object, DateTimeImmutable $today): ?string {
		try {
			$retention = ($object->getRetention() ?? []);
		} catch (\Throwable $e) {
			return null;
		}

		$hold = ($retention['legalHold'] ?? []);
		if (is_array($hold) === false || ($hold['active'] ?? false) !== true) {
			return null;
		}

		$reason = (string)($hold['reason'] ?? '');
		if (str_starts_with($reason, PayrollRetentionGuardService::HOLD_REASON_MARKER) === false
			|| preg_match('/tot (\d{4}-\d{2}-\d{2})/', $reason, $matches) !== 1
		) {
			return null;
		}

		if ($matches[1] >= $today->format('Y-m-d')) {
			return null;
		}

		return $matches[1];
	}//end lapsedHumaniqFloor()

	/**
	 * Whether the record's retention block already carries an appraisal.
	 *
	 * @param mixed $object The OpenRegister ObjectEntity.
	 *
	 * @return bool
	 */
	private function hasAppraisal(mixed $object): bool {
		try {
			$retention = ($object->getRetention() ?? []);
		} catch (\Throwable $e) {
			return true;
		}

		return trim((string)($retention['archiefnominatie'] ?? '')) !== '';
	}//end hasAppraisal()

	/**
	 * Write the appraisal `vernietigen` and `$floor` as action date into the
	 * record's retention block, the two fields OpenRegister's destruction
	 * check reads, unless an appraisal is already recorded (it wins).
	 *
	 * @param mixed  $object The OpenRegister ObjectEntity.
	 * @param string $floor  The floor date, YYYY-MM-DD.
	 *
	 * @return void
	 */
	private function markForDestruction(mixed $object, string $floor): void {
		if ($this->hasAppraisal($object) === true) {
			return;
		}

		$retention = ($object->getRetention() ?? []);
		$retention['archiefnominatie'] = self::DESTROY_APPRAISAL;
		$retention['archiefactiedatum'] = $floor;
		$object->setRetention($retention);
	}//end markForDestruction()

	/**
	 * The year an employee's employment ended, from `endDate`.
	 *
	 * @param mixed $object The OpenRegister ObjectEntity of an Employee.
	 *
	 * @return int|null
	 */
	private function endYear(mixed $object): ?int {
		try {
			$payload = ($object->getObject() ?? []);
		} catch (\Throwable $e) {
			return null;
		}

		if (preg_match('/^(\d{4})-/', (string)($payload['endDate'] ?? ''), $matches) !== 1) {
			return null;
		}

		return (int)$matches[1];
	}//end endYear()

	/**
	 * Persist a mutated entity via `MagicMapper::update()`, the call that keeps
	 * entity-level columns such as `retention` (see
	 * `PayrollRetentionGuardService`'s Persistence gotcha).
	 *
	 * @param mixed  $held   The mutated entity.
	 * @param string $schema The schema name (for the warning log message only).
	 *
	 * @return bool Whether the persist succeeded.
	 */
	private function persist(mixed $held, string $schema): bool {
		try {
			$this->container->get('OCA\OpenRegister\Db\MagicMapper')->update($held);
		} catch (\Throwable $e) {
			$this->logger->warning('RetentionExpiryService: kon ' . $schema . ' niet opslaan: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end persist()

	/**
	 * @return mixed OpenRegister's RetentionService.
	 */
	private function retentionService(): mixed {
		return $this->container->get('OCA\OpenRegister\Service\RetentionService');
	}//end retentionService()

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
