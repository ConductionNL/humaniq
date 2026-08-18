<?php

/**
 * Unit test for the seeder's Employee-UUID resolution.
 *
 * Several CheckProviders' seed samples (NlPayrollChecks' EmploymentContract /
 * Payslip, NlWageGarnishmentChecks' Loonbeslag) reference their anchor employee via
 * a synthetic `employeeNumber`-shaped placeholder in `employeeId`
 * ('EMP-NL-0001'). Every schema that types `employeeId` also requires
 * `format: 'uuid'`, so writing that literal placeholder always fails create
 * against a real OpenRegister instance — confirmed live: `occ
 * hrmq:rules:seed-testdata` logged "Property 'employeeId' should match format
 * 'uuid' but 'EMP-NL-0001' does not" for EmploymentContract, Payslip, AND
 * Loonbeslag. RuleTestDataSeeder now creates 'Employee' samples first, builds
 * an `employeeNumber => uuid` map from the resulting rows, and substitutes
 * that real UUID into every other sample's `employeeId` before saving it
 * (RuleTestDataSeeder::resolveEmployeeIdPlaceholder /
 * ::employeeUuidsByNumber). This test drives the REAL, unmocked
 * `RuleEngine::providerSeedObjects()` (the actual production seed corpus, not
 * a stub) against a fake in-memory ObjectService double, and asserts the
 * anchor employee's real generated id — not the literal 'EMP-NL-0001' string
 * — ends up in the Loonbeslag/EmploymentContract/Payslip rows' `employeeId`.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\RuleTestDataSeeder;
use OCA\Hrmq\Standards\CaoRegistry;
use OCA\Hrmq\Standards\RuleEngine;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the Employee-UUID resolution the seeder applies before writing
 * samples that reference an employee by the synthetic 'EMP-NL-0001' key.
 */
