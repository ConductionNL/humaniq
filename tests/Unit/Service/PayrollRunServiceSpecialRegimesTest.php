<?php

/**
 * PayrollRunService benefit and special-regime tests.
 *
 * The benefit pass of payroll-special-regimes: an active benefit entitlement
 * gets its own payslip whether or not the person is still employed, next to
 * a salary payslip of the same person; a political-office contract is paid
 * under its regime without employee insurance premiums; and an unverified
 * regime pays nothing and lists the person as regime-unverified. Drives the
 * service through a fake ObjectService double; the verified regime comes
 * from an uploaded tables document so the bundled, unverified figures stay
 * as they are.
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
 * @spec openspec/changes/payroll-special-regimes
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\JurisdictionPack;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PackSourceInterface;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\SickPayCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\TaxTableSourceInterface;
use OCA\Humaniq\Service\EmployerPremiumSettings;
use OCA\Humaniq\Service\PayrollRetentionGuardService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Benefit payslips and the office-holder regime in the payroll run.
 *
 * @covers \OCA\Humaniq\Service\PayrollRunService
 * @uses   \OCA\Humaniq\Payroll\BenefitEntitlementPay
 */
class PayrollRunServiceSpecialRegimesTest extends TestCase {

	/**
	 * The tables id of the verified test document.
	 *
	 * @var string
	 */
	private const VERIFIED = 'nl-2026-regimes';

	/**
	 * Tear down the uploaded-tables source.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		TaxTables::useSource(null);
		TaxTables::resetAvailableIdsCache();
	}//end tearDown()

	/**
	 * A fake ObjectService.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows per schema.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $rowsBySchema = []): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * Every saveObject() call, as `['schema' => ..., 'object' => ...]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * Every deleteObject() uuid, in delete order.
			 *
			 * @var array<int, string>
			 */
			public array $deleted = [];

			/**
			 * Every find() call: the one ObjectService read that leaves a `read` audit entry.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $found = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				public array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
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
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object to save.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param string|null $uuid Existing id when updating.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed> The saved object (with its id).
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$targetSchema = ($schema ?? $this->schema);

				$id = ($uuid ?? ('generated-' . $targetSchema . '-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id]);

				$this->saved[] = ['schema' => $targetSchema, 'object' => $saved];

				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				$replaced = false;
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$rows[$i] = $saved;
						$replaced = true;
						break;
					}
				}

				if ($replaced === false) {
					$rows[] = $saved;
				}

				$this->rowsBySchema[$targetSchema] = $rows;

				return $saved;
			}//end saveObject()

			/**
			 * @param string $uuid The object id to delete.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return bool
			 */
			/**
			 * A single-object read, recorded; it answers nothing.
			 *
			 * @param mixed ...$args The call's arguments.
			 *
			 * @return null
			 */
			public function find(mixed ...$args): mixed {
				$this->found[] = $args;
				return null;
			}//end find()

