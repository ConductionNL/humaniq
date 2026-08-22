<?php

/**
 * Rule Test-Data Seeder
 *
 * Idempotently backfills the local TEST data so it satisfies the enforced
 * machine-checkable HR/labour rules. For every object type whose CheckProvider
 * supplies sample objects it creates them when the type is empty
 * (providerSeedObjects), and for every type whose provider declares test-data
 * field defaults it backfills any missing/empty field on the existing rows
 * (providerSeedSpecs). Run after a clean-env reset so a fresh environment starts
 * 100%-compliant in `occ humaniq:rules:audit`.
 *
 * This is a TEST/DEV utility only: it writes with RBAC bypassed and as an admin
 * user to reach seeded objects' folders. No runtime path uses it; it exists so
 * the compliant-test-data state is reproducible rather than a one-off live edit.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Standards\RuleEngine;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Makes the local test data compliant with the enforced rules (idempotent).
 */
class RuleTestDataSeeder {
	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IUserManager $userManager To resolve an admin user for updates.
	 * @param IGroupManager $groupManager To find an admin user.
	 * @param LoggerInterface $logger Logger.
	 * @param RuleTestDataEmployeeIndex $employeeIndex employeeNumber => uuid resolution for seed samples.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly RuleTestDataEmployeeIndex $employeeIndex,
	) {

	}//end __construct()

	/**
	 * Seed/backfill the local test data to satisfy the enforced HR/labour rules.
	 *
	 * @return array<string, int> Counts: providerObjectsCreated, providerFieldsAdded, alreadyCompliant.
	 *
	 * The exclusion that used to sit here said "no spec target exists for this
	 * seeder", and it was true: the change was archived without its spec ever
	 * being promoted to openspec/specs/, so fifteen anchors across this app
	 * pointed at nothing. That is now fixed at the TARGET — the spec exists —
	 * so this is a real anchor rather than a reasoned skip.
	 *
	 * A skip whose reason has quietly stopped being true is worse than no skip:
	 * it reads as a considered decision and is an unnoticed hole.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function seed(): array {
		$register = $this->register();
		$admin = $this->adminUser();
		$os = $this->objectService();

		$alreadyCompliant = 0;

		// Provider object seeding: create compliant sample objects for new object
		// types that have no rows yet, so the provider's checks actually evaluate
		// (RuleEngine::providerSeedObjects, from the SeedsObjects capability).
		//
		// A type whose provider ALSO implements UpsertsObjects (cao-library) is
		// upserted on its declared natural key instead: create when no row
		// carries that key value, update the matching row in place otherwise, so
		// re-seeding after a corpus edit converges the display objects rather
		// than either duplicating them or skipping because the type is non-empty
		// (REQ-CAO-006).
		$upsertKeys = RuleEngine::providerUpsertKeys();
		$providerObjectsCreated = 0;
		$providerSeedObjects = RuleEngine::providerSeedObjects();

		// 'Employee' is created FIRST, out of provider-declaration order: several
		// other providers' samples (NlPayrollChecks' EmploymentContract/Payslip,
		// NlWageGarnishmentChecks' Loonbeslag, ...) reference an employee via a
		// synthetic `employeeNumber`-shaped `employeeId` placeholder (e.g.
		// 'EMP-NL-0001') -- but the Employee schema types `employeeId` as
		// `format: 'uuid'` on every referencing schema, so writing that literal
		// placeholder always fails create. Resolving 'Employee' first means the
		// real employee row (and its generated UUID) exists before
		// RuleTestDataEmployeeIndex below tries to substitute it into the
		// samples that reference it.
		if (isset($providerSeedObjects['Employee']) === true) {
			$providerObjectsCreated += $this->createMissingSamples($os, $register, $admin, 'Employee', $providerSeedObjects['Employee'], $alreadyCompliant);
			unset($providerSeedObjects['Employee']);
		}

		$employeeUuidsByNumber = $this->employeeIndex->byNumber($os, $register);

		foreach ($providerSeedObjects as $objectType => $samples) {
			if ($samples === []) {
				continue;
			}

			$samples = array_map(
				fn (array $sample): array => $this->employeeIndex->resolvePlaceholder($sample, $employeeUuidsByNumber),
				$samples
			);

			if (isset($upsertKeys[$objectType]) === true) {
				$providerObjectsCreated += $this->upsertSamples($os, $register, $admin, $objectType, $samples, $upsertKeys[$objectType]);
				continue;
			}

			$providerObjectsCreated += $this->createMissingSamples($os, $register, $admin, $objectType, $samples, $alreadyCompliant);
		}//end foreach

		// Generic per-domain provider seeding: each CheckProvider may declare the
		// test-data field defaults its checks expect (RuleEngine::providerSeedSpecs).
		// Backfill any missing/empty field on the existing objects of that type.
		$providerFieldsAdded = 0;
		foreach (RuleEngine::providerSeedSpecs() as $objectType => $fields) {
			if ($fields === []) {
				continue;
			}

			try {
				$rows = $os->setRegister($register)->setSchema($objectType)->findAll(['limit' => 10000]);
			} catch (\Throwable $e) {
				$this->logger->warning('RuleTestDataSeeder: cannot load ' . $objectType . ' for provider seeding: ' . $e->getMessage());
				continue;
			}

			foreach ($rows as $row) {
				$obj = is_array($row) === true ? $row : $row->jsonSerialize();
				$rowId = (string)($obj['id'] ?? $obj['@self']['id'] ?? '');
				$changed = false;
				foreach ($fields as $field => $default) {
					if (array_key_exists($field, $obj) === false || trim((string)($obj[$field] ?? '')) === '') {
						$obj[$field] = $default;
						$changed = true;
					}
				}

				if ($changed === false) {
					$alreadyCompliant++;
					continue;
				}

				unset($obj['@self']);
				try {
					$os->saveObject(object: $obj, register: $register, schema: $objectType, uuid: $rowId, _rbac: false, _multitenancy: false, currentUser: $admin);
					$providerFieldsAdded++;
				} catch (\Throwable $e) {
					$this->logger->warning('RuleTestDataSeeder: provider field backfill failed for ' . $objectType . ' ' . $rowId . ': ' . $e->getMessage());
				}
			}//end foreach
		}//end foreach

		return [
			'providerObjectsCreated' => $providerObjectsCreated,
			'providerFieldsAdded' => $providerFieldsAdded,
			'alreadyCompliant' => $alreadyCompliant,
		];

	}//end seed()

	/**
	 * Upsert provider samples on a natural key (cao-library, REQ-CAO-006):
	 * for each sample, find the existing row whose `$keyField` equals the
	 * sample's key value and update it in place (preserving its object id);
	 * create a new object when none matches. Idempotent: re-running creates no
	 * duplicate and converges each row's fields to the sample. Returns the
	 * number of objects written (created or updated).
	 *
	 * @param mixed $os The ObjectService.
	 * @param string $register Register slug.
	 * @param IUser|null $admin Admin user for the write.
	 * @param string $objectType Schema name.
	 * @param array<int, array<string, mixed>> $samples The provider's samples.
	 * @param string $keyField The natural-key field name.
	 *
	 * @return int
	 */
	private function upsertSamples(mixed $os, string $register, ?IUser $admin, string $objectType, array $samples, string $keyField): int {
		try {
			$rows = $os->setRegister($register)->setSchema($objectType)->findAll(['limit' => 10000]);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleTestDataSeeder: cannot load ' . $objectType . ' for upsert seeding: ' . $e->getMessage());
			return 0;
		}

		$idByKey = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$obj = is_array($row) === true ? $row : $row->jsonSerialize();
			$keyValue = trim((string)($obj[$keyField] ?? ''));
			if ($keyValue === '') {
				continue;
			}

			$idByKey[$keyValue] = (string)($obj['id'] ?? $obj['@self']['id'] ?? '');
		}

		$written = 0;
		foreach ($samples as $sample) {
			$keyValue = trim((string)($sample[$keyField] ?? ''));
			if ($keyValue === '') {
				continue;
			}

			$existingId = ($idByKey[$keyValue] ?? '');
			try {
				$this->writeSample($os, $register, $admin, $objectType, $sample, $existingId);
				$written++;
			} catch (\Throwable $e) {
				$this->logger->warning('RuleTestDataSeeder: upsert ' . $objectType . ' ' . $keyValue . ' failed: ' . $e->getMessage());
			}
		}//end foreach

		return $written;
	}//end upsertSamples()

	/**
	 * Write one sample, updating in place when a matching object already exists.
	 *
	 * Extracted from {@see self::upsertSamples()}: the update and create calls
	 * differ only in whether `uuid:` is supplied, so an early return expresses
	 * the choice without an else branch.
	 *
	 * @param mixed $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param IUser|null $admin Admin user for the write.
	 * @param string $objectType Schema name.
	 * @param array<string, mixed> $sample The sample to write.
	 * @param string $existingId Existing object id, or '' to create.
	 *
	 * @return void
	 */
	private function writeSample(mixed $objectService, string $register, ?IUser $admin, string $objectType, array $sample, string $existingId): void {
		if ($existingId !== '') {
			$objectService->saveObject(object: $sample, register: $register, schema: $objectType, uuid: $existingId, _rbac: false, _multitenancy: false, currentUser: $admin);
			return;
		}

		$objectService->saveObject(object: $sample, register: $register, schema: $objectType, _rbac: false, _multitenancy: false, currentUser: $admin);

	}//end writeSample()

	/**
	 * Create the provider's sample objects for one object type when the type
	 * currently has no rows (create-once-when-empty, the default SeedsObjects
	 * behaviour for providers not also implementing UpsertsObjects).
	 *
	 * @param mixed $os The ObjectService.
	 * @param string $register Register slug.
	 * @param IUser|null $admin Admin user for the write.
	 * @param string $objectType Schema name.
	 * @param array<int, array<string, mixed>> $samples The provider's samples.
	 * @param int $alreadyCompliant Incremented (by reference) when the type already has rows.
	 *
	 * @return int The number of objects created.
	 */
	private function createMissingSamples(mixed $os, string $register, ?IUser $admin, string $objectType, array $samples, int &$alreadyCompliant): int {
		try {
			$existing = $os->setRegister($register)->setSchema($objectType)->findAll(['limit' => 1]);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleTestDataSeeder: cannot probe ' . $objectType . ' for object seeding: ' . $e->getMessage());
			return 0;
		}

		if (is_array($existing) === true && $existing !== []) {
			$alreadyCompliant++;
			return 0;
		}

		$created = 0;
		foreach ($samples as $sample) {
			try {
				$os->saveObject(object: $sample, register: $register, schema: $objectType, _rbac: false, _multitenancy: false, currentUser: $admin);
				$created++;
			} catch (\Throwable $e) {
				$this->logger->warning('RuleTestDataSeeder: sample ' . $objectType . ' create failed: ' . $e->getMessage());
			}
		}

		return $created;
	}//end createMissingSamples()

	/**
	 * Resolve an admin user (needed to update seeded objects' folders), or null.
	 *
	 * @return IUser|null
	 */
	private function adminUser(): ?IUser {
		$admins = $this->groupManager->get('admin');
		if ($admins !== null) {
			foreach ($admins->getUsers() as $user) {
				return $user;
			}
		}

		return $this->userManager->get('admin');
	}//end adminUser()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. class_exists() rather
		// than SettingsService::isOpenRegisterAvailable(), because this class
		// does not inject SettingsService and adding a constructor dependency
		// purely to ask a yes/no question is the wrong trade. It answers the
		// same question the container would otherwise have answered fatally.
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured register slug.
	 *
	 * The 'hrmq' fallback is FROZEN across the Humaniq rename: OpenRegister's
	 * ImportHandler resolves the register BY SLUG. Renaming it would create a
	 * second, empty register and orphan every employee, contract, payslip and
	 * payroll run already stored under the 'hrmq' slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'hrmq');
		return $register === '' ? 'hrmq' : $register;
	}//end register()
}//end class
