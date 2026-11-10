<?php

/**
 * Golden test for the humaniq:references:audit command.
 *
 * Runs the audit, read-only and writing, over satellite and owner rows that
 * cover every verdict: a reference that is set, dangling, or read while the
 * owner register is down; identity keys that match one owner, several or none;
 * blank keys and blank owner ids; rows as arrays, as entities that serialise or
 * expose getObject(), and as values that resolve to nothing; more rows than one
 * read page; a write that fails; and a satellite read that fails. Each case's
 * exit code, console output and ObjectService calls are compared with
 * tests/fixtures/golden/references-audit.json. Set RECORD_GOLDEN=1 to rewrite
 * that file.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Command
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
 * @spec openspec/specs/multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\ReferencesAuditCommand;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden cases for the reference audit.
 *
 * @covers \OCA\Humaniq\Command\ReferencesAuditCommand
 * @uses   \OCA\Humaniq\Command\ReferenceAuditRun
 */
class ReferencesAuditGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/references-audit.json';

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = array_keys(self::scenarios());
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
		$flags = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$actual = (string)json_encode($this->audit(self::scenarios()[$case]), $flags);
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
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * Run the command over one scenario.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<string, mixed> Exit code, output and calls.
	 */
	private function audit(array $s): array {
		$store = new AuditGoldenObjectService(
			['hrAdministration' => $s['satellites'], 'Administration' => $s['owners']],
			($s['failing'] ?? [])
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$tester = new CommandTester(new ReferencesAuditCommand($container));
		$code = $tester->execute(($s['write'] ?? false) === true ? ['--write' => true] : []);

		return ['exit' => $code, 'output' => explode("\n", $tester->getDisplay()), 'calls' => $store->calls];

	}//end audit()

	/**
	 * Satellite rows that cover every verdict, in every shape a row comes in.
	 *
	 * @return array<int, mixed>
	 */
	private static function satellites(): array {
		return [
			['id' => 's-set', 'administration' => 'o-1', 'kvkNumber' => '111'],
			['id' => 's-dangling', 'administration' => ' o-gone ', 'kvkNumber' => '111'],
			['id' => 's-one', 'kvkNumber' => ' 111 '],
			['id' => 's-two', 'kvkNumber' => '222'],
			['id' => 's-none', 'kvkNumber' => '999'],
			['id' => 's-blank', 'kvkNumber' => '  ', 'administration' => ''],
			['id' => 's-nokey'],
			['kvkNumber' => '333'],
			new AuditGoldenRow(['id' => 's-entity', 'kvkNumber' => '333'], 'jsonSerialize'),
			new AuditGoldenRow(['id' => 's-object', 'kvkNumber' => '444'], 'getObject'),
			new AuditGoldenRow(['id' => 's-broken'], 'throws'),
			'not a row',
			[],
			['id' => 's-fail', 'kvkNumber' => '555'],
			['id' => 's-blank-owner', 'kvkNumber' => '666'],
		];

	}//end satellites()

	/**
	 * Owner rows: single and duplicate keys, blank keys and blank ids.
	 *
	 * @return array<int, mixed>
	 */
	private static function owners(): array {
		return [
			['id' => 'o-1', 'kvkNumber' => '111'],
			['id' => 'o-2a', 'kvkNumber' => '222'],
			new AuditGoldenRow(['id' => 'o-2b', 'kvkNumber' => ' 222'], 'jsonSerialize'),
			['id' => 'o-3', 'kvkNumber' => '333'],
			new AuditGoldenRow(['id' => 'o-4', 'kvkNumber' => '444'], 'getObject'),
			['id' => 'o-blank', 'kvkNumber' => ''],
			['id' => 'o-5', 'kvkNumber' => '555'],
			['kvkNumber' => '666'],
			'junk',
		];

	}//end owners()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$many = [];
		for ($i = 0; $i < 401; $i++) {
			$many[] = ['id' => 'p-' . $i, 'kvkNumber' => (string)(100 + ($i % 7))];
		}

		$manyOwners = [];
		for ($i = 0; $i < 205; $i++) {
			$manyOwners[] = ['id' => 'q-' . $i, 'kvkNumber' => (string)(100 + ($i % 211))];
		}

		return [
			'dry run' => ['satellites' => self::satellites(), 'owners' => self::owners()],
			'write' => ['satellites' => self::satellites(), 'owners' => self::owners(), 'write' => true, 'failing' => ['patch:s-fail']],
			'owners unreadable' => ['satellites' => self::satellites(), 'owners' => self::owners(), 'write' => true, 'failing' => ['read:Administration']],
			'satellites unreadable' => ['satellites' => self::satellites(), 'owners' => self::owners(), 'failing' => ['read:hrAdministration']],
			'nothing to do' => ['satellites' => [], 'owners' => []],
			'all set' => ['satellites' => [['id' => 's', 'administration' => 'o-1']], 'owners' => self::owners()],
			'only dangling' => ['satellites' => [['id' => 's', 'administration' => 'o-x']], 'owners' => self::owners(), 'write' => true],
			'pages dry' => ['satellites' => $many, 'owners' => $manyOwners],
			'pages write' => ['satellites' => $many, 'owners' => $manyOwners, 'write' => true],
		];

	}//end scenarios()

}//end class