			/**
			 * Delete a row.
			 *
			 * @param string $uuid The id.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return bool
			 */
			public function deleteObject(
				string $uuid,
				?string $register = null,
				?string $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): bool {
				$this->deleted[] = $uuid;

				$targetSchema = ($schema ?? $this->schema);
				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $uuid) {
						unset($rows[$i]);
						break;
					}
				}

				$this->rowsBySchema[$targetSchema] = array_values($rows);

				return true;
			}//end deleteObject()

		};

	}//end fakeObjectService()

	/**
	 * The service, with the bundled pack pointed at verified regime tables
	 * unless the bundled ones are asked for.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows per schema.
	 * @param bool                                            $verified     Whether the regimes are verified.
	 *
	 * @return array{0: PayrollRunService, 1: object}
	 */
	private function service(array $rowsBySchema, bool $verified = true): array {
		$fake = $this->fakeObjectService(array_merge(['PayrollRun' => [], 'Payslip' => [], 'EmploymentContract' => [], 'BenefitEntitlement' => []], $rowsBySchema));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$packs = new PackRepository();
		if ($verified === true) {
			$tables = PackFixtures::tables(self::VERIFIED);
			$tables['year'] = 2026;
			foreach ($tables['parameters']['specialRegimes'] as $regime => $leaves) {
				if (is_array($leaves) === false) {
					continue;
				}

				foreach ($leaves as $key => $leaf) {
					$tables['parameters']['specialRegimes'][$regime][$key]['verified'] = true;
				}
			}

			TaxTables::useSource(
				static fn (): TaxTableSourceInterface => new class($tables) implements TaxTableSourceInterface {
					/**
					 * The source.
					 *
					 * @param array<string, mixed> $tables The tables document.
					 */
					public function __construct(
						private readonly array $tables,
					) {
					}//end __construct()

					/**
					 * The tables for an id.
					 *
					 * @param string $id The tables id.
					 *
					 * @return array<string, mixed>|null
					 */
					public function activeTables(string $id): ?array {
						return ($id === PayrollRunServiceSpecialRegimesTest::tablesId() ? $this->tables : null);
					}//end activeTables()
				}
			);

			$pack = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Standards/packs/nl-2026.pack.json'), true);
			$pack['tables'] = self::VERIFIED;
			$packs = new PackRepository(
				new class(new JurisdictionPack($pack)) implements PackSourceInterface {
					/**
					 * The source.
					 *
					 * @param JurisdictionPack $pack The pack.
					 */
					public function __construct(
						private readonly JurisdictionPack $pack,
					) {
					}//end __construct()

					/**
					 * The active pack.
					 *
					 * @param string $jurisdiction The jurisdiction.
					 * @param int $taxYear The tax year.
					 *
					 * @return JurisdictionPack|null
					 */
					public function activePack(string $jurisdiction, int $taxYear): ?JurisdictionPack {
						return $this->pack;
					}//end activePack()
				}
			);
		}//end if

		$service = new PayrollRunService($container, $settings, new EmployerPremiumSettings($this->createMock(IAppConfig::class)), new PayrollCalculator(), new SickPayCalculator(), $this->createMock(PayrollRetentionGuardService::class), $this->createMock(LoggerInterface::class), packs: $packs);

		return [$service, $fake];
	}//end service()

	/**
	 * The verified tables id, for the anonymous source.
	 *
	 * @return string
	 */
	public static function tablesId(): string {
		return self::VERIFIED;
	}//end tablesId()

	/**
	 * A person.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function person(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-1',
				'employeeNumber' => 'EMP-NL-0001',
				'firstName' => 'Jan',
				'lastName' => 'de Groot',
				'dateOfBirth' => '1961-03-15',
				'startDate' => '2001-01-01',
				'endDate' => '2026-06-30',
				'grossMonthlySalary' => 3800.00,
				'taxTableColor' => 'wit',
				'loonheffingskortingToegepast' => true,
				'bsn' => '123456782',
				'identityDocumentVerified' => true,
				'nextcloudUserId' => 'jan',
			],
			$overrides
		);
	}//end person()

	/**
	 * A benefit entitlement.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function entitlement(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ben-1',
				'employeeId' => 'emp-1',
				'kind' => 'rvu',
				'startDate' => '2026-07-01',
				'paymentMode' => 'periodic',
				'monthlyAmount' => 2000.00,
				'status' => 'active',
			],
			$overrides
		);
	}//end entitlement()

	/**
	 * The saved rows of one schema.
	 *
	 * @param object $fake   The fake ObjectService.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedFor(object $fake, string $schema): array {
		$out = [];
		foreach ($fake->saved as $entry) {
			if ($entry['schema'] === $schema) {
				$out[] = $entry['object'];
			}
		}

		return $out;
	}//end savedFor()

	/**
	 * A former employee receives the monthly RVU as its own payslip, with withheld Zvw and no insurance premiums.
	 *
	 * @return void
	 */
	public function testAFormerEmployeeReceivesTheMonthlyBenefit(): void {
		[$service, $fake] = $this->service(['Employee' => [$this->person()], 'BenefitEntitlement' => [$this->entitlement()]]);

		$result = $service->runFor('2026-07');

		self::assertSame('calculated', $result['status']);
		self::assertSame([], $result['skipped']);
		$payslips = $this->savedFor($fake, 'Payslip');
		self::assertCount(1, $payslips);
		$slip = $payslips[0];
		self::assertSame('ben-1', $slip['benefitEntitlementId']);
		self::assertSame('emp-1', $slip['employeeId']);
		self::assertSame('rvu', $slip['specialRegime']);
		self::assertSame(2000.00, $slip['grossPay']);
		self::assertSame('inhouding', $slip['zvwMode']);
		self::assertSame(97.00, $slip['zvw']);
		self::assertSame(0.0, $slip['werknemersverzekeringen']);
		self::assertSame(0.0, $slip['rvuEindheffing']);
		self::assertSame(round((2000.00 - $slip['loonheffing'] - 97.00), 2), $slip['nettoPay']);
		self::assertSame('inhouding', $slip['engineInputSnapshot']['zvwMode']);
		self::assertSame('groen', $slip['engineInputSnapshot']['taxTableColor']);
		self::assertSame(2000.00, $result['totals']['totalGross']);
	}//end testAFormerEmployeeReceivesTheMonthlyBenefit()

	/**
	 * A person with a covering contract and an active wachtgeld entitlement has a salary and a benefit payslip.
	 *
	 * @return void
	 */
	public function testSalaryAndBenefitInOneMonth(): void {
		$rows = [
			'Employee' => [$this->person(['endDate' => null])],
			'EmploymentContract' => [['id' => 'ct-1', 'employeeId' => 'emp-1', 'type' => 'permanent', 'writtenContract' => true, 'startDate' => '2022-01-01', 'endDate' => null, 'hoursPerWeek' => 36.0, 'awfTariff' => 'low']],
			'BenefitEntitlement' => [$this->entitlement(['kind' => 'wachtgeld', 'monthlyAmount' => 1500.00, 'offsetIncomeMonthly' => 250.00])],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->runFor('2026-07');

		self::assertCount(2, $result['computed']);
		$payslips = $this->savedFor($fake, 'Payslip');
		self::assertCount(2, $payslips);
		self::assertArrayNotHasKey('benefitEntitlementId', $payslips[0]);
		self::assertArrayNotHasKey('specialRegime', $payslips[0]);
		self::assertSame(3800.00, $payslips[0]['grossPay']);
		self::assertSame('ben-1', $payslips[1]['benefitEntitlementId']);
		self::assertSame('wachtgeld', $payslips[1]['specialRegime']);
		self::assertSame(1250.00, $payslips[1]['grossPay']);

		// Recalculating upserts both by their own key: no new payslip.
		$service->runFor('2026-07', recalculate: true);
		self::assertCount(2, $fake->rowsBySchema['Payslip']);
		self::assertSame([], $fake->deleted);
	}//end testSalaryAndBenefitInOneMonth()

	/**
	 * An unverified regime pays nothing and lists the person as regime-unverified.
	 *
	 * @return void
	 */
	public function testAnUnverifiedRegimePaysNothing(): void {
		[$service, $fake] = $this->service(['Employee' => [$this->person()], 'BenefitEntitlement' => [$this->entitlement(['kind' => 'wachtgeld'])]], verified: false);

		$result = $service->runFor('2026-07');

		self::assertSame([], $this->savedFor($fake, 'Payslip'));
		self::assertCount(1, $result['skipped']);
		self::assertSame('emp-1', $result['skipped'][0]['employeeId']);
		self::assertStringStartsWith('regime-unverified', $result['skipped'][0]['reason']);
		self::assertStringContainsString('specialRegimes.wachtgeld.zvwMode', $result['skipped'][0]['reason']);
	}//end testAnUnverifiedRegimePaysNothing()

	/**
	 * An alderman is paid under the political-office regime without employee insurance premiums.
	 *
	 * @return void
	 */
	public function testAnAldermansPayslip(): void {
		$rows = [
			'Employee' => [$this->person(['id' => 'emp-w', 'employeeNumber' => 'EMP-NL-0099', 'dateOfBirth' => '1975-05-01', 'endDate' => null, 'grossMonthlySalary' => 7000.00])],
			'EmploymentContract' => [['id' => 'ct-w', 'employeeId' => 'emp-w', 'type' => 'political-office', 'officeRole' => 'wethouder', 'writtenContract' => true, 'startDate' => '2026-01-01', 'endDate' => null, 'hoursPerWeek' => 36.0, 'awfTariff' => 'low']],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->runFor('2026-05');

		self::assertSame([], $result['skipped']);
		$slip = $this->savedFor($fake, 'Payslip')[0];
		self::assertSame('politiek-ambt', $slip['specialRegime']);
		self::assertSame(0.0, $slip['werknemersverzekeringen']);
		self::assertSame('werkgeversheffing', $slip['zvwMode']);
		self::assertFalse($slip['engineInputSnapshot']['verzekeringsplichtig']);
		self::assertArrayNotHasKey('benefitEntitlementId', $slip);

		[$bundled] = $this->service($rows, verified: false);
		$refused = $bundled->runFor('2026-05');
		self::assertSame([], $refused['computed']);
		self::assertStringStartsWith('regime-unverified', $refused['skipped'][0]['reason']);
	}//end testAnAldermansPayslip()

	/**
	 * An RVU above the threshold carries the final levy on the excess; a lump sum and a draft entitlement are not paid.
	 *
	 * @return void
	 */
	public function testTheFinalLevyAndWhatIsNotPaid(): void {
		$rows = [
			'Employee' => [$this->person()],
			'BenefitEntitlement' => [
				$this->entitlement(['monthlyAmount' => 3000.00]),
				$this->entitlement(['id' => 'ben-2', 'paymentMode' => 'lump-sum', 'lumpSumAmount' => 40000.00, 'lumpSumPeriod' => '2026-07']),
				$this->entitlement(['id' => 'ben-3', 'status' => 'draft']),
			],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->runFor('2026-07');

		$payslips = $this->savedFor($fake, 'Payslip');
		self::assertCount(1, $payslips);
		self::assertSame(round((3000.00 - 2286.00) * 0.52, 2), $payslips[0]['rvuEindheffing']);
		self::assertCount(1, $result['skipped']);
		self::assertStringStartsWith('lump-sum-pending', $result['skipped'][0]['reason']);
	}//end testTheFinalLevyAndWhatIsNotPaid()

	/**
	 * A benefit payslip whose entitlement ended is removed on recalculation, leaving the salary payslip alone.
	 *
	 * @return void
	 */
	public function testAnEndedEntitlementsPayslipIsRemoved(): void {
		$rows = ['Employee' => [$this->person()], 'BenefitEntitlement' => [$this->entitlement()]];
		[$service, $fake] = $this->service($rows);
		$service->runFor('2026-07');
		$payslipId = (string)$this->savedFor($fake, 'Payslip')[0]['id'];

		$fake->rowsBySchema['BenefitEntitlement'][0]['status'] = 'ended';
		$service->runFor('2026-07', recalculate: true);

		self::assertSame([$payslipId], $fake->deleted);
	}//end testAnEndedEntitlementsPayslipIsRemoved()

}//end class
