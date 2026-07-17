<?php

/**
 * Hrmq Initialize Register Repair Step
 *
 * Post-migration repair step that imports the hrmq OpenRegister register on
 * install/upgrade. It delegates to SettingsService::loadConfiguration() (NOT the
 * forced variant — see the run() body), which reads lib/Settings/hrmq_register.json,
 * deep-merges the modular schema
 * fragments under lib/Settings/register.d/*.json, and hands the result to
 * OpenRegister. OpenRegister's per-register/per-schema version_compare provides
 * idempotency, so re-running on a routine upgrade is a no-op unless a schema or
 * fragment changed. Fails soft when OpenRegister is not installed.
 *
 * @category Repair
 * @package  OCA\Hrmq\Repair
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
 * @spec openspec/changes/hrm-rule-engine/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Repair;

use OCA\Hrmq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Imports the hrmq register via SettingsService on install/upgrade.
 */
class InitializeRegister implements IRepairStep
{

    /**
     * @param SettingsService $settingsService The register importer.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * The repair step name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Initialize hrmq register and schemas in OpenRegister';

    }//end getName()


    /**
     * Run the repair step: import the hrmq register.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Initializing hrmq register...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not installed or enabled. Skipping hrmq register import.');
            $this->logger->warning('Hrmq: OpenRegister not available, skipping register initialization');
            return;
        }

        try {
            // NOT forced. loadConfigurationForced() passes force:true, which bypasses
            // OpenRegister's app-level import fast-skip (gated on `$force === false`), so this
            // step re-parsed hrmq_register.json + the register.d fragments and walked every
            // register/schema on EVERY upgrade, even when nothing changed. Forcing was never
            // needed: the version passed to OR is content-addressed (`+frag.<md5 of the
            // fragments>`), so a content change already bumps the version and re-imports;
            // OpenRegister#426 additionally makes the gate content-aware.
            $result = $this->settingsService->loadConfiguration();

            if (($result['success'] ?? false) === true) {
                if (($result['skipped'] ?? false) === true) {
                    $output->info('Hrmq register already up-to-date (version-unchanged skip).');
                    return;
                }

                $output->info('Hrmq register imported successfully (version: '.($result['version'] ?? 'unknown').').');
                return;
            }

            $output->warning('Hrmq register import issue: '.($result['message'] ?? 'unknown error'));
        } catch (\Throwable $e) {
            $output->warning('Could not initialize hrmq register: '.$e->getMessage());
            $this->logger->error('Hrmq register initialization failed', ['exception' => $e->getMessage()]);
        }//end try

    }//end run()


}//end class