/**
 * A row as an entity: its payload behind one accessor, or behind none.
 */
class AuditGoldenRow {

	/**
	 * @param array<string, mixed> $payload The payload.
	 * @param string $accessor `jsonSerialize`, `getObject` or `throws`.
	 */
	public function __construct(
		private readonly array $payload,
		private readonly string $accessor,
	) {

	}//end __construct()

	/**
	 * The payload when this row serialises.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When this row only throws.
	 */
	public function jsonSerialize(): mixed {
		if ($this->accessor === 'throws') {
			throw new RuntimeException('broken entity');
		}

		return ($this->accessor === 'jsonSerialize' ? $this->payload : 'no payload here');

	}//end jsonSerialize()

	/**
	 * The payload when this row exposes it as an object.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When this row only throws.
	 */
	public function getObject(): mixed {
		if ($this->accessor === 'throws') {
			throw new RuntimeException('broken entity');
		}

		return $this->payload;

	}//end getObject()

}//end class

/**
 * Paged in-memory ObjectService that records every call.
 */
class AuditGoldenObjectService {

	/**
	 * Every call, in order.
	 *
	 * @var array<int, array<int, mixed>>
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
	 * @param array<string, array<int, mixed>> $rows Rows per schema.
	 * @param array<int, string> $failing `read:<schema>` and `patch:<id>` entries that throw.
	 */
	public function __construct(
		private readonly array $rows,
		private readonly array $failing,
	) {

	}//end __construct()

	/**
	 * Select the register.
	 *
	 * @param string $register The register slug.
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
	 * @param string $schema The schema slug.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;

	}//end setSchema()

	/**
	 * One page of the selected schema.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return array<int, mixed>
	 *
	 * @throws RuntimeException For a failing schema.
	 */
	public function findAll(array $config): array {
		$this->calls[] = ['findAll', $this->register, $this->schema, $config];
		if (in_array('read:' . $this->schema, $this->failing, true) === true) {
			throw new RuntimeException('cannot read ' . $this->schema);
		}

		return array_slice(($this->rows[$this->schema] ?? []), (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 0));

	}//end findAll()

	/**
	 * Record one patch.
	 *
	 * @param ObjectPatch $patch The object id, the patch and the register and schema it is in.
	 * @param bool $_rbac RBAC flag.
	 * @param bool $_multitenancy Multitenancy flag.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException For a failing id.
	 */
	public function patchObject(
		ObjectPatch $patch,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$objectId = $patch->objectId;
		$data = $patch->data;
		$register = $patch->target->register;
		$schema = $patch->target->schema;
		$this->calls[] = ['patchObject', ['objectId' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy]];
		if (in_array('patch:' . $objectId, $this->failing, true) === true) {
			throw new RuntimeException('patch refused');
		}

		return $data;

	}//end patchObject()

}//end class
