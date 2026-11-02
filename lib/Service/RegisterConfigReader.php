<?php

/**
 * Humaniq Register Config Reader
 *
 * Reads the humaniq OpenRegister register from lib/Settings/humaniq_register.json
 * and deep-merges the modular schema fragments from lib/Settings/register.d/*.json
 * (ADR-037). The fragment-content signature is folded into the version so
 * OpenRegister re-imports whenever a fragment changes.
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

use Psr\Log\LoggerInterface;

/**
 * Reads the register configuration and merges its fragments.
 */
class RegisterConfigReader {

	private const SETTINGS = __DIR__ . '/../Settings';

	/**
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Load and parse humaniq_register.json, deep-merging register.d/*.json fragments.
	 *
	 * @return array<string, mixed> Either ['data' => array, 'version' => string]
	 *                              or ['success' => false, 'message' => string].
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	public function read(): array {
		$base = $this->readBase();
		if (array_key_exists('data', $base) === false) {
			return $base;
		}

		[$configData, $fragmentSig] = $this->mergeFragments($base['data']);

		// Fold the fragment signature into the version so OpenRegister's
		// version-gated importFromApp re-imports whenever fragments change.
		$version = ($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$version .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		return [
			'data' => $configData,
			'version' => $version,
		];

	}//end read()

	/**
	 * Read humaniq_register.json.
	 *
	 * @return array<string, mixed> Either ['data' => mixed] or ['success' => false, 'message' => string].
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private function readBase(): array {
		$configPath = self::SETTINGS . '/humaniq_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error('Humaniq: humaniq_register.json not found at ' . $configPath);
			return self::failure('Configuration file humaniq_register.json not found.');
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			$this->logger->error('Humaniq: failed to read humaniq_register.json');
			return self::failure('Failed to read configuration file.');
		}

		return $this->decodeBase($configContent);

	}//end readBase()

	/**
	 * Parse the content of humaniq_register.json.
	 *
	 * @param string $configContent The file content.
	 *
	 * @return array<string, mixed> Either ['data' => mixed] or ['success' => false, 'message' => string].
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private function decodeBase(string $configContent): array {
		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Humaniq: failed to parse humaniq_register.json: ' . json_last_error_msg());
			return self::failure('Failed to parse configuration file: ' . json_last_error_msg());
		}

		return ['data' => $configData];

	}//end decodeBase()

	/**
	 * A failed read.
	 *
	 * @param string $message What went wrong.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function failure(string $message): array {
		return [
			'success' => false,
			'message' => $message,
		];

	}//end failure()

	/**
	 * Merge the register.d/*.json fragments onto the base config (ADR-037).
	 *
	 * Each domain CheckProvider drops its own schema fragment there instead of
	 * editing the monolith, so concurrent builds touch disjoint files. OpenAPI
	 * `components.schemas` is a keyed object, so disjoint fragments union by key.
	 *
	 * @param mixed $configData The base config.
	 *
	 * @return array{0: mixed, 1: string} The merged config and the fragment signature.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private function mergeFragments(mixed $configData): array {
		$fragmentDir = self::SETTINGS . '/register.d';
		$fragmentSig = '';
		if (is_dir($fragmentDir) === false) {
			return [$configData, $fragmentSig];
		}

		$fragmentFiles = (glob($fragmentDir . '/*.json') ?: []);
		sort($fragmentFiles);
		foreach ($fragmentFiles as $fragmentFile) {
			$fragment = $this->readFragment($fragmentFile);
			if ($fragment === null) {
				continue;
			}

			$configData = self::deepMergeConfig(base: $configData, overlay: $fragment['data']);
			$fragmentSig .= basename($fragmentFile) . ':' . md5($fragment['content']) . ';';
		}

		return [$configData, $fragmentSig];

	}//end mergeFragments()

	/**
	 * Read and parse one fragment; null when it cannot be read or is malformed.
	 *
	 * @param string $fragmentFile The fragment's path.
	 *
	 * @return array{data: mixed, content: string}|null
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private function readFragment(string $fragmentFile): ?array {
		$fragmentContent = file_get_contents($fragmentFile);
		if ($fragmentContent === false) {
			return null;
		}

		$fragmentData = json_decode($fragmentContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->warning(
				'Humaniq: skipping malformed register fragment ' . basename($fragmentFile)
				. ': ' . json_last_error_msg()
			);
			return null;
		}

		return [
			'data' => $fragmentData,
			'content' => $fragmentContent,
		];

	}//end readFragment()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`) are merged by
	 * key union (recursing on shared keys); list arrays are concatenated; scalars
	 * in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$base[$key] = self::mergeConfigValue(($base[$key] ?? null), $value);
		}

		return $base;

	}//end deepMergeConfig()

	/**
	 * Merge a single overlay value onto its base counterpart (ADR-037).
	 *
	 * Only when BOTH sides are arrays does anything other than a plain
	 * overwrite happen: two lists concatenate, anything else merges by key.
	 *
	 * @param mixed $baseValue The current value in the base config, or null when absent.
	 * @param mixed $value The overlay value to merge in.
	 *
	 * @return mixed The merged value for this key.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function mergeConfigValue(mixed $baseValue, mixed $value): mixed {
		if (is_array($value) === false || is_array($baseValue) === false) {
			return $value;
		}

		if (self::isList($baseValue) === true && self::isList($value) === true) {
			return array_merge($baseValue, $value);
		}

		return self::deepMergeConfig(base: $baseValue, overlay: $value);

	}//end mergeConfigValue()

	/**
	 * Whether an array is a list: empty, or keyed 0..n-1 in order.
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function isList(array $value): bool {
		return ($value === [] || array_keys($value) === range(0, (count($value) - 1)));

	}//end isList()

}//end class