class RuleTestDataSeederEmployeeIdTest extends TestCase {

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
	 * Build a fake ObjectService that keeps an in-memory store keyed by
	 * schema and supports the register/schema/findAll/saveObject surface the
	 * seeder uses. Create assigns a fresh, schema-prefixed id that is
	 * deliberately NOT uuid-shaped and NOT equal to any employeeNumber
	 * placeholder, so a test assertion that a row's `employeeId` equals this
	 * generated id can never be trivially satisfied by an unresolved
	 * placeholder slipping through unchanged.
	 *
	 * @return object
	 */
	private function fakeObjectService(): object {
		return new class {
			/**
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $store = [];

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $counter = 0;

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
			 * @param array<string, mixed> $filters Query filters (unused beyond presence).
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
				// Deliberately UNLIKE both a uuid AND any 'EMP-*-0001' placeholder,
				// so a passing assertion proves real resolution happened.
				$id = 'fake-generated-id-' . $schema . '-' . $this->counter;
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
		$appConfig->method('getValueString')->willReturn('hrmq');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);

		return new RuleTestDataSeeder(
			$container,
			$appConfig,
			$userManager,
			$groupManager,
			$this->createMock(LoggerInterface::class)
		);

	}//end seederWith()

	/**
	 * Find the row in a fake-store schema slice whose `employeeNumber` field
	 * equals the given natural key. `RuleEngine::providerSeedObjects()` merges
	 * samples from every discovered provider (EuUsPayrollChecks contributes
	 * DE/FR/US Employee samples alongside NlPayrollChecks' NL one), so the NL
	 * anchor employee is not reliably at a fixed index — it must be located by
	 * its natural key.
	 *
	 * @param array<int, array<string, mixed>> $rows The schema's rows.
	 * @param string $employeeNumber The natural key to match.
	 *
	 * @return array<string, mixed>
	 */
	private function findByEmployeeNumber(array $rows, string $employeeNumber): array {
		foreach ($rows as $row) {
			if ((string)($row['employeeNumber'] ?? '') === $employeeNumber) {
				return $row;
			}
		}

		$this->fail('No row found with employeeNumber ' . $employeeNumber);

	}//end findByEmployeeNumber()

	/**
	 * The seeded Loonbeslag row's `employeeId` is the NL anchor Employee's
	 * real generated id, never the literal 'EMP-NL-0001' placeholder the
	 * provider's static seedObjects() declares.
	 *
	 * @return void
	 */
	public function testLoonbeslagEmployeeIdResolvesToRealEmployeeUuid(): void {
		$fake = $this->fakeObjectService();
		$seeder = $this->seederWith($fake);

		$seeder->seed();

		$employeeRows = ($fake->store['Employee'] ?? []);
		$this->assertNotEmpty($employeeRows, 'The anchor Employee sample must have been created.');
		$anchor = $this->findByEmployeeNumber($employeeRows, 'EMP-NL-0001');
		$anchorId = (string)$anchor['id'];
		$this->assertNotSame('EMP-NL-0001', $anchorId, 'Sanity: the fake generates its own id, not the natural key.');

		$loonbeslagRows = ($fake->store['Loonbeslag'] ?? []);
		$this->assertNotEmpty($loonbeslagRows, 'The Loonbeslag sample must have been created.');
		$this->assertSame(
			$anchorId,
			$loonbeslagRows[0]['employeeId'],
			'Loonbeslag.employeeId must resolve to the real Employee uuid, not the literal EMP-NL-0001 placeholder.'
		);
		$this->assertNotSame(
			'EMP-NL-0001',
			$loonbeslagRows[0]['employeeId'],
			'Loonbeslag.employeeId must never be the unresolved natural-key placeholder.'
		);

	}//end testLoonbeslagEmployeeIdResolvesToRealEmployeeUuid()

	/**
	 * The same resolution applies to NlPayrollChecks' own EmploymentContract
	 * and Payslip samples (matched to the NL anchor employee among the merged
	 * NL/DE/FR/US rows) AND, since EuUsPayrollChecks seeds its own DE/FR/US
	 * Employee samples under the identical natural-key scheme, to the DE/FR/US
	 * EmploymentContract/Payslip rows as well — the fix is general, not
	 * NL-only.
	 *
	 * @return void
	 */
	public function testEmploymentContractAndPayslipEmployeeIdResolveToRealEmployeeUuidForEveryJurisdiction(): void {
		$fake = $this->fakeObjectService();
		$seeder = $this->seederWith($fake);

		$seeder->seed();

		$employeeRows = $fake->store['Employee'];
		$contractRows = $fake->store['EmploymentContract'];
		$payslipRows = $fake->store['Payslip'];

		foreach (['EMP-NL-0001', 'EMP-DE-0001', 'EMP-FR-0001', 'EMP-US-0001'] as $employeeNumber) {
			$anchorId = (string)$this->findByEmployeeNumber($employeeRows, $employeeNumber)['id'];

			$contractMatch = null;
			foreach ($contractRows as $row) {
				if (($row['employeeId'] ?? null) === $anchorId) {
					$contractMatch = $row;
					break;
				}
			}

			$this->assertNotNull($contractMatch, 'An EmploymentContract row must resolve to the ' . $employeeNumber . ' employee uuid.');

			$payslipMatch = null;
			foreach ($payslipRows as $row) {
				if (($row['employeeId'] ?? null) === $anchorId) {
					$payslipMatch = $row;
					break;
				}
			}

			$this->assertNotNull($payslipMatch, 'A Payslip row must resolve to the ' . $employeeNumber . ' employee uuid.');
		}//end foreach

		// No row of either type may still carry an unresolved natural-key placeholder.
		foreach (array_merge($contractRows, $payslipRows) as $row) {
			$this->assertDoesNotMatchRegularExpression(
				'/^EMP-[A-Z]{2}-\d+$/',
				(string)($row['employeeId'] ?? ''),
				'No EmploymentContract/Payslip row may retain an unresolved EMP-XX-0001 placeholder.'
			);
		}

	}//end testEmploymentContractAndPayslipEmployeeIdResolveToRealEmployeeUuidForEveryJurisdiction()

}//end class
