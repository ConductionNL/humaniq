<?php

/**
 * Golden test for RuleTestDataSeeder::seed().
 *
 * Runs the real provider seed corpus against an in-memory ObjectService double
 * that records every read and write by parameter name: an empty store, a store
 * that already holds rows (upsert keys that match, keys only under `@self`,
 * blank keys, rows missing seed fields and complete rows), reads that fail for
 * some object types, writes that fail for some object types, and the three ways
 * the admin user resolves. Each case's counts, call log and warnings are
 * compared with tests/fixtures/golden/rule-test-data-seeder.json. Set
 * RECORD_GOLDEN=1 to rewrite that file.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RuleTestDataEmployeeIndex;
use OCA\Humaniq\Service\RuleTestDataSeeder;
use OCA\Humaniq\Standards\CaoRegistry;
use OCA\Humaniq\Standards\Checks\CheckProvider;
use OCA\Humaniq\Standards\Checks\SeedsObjects;
use OCA\Humaniq\Standards\Checks\UpsertsObjects;
use OCA\Humaniq\Standards\RuleEngine;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden cases for the rule test-data seeder.
 *
 * No coverage metadata, like RuleTestDataSeederEmployeeIdTest: the run walks
 * the whole provider corpus, so every check provider executes.
 */
class RuleTestDataSeederGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/rule-test-data-seeder.json';

	/**
	 * Reset the provider caches.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		CaoRegistry::reset();

	}//end setUp()

	/**
	 * Reset the provider caches.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();
		CaoRegistry::reset();

	}//end tearDown()

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = ['real corpus only', 'empty store', 'prefilled store', 'object rows', 'reads fail', 'every read fails', 'writes fail', 'empty store writes fail', 'admin from group', 'admin by name', 'group without users'];
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
		$flags = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = (string)json_encode($this->seedCase($case), $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode($actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, $flags);
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$expected = (string)json_encode($golden[$case], $flags);
		$this->assertTrue($expected === $actual, 'Seeder output drifted for case ' . $case . ' at byte ' . strspn($expected ^ $actual, "\0"));

	}//end testGolden()

	/**
	 * Run the seeder for one case.
	 *
	 * @param string $case The case name.
	 *
	 * @return array<string, mixed> Counts, call log and warnings.
	 */
	private function seedCase(string $case): array {
		if ($case !== 'real corpus only') {
			self::addGoldenProvider();
		}

		$store = new SeederGoldenObjectService();
		if (in_array($case, ['real corpus only', 'empty store', 'empty store writes fail'], true) === false) {
			$store->rows = self::prefilledRows();
		}

		$store->objectRows = ($case === 'object rows');

		$types = array_keys(array_merge(RuleEngine::providerSeedObjects(), RuleEngine::providerSeedSpecs()));
		sort($types);
		foreach ($types as $index => $type) {
			if (($case === 'reads fail' && $index % 2 === 0) || $case === 'every read fails') {
				$store->failingReads[] = $type;
			}

			if (in_array($case, ['writes fail', 'empty store writes fail'], true) === true && $index % 2 === 1) {
				$store->failingWrites[] = $type;
			}
		}

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$counts = $this->seeder(case: $case, store: $store, logger: $logger)->seed();
		RuleEngine::reset();
		CaoRegistry::reset();

		return ['counts' => $counts, 'calls' => $store->calls, 'warnings' => $warnings];

	}//end seedCase()

	/**
	 * A seeder wired to the double, the logger and the case's admin lookup.
	 *
	 * @param string $case The case name.
	 * @param SeederGoldenObjectService $store The ObjectService double.
	 * @param LoggerInterface $logger The recording logger.
	 *
	 * @return RuleTestDataSeeder
	 */
	private function seeder(string $case, SeederGoldenObjectService $store, LoggerInterface $logger): RuleTestDataSeeder {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('humaniq');

		$groupUser = $this->createMock(IUser::class);
		$groupUser->method('getUID')->willReturn('group-admin');
		$namedUser = $this->createMock(IUser::class);
		$namedUser->method('getUID')->willReturn('admin');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn(($case === 'group without users' ? [] : [$groupUser]));

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn((in_array($case, ['admin from group', 'group without users'], true) === true ? $group : null));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn((in_array($case, ['admin by name', 'group without users'], true) === true ? $namedUser : null));

		return new RuleTestDataSeeder(
			$container,
			$appConfig,
			$userManager,
			$groupManager,
			$logger,
			new RuleTestDataEmployeeIndex($this->createMock(LoggerInterface::class))
		);

	}//end seeder()

	/**
	 * Add {@see SeederGoldenProvider} to the discovered providers, so field
	 * defaults, an empty sample list and an upsert type with blank keys are
	 * seeded too: the shipped corpus has none of them.
	 *
	 * @return void
	 */
	private static function addGoldenProvider(): void {
		$discover = new \ReflectionMethod(RuleEngine::class, 'providers');
		$found = $discover->invoke(null);
		(new \ReflectionProperty(RuleEngine::class, 'providers'))->setValue(null, array_merge($found, [SeederGoldenProvider::class]));

	}//end addGoldenProvider()

	/**
	 * Rows that already exist before the seeder runs.
	 *
	 * Every sample type gets rows; upsert types get one row whose key matches
	 * the first sample, one keyed only under `@self`, and one with a blank
	 * key. Seed-spec types alternate between a row missing every field and a
	 * row that already carries them all.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function prefilledRows(): array {
		$rows = [];
		$keys = RuleEngine::providerUpsertKeys();
		foreach (RuleEngine::providerSeedObjects() as $type => $samples) {
			$rows[$type] = [['id' => 'pre-' . $type . '-1', 'employeeNumber' => 'EMP-PRE-' . $type]];
			if (isset($keys[$type]) === false || $samples === []) {
				continue;
			}

			$key = $keys[$type];
			$rows[$type] = [
				['id' => 'pre-' . $type . '-key', $key => (string)($samples[0][$key] ?? '')],
				['@self' => ['id' => 'pre-' . $type . '-self'], $key => (string)(end($samples)[$key] ?? '')],
				['id' => 'pre-' . $type . '-blank', $key => '  '],
			];
		}

		$index = 0;
		foreach (RuleEngine::providerSeedSpecs() as $type => $fields) {
			if ($fields === []) {
				continue;
			}

			$complete = array_map(static fn ($value): mixed => (is_string($value) === true ? $value : 'set'), $fields);
			$rows[$type][] = ($index % 2 === 0 ? ['id' => 'spec-' . $type] : array_merge(['@self' => ['id' => 'spec-' . $type]], $complete));
			$rows[$type][] = ['@self' => ['id' => 'spec-' . $type . '-blank'], array_key_first($fields) => ' '];
			$index++;
		}

		return $rows;

	}//end prefilledRows()

}//end class

/**
 * In-memory ObjectService double that records every call by parameter name.
 */
