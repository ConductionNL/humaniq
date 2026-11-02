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

use OCA\Humaniq\Standards\RuleEngine;
use OCA\Humaniq\Support\RegisterSlugLookup;
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
		$run = new RuleTestDataRun(objectService: $this->objectService(), register: $register, admin: $admin, logger: $this->logger);

		$providerObjectsCreated = $this->seedSamples(run: $run);

		// Generic per-domain provider seeding: each CheckProvider may declare the
		// test-data field defaults its checks expect (RuleEngine::providerSeedSpecs).
		// Backfill any missing/empty field on the existing objects of that type.
		$providerFieldsAdded = 0;
		foreach (RuleEngine::providerSeedSpecs() as $objectType => $fields) {
			if ($fields === []) {
				continue;
			}

			$providerFieldsAdded += $run->backfillFields(objectType: $objectType, fields: $fields);
		}

		return [
			'providerObjectsCreated' => $providerObjectsCreated,
			'providerFieldsAdded' => $providerFieldsAdded,
			'alreadyCompliant' => $run->alreadyCompliant,
		];

	}//end seed()

	/**
	 * Create or upsert the provider sample objects of every object type.
	 *
	 * Provider object seeding: create compliant sample objects for new object
	 * types that have no rows yet, so the provider's checks actually evaluate
	 * (RuleEngine::providerSeedObjects, from the SeedsObjects capability).
	 *
	 * A type whose provider ALSO implements UpsertsObjects (cao-library) is
	 * upserted on its declared natural key instead: create when no row carries
	 * that key value, update the matching row in place otherwise, so re-seeding
	 * after a corpus edit converges the display objects rather than either
	 * duplicating them or skipping because the type is non-empty (REQ-CAO-006).
	 *
	 * @param RuleTestDataRun $run The register, acting user and counts of this run.
	 *
	 * @return int The number of objects written.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	private function seedSamples(RuleTestDataRun $run): int {
		$upsertKeys = RuleEngine::providerUpsertKeys();
		$written = 0;
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
			$written += $run->createMissingSamples(objectType: 'Employee', samples: $providerSeedObjects['Employee']);
			unset($providerSeedObjects['Employee']);
		}

		$employeeUuidsByNumber = $this->employeeIndex->byNumber($run->objectService, $run->register);

		foreach ($providerSeedObjects as $objectType => $samples) {
			if ($samples === []) {
				continue;
			}

			$samples = array_map(
				fn (array $sample): array => $this->employeeIndex->resolvePlaceholder($sample, $employeeUuidsByNumber),
				$samples
			);

			if (isset($upsertKeys[$objectType]) === true) {
				$written += $run->upsertSamples(objectType: $objectType, samples: $samples, keyField: $upsertKeys[$objectType]);
				continue;
			}

			$written += $run->createMissingSamples(objectType: $objectType, samples: $samples);
		}

		return $written;

	}//end seedSamples()


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
	 * The slug this instance's humaniq register answers to.
	 *
	 * This used to end `return $register === '' ? 'hrmq' : $register;` under a
	 * note saying the `hrmq` fallback was frozen across the rename — while the
	 * `getValueString()` default beside it already said `humaniq`. So the frozen
	 * branch was reachable only for a config value stored as the empty string,
	 * and every ordinary instance took the canonical default instead. On an
	 * instance that has not yet run `MigrateRegisterSlug` the register is still
	 * `hrmq`, and OpenRegister does not raise for a register that is not there.
	 * See ConductionNL/openregister#3579.
	 *
	 * This one WRITES. Returning a slug nothing answers to would send every
	 * seeded object at a register that is not there, and OpenRegister's import
	 * path creates rather than refuses — so a silent default here does not fail,
	 * it forks the data. It raises instead: `occ` prints the reason and exits
	 * non-zero, which is what an operator can act on.
	 *
	 * @return string The slug to use.
	 *
	 * @throws RuntimeException When this instance carries no humaniq register
	 *                          under any of its known slugs.
	 */
	private function register(): string {
		$slug = (new RegisterSlugLookup($this->container, $this->appConfig))->slugOrNull();
		if ($slug === null) {
			$message = 'Het humaniq-register is niet gevonden op deze instance. Voer de humaniq-reparatiestap '
				. 'uit of stel het register in bij de humaniq-instellingen.';
			$this->logger->error(static::class . ': ' . $message);
			throw new RuntimeException($message);
		}

		return $slug;
	}//end register()
}//end class
