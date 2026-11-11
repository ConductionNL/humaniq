<?php

/**
 * Humaniq Register Importer
 *
 * Loads the humaniq OpenRegister register from lib/Settings/humaniq_register.json,
 * deep-merging any modular schema fragments from lib/Settings/register.d/*.json
 * (ADR-037), and hands the merged configuration to OpenRegister's
 * ConfigurationService::importFromApp. The fragment-content signature is folded
 * into the version so OpenRegister re-imports whenever a fragment changes.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports the humaniq register into OpenRegister.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
final class RegisterImporter {

	/**
	 * The answer when OpenRegister skipped the import because the version did not change.
	 */
	private const UNCHANGED_IMPORT = [
		'success' => true,
		'skipped' => true,
		'message' => 'Configuration already up-to-date (version-unchanged skip).',
	];

	/**
	 * @param SettingsService $settingsService Tells whether OpenRegister is available.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger.
	 * @param InternalWriteMarker $marker Marks the register import as a shipped import.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly InternalWriteMarker $marker = new InternalWriteMarker(),
	) {

	}//end __construct()

	/**
	 * Import the register idempotently (skips when the version is unchanged).
	 *
	 * @return array<string, mixed> Result with success flag, message, and version.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	public function loadConfiguration(): array {
		return $this->runLoadConfiguration(force: false);
	}//end loadConfiguration()

	/**
	 * Force re-import of the register (bypasses the already-configured guard).
	 *
	 * @return array<string, mixed> Result with success flag, message, and version.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	public function loadConfigurationForced(): array {
		return $this->runLoadConfiguration(force: true);
	}//end loadConfigurationForced()

	/**
	 * Internal implementation for loadConfiguration / loadConfigurationForced.
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @return array<string, mixed>
	 */
	private function runLoadConfiguration(bool $force): array {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Humaniq: OpenRegister not available, skipping register initialization');
			return self::failedImport('OpenRegister is not installed or enabled.');
		}

		try {
			$configLoad = (new RegisterConfigReader($this->logger))->read();
			if (isset($configLoad['success']) === true && $configLoad['success'] === false) {
				return $configLoad;
			}

			return $this->importRegister(configLoad: $configLoad, force: $force);
		} catch (\Throwable $e) {
			$this->logger->error('Humaniq: configuration import failed', ['exception' => $e->getMessage()]);
			return self::failedImport($e->getMessage());
		}//end try

	}//end runLoadConfiguration()

	/**
	 * Hand the register configuration to OpenRegister's importFromApp.
	 *
	 * @param array<string, mixed> $configLoad The config and its version, from RegisterConfigReader::read().
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @return array<string, mixed>
	 */
	private function importRegister(array $configLoad, bool $force): array {
		$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
		$result = $this->marker->runShippedImport(
			static fn (): mixed => $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $configLoad['data'],
				version: $configLoad['version'],
				force: $force
			)
		);

		if (empty($result) === false) {
			return $this->importOutcome($result);
		}

		// OR's importFromApp returns an all-empty result on the version-unchanged
		// idempotent-skip path (force=false, same version). That is success, not
		// failure — the register is already up-to-date.
		$this->logger->info('Humaniq: register configuration already up-to-date (version-unchanged skip)');
		return self::UNCHANGED_IMPORT;

	}//end importRegister()

	/**
	 * The answer for an import that returned a result: failure on errors,
	 * success (with any warnings logged) otherwise.
	 *
	 * @param array<string, mixed> $result What importFromApp returned.
	 *
	 * @return array<string, mixed>
	 */
	private function importOutcome(array $result): array {
		if (empty($result['errors'] ?? []) === false) {
			return $this->importErrors($result);
		}

		$warnings = ($result['warnings'] ?? []);
		if (empty($warnings) === false) {
			$this->logger->warning('Humaniq: register configuration imported with warnings', ['warnings' => $warnings]);
		}

		$this->logger->info('Humaniq: register configuration imported successfully');
		return [
			'success' => true,
			'message' => 'Configuration imported successfully.',
			'warnings' => $warnings,
			'version' => ($result['version'] ?? 'unknown'),
		];

	}//end importOutcome()

	/**
	 * The answer for an import that reported errors.
	 *
	 * @param array<string, mixed> $result What importFromApp returned, with errors.
	 *
	 * @return array<string, mixed>
	 */
	private function importErrors(array $result): array {
		$this->logger->error('Humaniq: register configuration imported with errors', ['errors' => $result['errors']]);
		return [
			'success' => false,
			'message' => 'Configuration import completed with errors.',
			'errors' => $result['errors'],
			'warnings' => ($result['warnings'] ?? []),
			'version' => ($result['version'] ?? 'unknown'),
		];

	}//end importErrors()

	/**
	 * The answer for an import that could not run.
	 *
	 * @param string $message Why it could not run.
	 *
	 * @return array<string, mixed>
	 */
	private static function failedImport(string $message): array {
		return [
			'success' => false,
			'message' => $message,
		];

	}//end failedImport()

}//end class