class SeederGoldenObjectService {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Schemas whose reads throw.
	 *
	 * @var array<int, string>
	 */
	public array $failingReads = [];

	/**
	 * Schemas whose writes throw.
	 *
	 * @var array<int, string>
	 */
	public array $failingWrites = [];

	/**
	 * Whether reads return rows as JsonSerializable objects.
	 *
	 * @var boolean
	 */
	public bool $objectRows = false;

	/**
	 * Every call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * The register set last.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * The schema set last.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Ids handed out so far.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Select the register.
	 *
	 * @param string $register Register slug.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		$this->register = $register;
		return $this;

	}//end setRegister()

	/**
	 * Select the schema.
	 *
	 * @param string $schema Schema name.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;

	}//end setSchema()

	/**
	 * The rows of the selected schema.
	 *
	 * @param array<string, mixed> $config Query config.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException For a failing schema.
	 */
	public function findAll(array $config = []): array {
		$this->calls[] = ['findAll', $this->register, $this->schema, $config];
		if (in_array($this->schema, $this->failingReads, true) === true) {
			throw new RuntimeException('read refused for ' . $this->schema);
		}

		$rows = array_slice(($this->rows[$this->schema] ?? []), 0, (int)($config['limit'] ?? 10000));
		if ($this->objectRows === false) {
			return $rows;
		}

		return array_map(static fn (array $row): SeederGoldenRow => new SeederGoldenRow($row), $rows);

	}//end findAll()

	/**
	 * Save one object.
	 *
	 * @param mixed $object The object.
	 * @param string $register Register slug.
	 * @param string $schema Schema name.
	 * @param string|null $uuid Id to update, or null to create.
	 * @param bool $_rbac RBAC flag.
	 * @param bool $_multitenancy Multitenancy flag.
	 * @param mixed $currentUser Acting user.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException For a failing schema.
	 */
	public function saveObject(mixed $object = [], string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, mixed $currentUser = null): array {
		$object = (array)$object;
		$this->calls[] = [
			'saveObject',
			[
				'object' => md5((string)json_encode($object)),
				'register' => $register,
				'schema' => $schema,
				'uuid' => $uuid,
				'_rbac' => $_rbac,
				'_multitenancy' => $_multitenancy,
				'currentUser' => ($currentUser instanceof IUser ? $currentUser->getUID() : $currentUser),
			],
		];
		if (in_array($schema, $this->failingWrites, true) === true) {
			throw new RuntimeException('write refused for ' . $schema);
		}

		if ($uuid === null) {
			$this->counter++;
			$uuid = 'gen-' . $schema . '-' . $this->counter;
		}

		$saved = array_merge(['id' => $uuid], $object);
		$this->rows[$schema][] = $saved;
		return $saved;

	}//end saveObject()

}//end class

/**
 * A stored row as OpenRegister returns it: an entity that serialises to the row.
 */
class SeederGoldenRow implements \JsonSerializable {

	/**
	 * @param array<string, mixed> $row The row.
	 */
	public function __construct(
		private readonly array $row,
	) {

	}//end __construct()

	/**
	 * The row.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->row;

	}//end jsonSerialize()

}//end class

/**
 * A provider with what the shipped corpus lacks: field defaults, an empty
 * sample list and an upsert type whose samples include blank keys.
 */
class SeederGoldenProvider implements CheckProvider, SeedsObjects, UpsertsObjects {

	/**
	 * No checks.
	 *
	 * @return array<string, mixed>
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * Field defaults per object type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'GoldenSpec' => ['status' => 'active', 'code' => 'X1', 'amount' => 5],
			'GoldenSpecEmpty' => [],
			'Employee' => ['department' => 'HR'],
		];

	}//end seedSpec()

	/**
	 * Samples per object type.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'GoldenEmpty' => [],
			'GoldenUpsert' => [['code' => 'A', 'v' => 1], ['code' => ' ', 'v' => 2], ['code' => 'B', 'v' => 3], ['v' => 4], ['code' => 'A', 'v' => 5]],
			'GoldenPlain' => [['employeeId' => 'EMP-NL-0001', 'v' => 1], ['employeeId' => 'EMP-XX-9999', 'v' => 2]],
		];

	}//end seedObjects()

	/**
	 * The natural key per upserted object type.
	 *
	 * @return array<string, string>
	 */
	public static function upsertKeys(): array {
		return ['GoldenUpsert' => 'code'];

	}//end upsertKeys()

}//end class
