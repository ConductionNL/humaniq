<?php

/**
 * Golden test for the register import of RegisterImporter.
 *
 * Runs loadConfiguration() and loadConfigurationForced() over every answer
 * OpenRegister's importFromApp can give (errors, warnings, a clean import,
 * the empty version-unchanged result, an exception) and with OpenRegister
 * missing, and merges register fragments of every shape (lists, keyed
 * objects, scalars, empty arrays, nested values). Every answer, log line and
 * import call is compared with
 * tests/fixtures/golden/register-config-load.json.
 * Set RECORD_GOLDEN=1 to rewrite that file.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RegisterConfigReader;
use OCA\Humaniq\Service\RegisterImporter;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden cases for the register import and the fragment merge.
 *
 * @covers \OCA\Humaniq\Service\RegisterImporter
 * @covers \OCA\Humaniq\Service\RegisterConfigReader
 * @uses   \OCA\Humaniq\Service\SettingsService
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\OpenRegisterAvailability
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
class RegisterConfigLoadGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/register-config-load.json';

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = array_merge(array_keys(self::imports()), array_keys(self::merges()));
		return array_combine($names, array_map(static fn (string $n): array => [$n], $names));

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $case The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $case): void {
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$imports = self::imports();
		$result = isset($imports[$case]) === true ? $this->import($imports[$case]) : $this->merge(self::merges()[$case]);
		$actual = json_encode($result, $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode((string)$actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * The import scenarios: whether OpenRegister is installed, which entry
	 * point runs, and what importFromApp answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function imports(): array {
		$errors = ['schema x: bad type'];
		$warnings = ['schema y: deprecated'];
		return [
			'import-openregister-missing' => ['installed' => false, 'result' => []],
			'import-errors-with-version' => ['result' => ['errors' => $errors, 'warnings' => $warnings, 'version' => '1.2.3']],
			'import-errors-without-version' => ['result' => ['errors' => $errors]],
			'import-warnings' => ['result' => ['warnings' => $warnings, 'version' => '1.2.3']],
			'import-clean' => ['result' => ['version' => '1.2.3']],
			'import-clean-empty-lists' => ['result' => ['errors' => [], 'warnings' => []]],
			'import-unchanged-skip' => ['result' => []],
			'import-throws' => ['throw' => 'OpenRegister is down'],
			'import-forced' => ['forced' => true, 'result' => ['version' => '2.0.0']],
		];

	}//end imports()

	/**
	 * The merge scenarios: a base config and a fragment laid over it.
	 *
	 * @return array<string, array{0: array<mixed>, 1: array<mixed>}>
	 */
	private static function merges(): array {
		return [
			'merge-scalar-overwrites' => [['a' => 1, 'b' => 'x'], ['a' => 2]],
			'merge-new-key-added' => [['a' => 1], ['b' => 2]],
			'merge-lists-concatenate' => [['l' => [1, 2]], ['l' => [3]]],
			'merge-empty-list-takes-object' => [['l' => []], ['l' => ['k' => 1]]],
			'merge-objects-recurse' => [['o' => ['x' => 1, 'y' => ['p' => 1]]], ['o' => ['y' => ['q' => 2], 'z' => 3]]],
			'merge-array-over-scalar' => [['a' => 1], ['a' => [1]]],
			'merge-scalar-over-array' => [['a' => [1]], ['a' => 's']],
			'merge-array-over-null' => [['a' => null], ['a' => ['k' => 1]]],
			'merge-list-and-object' => [['l' => [1, 2]], ['l' => ['k' => 3]]],
			'merge-gapped-int-keys' => [['l' => [1 => 'a']], ['l' => [0 => 'b']]],
			'merge-empty-over-object' => [['o' => ['k' => 1]], ['o' => []]],
			'merge-both-empty' => [['e' => []], ['e' => []]],
			'merge-nested-lists' => [['c' => ['objects' => [['id' => 1]], 'schemas' => ['A' => ['type' => 'object']]]], ['c' => ['objects' => [['id' => 2]], 'schemas' => ['B' => []]]]],
			'merge-top-level-lists' => [[1, 2], [3]],
			'merge-null-overlay' => [['a' => ['k' => 1]], ['a' => null]],
		];

	}//end merges()

	/**
	 * Run one import scenario and describe what happened.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function import(array $s): array {
		$logger = new class extends AbstractLogger {
			/**
			 * @var array<int, array<int, mixed>>
			 */
			public array $lines = [];

			/**
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};
		$importer = new class($s) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $s The scenario.
			 */
			public function __construct(private array $s) {
			}

			/**
			 * @param string $appId The app id.
			 * @param array<mixed> $data The register config.
			 * @param string $version The version.
			 * @param bool $force Whether the import is forced.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->calls[] = ['appId' => $appId, 'force' => $force, 'version' => RegisterConfigLoadGoldenTest::versionShape($version), 'data' => RegisterConfigLoadGoldenTest::dataShape($data)];
				if (isset($this->s['throw']) === true) {
					throw new RuntimeException($this->s['throw']);
				}

				return $this->s['result'];
			}
		};

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'openregister' && ($s['installed'] ?? true));
		$container = $this->createMock(ContainerInterface::class);
		$asked = [];
		$container->method('get')->willReturnCallback(static function (string $id) use ($importer, &$asked) {
			$asked[] = $id;
			return $importer;
		});

		$service = new RegisterImporter(new SettingsService($this->createMock(IAppConfig::class), $appManager), $container, $logger);
		$answer = (($s['forced'] ?? false) === true) ? $service->loadConfigurationForced() : $service->loadConfiguration();

		return ['answer' => $answer, 'log' => $logger->lines, 'asked' => $asked, 'calls' => $importer->calls];

	}//end import()

	/**
	 * Merge one fragment onto a base config.
	 *
	 * @param array{0: array<mixed>, 1: array<mixed>} $s The base and the fragment.
	 *
	 * @return array<mixed>
	 */
	private function merge(array $s): array {
		$merge = new ReflectionMethod(RegisterConfigReader::class, 'deepMergeConfig');
		return ['merged' => $merge->invoke(null, $s[0], $s[1])];

	}//end merge()

	/**
	 * Whether the version is the register's own version plus a fragment signature.
	 *
	 * @param string $version The version handed to importFromApp.
	 *
	 * @return array<string, mixed>
	 */
	public static function versionShape(string $version): array {
		$base = json_decode((string)file_get_contents(self::SETTINGS . '/humaniq_register.json'), true);
		$prefix = preg_quote((string)($base['info']['version'] ?? '0.0.0'), '/');
		return [
			'baseWithSignature' => preg_match('/^' . $prefix . '\+frag\.[0-9a-f]{8}$/', $version) === 1,
		];

	}//end versionShape()

	/**
	 * Whether the merged config holds every schema and every seed object of
	 * the base file and of each fragment, the objects in file-name order.
	 *
	 * @param array<mixed> $data The register config handed to importFromApp.
	 *
	 * @return array<string, mixed>
	 */
	public static function dataShape(array $data): array {
		$schemas = [];
		$objects = [];
		$files = glob(self::SETTINGS . '/register.d/*.json');
		sort($files);
		array_unshift($files, self::SETTINGS . '/humaniq_register.json');
		foreach ($files as $file) {
			$part = json_decode((string)file_get_contents($file), true);
			$schemas = array_merge($schemas, array_keys($part['components']['schemas'] ?? []));
			$objects = array_merge($objects, ($part['components']['objects'] ?? []));
		}

		$merged = array_keys($data['components']['schemas'] ?? []);
		sort($merged);
		$schemas = array_values(array_unique($schemas));
		sort($schemas);
		return [
			'openapi' => isset($data['openapi']),
			'allSchemas' => $merged === $schemas,
			'allObjects' => count($data['components']['objects'] ?? []) === count($objects),
			'objectsInFileOrder' => ($data['components']['objects'] ?? []) === $objects,
			'fragmentMarker' => isset($data['x-humaniq-fragment']),
		];

	}//end dataShape()

}//end class
