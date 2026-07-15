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
 * 100%-compliant in `occ hrmq:rules:audit`.
 *
 * This is a TEST/DEV utility only: it writes with RBAC bypassed and as an admin
 * user to reach seeded objects' folders. No runtime path uses it; it exists so
 * the compliant-test-data state is reproducible rather than a one-off live edit.
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
 * @spec openspec/changes/hrm-rule-testdata-seed/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Standards\RuleEngine;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Makes the local test data compliant with the enforced rules (idempotent).
 */
class RuleTestDataSeeder
{
    /**
     * @param ContainerInterface $container    DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig    App config for the register slug.
     * @param IUserManager       $userManager  To resolve an admin user for updates.
     * @param IGroupManager      $groupManager To find an admin user.
     * @param LoggerInterface    $logger       Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Seed/backfill the local test data to satisfy the enforced HR/labour rules.
     *
     * @return array<string, int> Counts: providerObjectsCreated, providerFieldsAdded, alreadyCompliant.
     */
    public function seed(): array
    {
        $register = $this->register();
        $admin    = $this->adminUser();
        $os       = $this->objectService();

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
        $upsertKeys             = RuleEngine::providerUpsertKeys();
        $providerObjectsCreated = 0;
        foreach (RuleEngine::providerSeedObjects() as $objectType => $samples) {
            if ($samples === []) {
                continue;
            }

            if (isset($upsertKeys[$objectType]) === true) {
                $providerObjectsCreated += $this->upsertSamples($os, $register, $admin, $objectType, $samples, $upsertKeys[$objectType]);
                continue;
            }

            try {
                $existing = $os->setRegister($register)->setSchema($objectType)->findAll(['limit' => 1]);
            } catch (\Throwable $e) {
                $this->logger->warning('RuleTestDataSeeder: cannot probe '.$objectType.' for object seeding: '.$e->getMessage());
                continue;
            }

            if (is_array($existing) === true && $existing !== []) {
                $alreadyCompliant++;
                continue;
            }

            foreach ($samples as $sample) {
                try {
                    $os->saveObject(object: $sample, register: $register, schema: $objectType, _rbac: false, _multitenancy: false, currentUser: $admin);
                    $providerObjectsCreated++;
                } catch (\Throwable $e) {
                    $this->logger->warning('RuleTestDataSeeder: sample '.$objectType.' create failed: '.$e->getMessage());
                }
            }
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
                $this->logger->warning('RuleTestDataSeeder: cannot load '.$objectType.' for provider seeding: '.$e->getMessage());
                continue;
            }

            foreach ($rows as $row) {
                $obj     = is_array($row) === true ? $row : $row->jsonSerialize();
                $rowId   = (string) ($obj['id'] ?? $obj['@self']['id'] ?? '');
                $changed = false;
                foreach ($fields as $field => $default) {
                    if (array_key_exists($field, $obj) === false || trim((string) ($obj[$field] ?? '')) === '') {
                        $obj[$field] = $default;
                        $changed     = true;
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
                    $this->logger->warning('RuleTestDataSeeder: provider field backfill failed for '.$objectType.' '.$rowId.': '.$e->getMessage());
                }
            }//end foreach
        }//end foreach

        return [
            'providerObjectsCreated' => $providerObjectsCreated,
            'providerFieldsAdded'    => $providerFieldsAdded,
            'alreadyCompliant'       => $alreadyCompliant,
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
     * @param mixed                                   $os         The ObjectService.
     * @param string                                  $register   Register slug.
     * @param IUser|null                              $admin      Admin user for the write.
     * @param string                                  $objectType Schema name.
     * @param array<int, array<string, mixed>>        $samples    The provider's samples.
     * @param string                                  $keyField   The natural-key field name.
     *
     * @return int
     */
    private function upsertSamples(mixed $os, string $register, ?IUser $admin, string $objectType, array $samples, string $keyField): int
    {
        try {
            $rows = $os->setRegister($register)->setSchema($objectType)->findAll(['limit' => 10000]);
        } catch (\Throwable $e) {
            $this->logger->warning('RuleTestDataSeeder: cannot load '.$objectType.' for upsert seeding: '.$e->getMessage());
            return 0;
        }

        $idByKey = [];
        foreach ((is_array($rows) === true ? $rows : []) as $row) {
            $obj      = is_array($row) === true ? $row : $row->jsonSerialize();
            $keyValue = trim((string) ($obj[$keyField] ?? ''));
            if ($keyValue === '') {
                continue;
            }

            $idByKey[$keyValue] = (string) ($obj['id'] ?? $obj['@self']['id'] ?? '');
        }

        $written = 0;
        foreach ($samples as $sample) {
            $keyValue = trim((string) ($sample[$keyField] ?? ''));
            if ($keyValue === '') {
                continue;
            }

            $existingId = ($idByKey[$keyValue] ?? '');
            try {
                if ($existingId !== '') {
                    $os->saveObject(object: $sample, register: $register, schema: $objectType, uuid: $existingId, _rbac: false, _multitenancy: false, currentUser: $admin);
                } else {
                    $os->saveObject(object: $sample, register: $register, schema: $objectType, _rbac: false, _multitenancy: false, currentUser: $admin);
                }

                $written++;
            } catch (\Throwable $e) {
                $this->logger->warning('RuleTestDataSeeder: upsert '.$objectType.' '.$keyValue.' failed: '.$e->getMessage());
            }
        }//end foreach

        return $written;

    }//end upsertSamples()


    /**
     * Resolve an admin user (needed to update seeded objects' folders), or null.
     *
     * @return IUser|null
     */
    private function adminUser(): ?IUser
    {
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
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * @return string The configured register slug.
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'hrmq');
        return $register === '' ? 'hrmq' : $register;

    }//end register()
}//end class
