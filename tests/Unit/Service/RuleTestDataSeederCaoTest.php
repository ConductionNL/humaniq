<?php

/**
 * Unit test for the CAO reference-object seed idempotency (cao-library).
 *
 * Drives RuleTestDataSeeder::seed() against a fake ObjectService double and
 * asserts the UpsertsObjects contract for the read-only `Cao` display objects
 * (REQ-CAO-006): re-seeding creates no duplicate `Cao` object (upsert on the
 * natural `caoId` key, not the OpenRegister object id) and converges a stale
 * pre-existing row's values to the corpus, preserving that row's object id. The
 * corpus is authoritative; the `Cao` objects are a derived projection.
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
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RuleTestDataEmployeeIndex;
use OCA\Humaniq\Service\RuleTestDataSeeder;
use OCA\Humaniq\Standards\CaoRegistry;
use OCA\Humaniq\Standards\RuleEngine;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the Cao seed upsert idempotency + convergence.
 *
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
 */
class RuleTestDataSeederCaoTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		CaoRegistry::reset();

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();
		CaoRegistry::reset();

	}//end tearDown()

	/**
	 * Build a fake ObjectService that keeps an in-memory store keyed by schema
	 * and supports the register/schema/findAll/saveObject surface the seeder
	 * uses. Upsert-by-uuid replaces in place; create assigns a fresh id.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $seedStore Initial rows keyed by schema.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $seedStore): object {
		return new class($seedStore) {
			/**
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $store;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $counter = 0;

			/**
			 * @param array<string, array<int, array<string, mixed>>> $seedStore Initial rows.
			 */
			public function __construct(array $seedStore) {
				$this->store = $seedStore;

			}//end __construct()

			/**
			 * @param string $register Register slug (unused).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $filters Query filters (only limit honoured).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $filters = []): array {
				return ($this->store[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param mixed $object The object to save.
			 * @param string $register Register slug (unused).
			 * @param string $schema Schema name.
			 * @param string|null $uuid Existing id to update, or null to create.
			 * @param bool $_rbac RBAC flag (unused).
			 * @param bool $_multitenancy Multitenancy flag (unused).
			 * @param mixed $currentUser Acting user (unused).
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(mixed $object = [], string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, mixed $currentUser = null): array {
				$object = (array)$object;

				if ($uuid !== null && $uuid !== '') {
					foreach (($this->store[$schema] ?? []) as $i => $row) {
						if ((string)($row['id'] ?? '') === $uuid) {
							$this->store[$schema][$i] = array_merge(['id' => $uuid], $object);
							return $this->store[$schema][$i];
						}
					}
				}

				$this->counter++;
				$id = $schema . '-' . $this->counter;
				$saved = array_merge(['id' => $id], $object);
				$this->store[$schema][] = $saved;
				return $saved;
			}//end saveObject()

		};

	}//end fakeObjectService()

	/**
	 * Build a seeder wired to the fake ObjectService.
	 *
	 * @param object $objectService The fake ObjectService.
	 *
	 * @return RuleTestDataSeeder
	 */
	private function seederWith(object $objectService): RuleTestDataSeeder {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('humaniq');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);

		return new RuleTestDataSeeder(
			$container,
			$appConfig,
			$userManager,
			$groupManager,
			$this->createMock(LoggerInterface::class),
			new RuleTestDataEmployeeIndex($this->createMock(LoggerInterface::class))
		);

	}//end seederWith()

	/**
	 * A stale pre-existing cao-generiek row is converged (not duplicated) and
	 * every other corpus CAO is created exactly once; re-running the seed
	 * leaves the Cao row count unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
	 */
	public function testSeedUpsertsCaoObjectsWithoutDuplicating(): void {
		$expected = count(CaoRegistry::availableCaos());
		$this->assertGreaterThanOrEqual(3, $expected);

		// A stale, hand-diverged cao-generiek row already exists.
		$fake = $this->fakeObjectService(
			[
				'Cao' => [
					['id' => 'cao-obj-existing', 'caoId' => 'cao-generiek', 'sector' => 'STALE SECTOR', 'name' => 'stale'],
				],
			]
		);

		$seeder = $this->seederWith($fake);

		// First seed: converges the stale row, creates the rest.
		$seeder->seed();

		$caoRows = $fake->store['Cao'];
		$this->assertCount($expected, $caoRows, 'One Cao row per corpus CAO — no duplicate for cao-generiek.');

		$byCaoId = [];
		foreach ($caoRows as $row) {
			$byCaoId[$row['caoId']] = $row;
		}

		// The stale row kept its object id and converged its sector to the corpus.
		$this->assertSame('cao-obj-existing', $byCaoId['cao-generiek']['id'], 'The existing object id is preserved (upsert in place).');
		$corpusSector = CaoRegistry::availableCaos()['cao-generiek']['sector'];
		$this->assertSame($corpusSector, $byCaoId['cao-generiek']['sector'], 'The stale sector converged to the corpus value.');

		// Second seed: still no duplicates (idempotent).
		$seeder->seed();
		$this->assertCount($expected, $fake->store['Cao'], 'Re-seeding creates no duplicate Cao objects.');

	}//end testSeedUpsertsCaoObjectsWithoutDuplicating()

	/**
	 * Cao is the object type the CAO provider declares its upsert key for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
	 */
	public function testCaoUpsertKeyIsRegistered(): void {
		$this->assertSame('caoId', (RuleEngine::providerUpsertKeys()['Cao'] ?? null));

	}//end testCaoUpsertKeyIsRegistered()

}//end class
