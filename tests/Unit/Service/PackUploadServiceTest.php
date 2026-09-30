<?php

/**
 * Pack and tables uploaded as one unit, deactivated without touching runs,
 * and a year's resolution answered for the page and occ.
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
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PackValidator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\JurisdictionPackService;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TaxTableSetService;
use OCA\Humaniq\Service\YearTransitionService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The upload, deactivation and year resolution of payroll packs and tables.
 */
class PackUploadServiceTest extends TestCase {

	private FakeObjectStore $store;

	private TaxTableSetService $tableSets;

	private JurisdictionPackService $packs;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$this->tableSets = new TaxTableSetService(gateway: $gateway);
		$this->packs = new JurisdictionPackService(gateway: $gateway, validator: new PackValidator(), tableSets: $this->tableSets, logger: new NullLogger());
		$tableSets = $this->tableSets;
		TaxTables::useSource(static fn () => $tableSets);
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		TaxTables::useSource(null);
		parent::tearDown();
	}//end tearDown()

	/**
	 * The schema slugs the services write are the ones the register declares.
	 *
	 * @return void
	 */
	public function testTheServicesWriteTheSchemasTheRegisterDeclares(): void {
		self::assertSame('JurisdictionPack', RegisterSchemaValidator::schema(JurisdictionPackService::SCHEMA)['slug']);
		self::assertSame('TaxTableSet', RegisterSchemaValidator::schema(TaxTableSetService::SCHEMA)['slug']);
	}//end testTheServicesWriteTheSchemasTheRegisterDeclares()

	/**
	 * REQ-PKU-001 A payroll administrator loads 2027: both are stored, active,
	 * each valid against its schema, and a 2027 period resolves to the upload.
	 *
	 * @return void
	 */
	public function testA2027PackWithItsTablesIsStoredAndResolves(): void {
		$stored = $this->packs->upload(PackFixtures::pack(), false, PackFixtures::tables());

		self::assertSame('nl-2027', $stored['packId']);
		self::assertTrue($stored['active']);
		$pack = array_values($this->store->state->objects['JurisdictionPack']);
		$tables = array_values($this->store->state->objects['TaxTableSet']);
		self::assertCount(1, $pack);
		self::assertCount(1, $tables);
		self::assertSame([], RegisterSchemaValidator::errors('JurisdictionPack', $pack[0]));
		self::assertSame([], RegisterSchemaValidator::errors('TaxTableSet', $tables[0]));
		self::assertSame('nl-2027', $tables[0]['tablesId']);
		self::assertTrue($tables[0]['active']);

		$resolved = (new PackRepository($this->packs))->resolve('NL', '2027-01');
		self::assertSame('nl-2027@1.0.0', $resolved->engineVersion());
		self::assertSame(4.85, TaxTables::load('nl-2027')->zvw()['inhouding']);
	}//end testA2027PackWithItsTablesIsStoredAndResolves()

	/**
	 * REQ-PKU-001 A table that breaks the golden vectors is refused whole.
	 *
	 * @return void
	 */
	public function testATableThatBreaksTheGoldenVectorIsRefusedWhole(): void {
		$tables = PackFixtures::tables();
		$tables['parameters']['zvw']['inhouding']['value'] = 48.5;

		try {
			$this->packs->upload(PackFixtures::pack(), false, $tables);
			self::fail('a mistyped rate must be refused');
		} catch (DslException $e) {
			self::assertStringContainsString('selfTest', $e->getMessage());
		}

		self::assertSame([], $this->store->state->saves, 'neither the pack nor the tables exist afterwards');
	}//end testATableThatBreaksTheGoldenVectorIsRefusedWhole()

	/**
	 * REQ-PKU-001 The bundled year cannot be shadowed by an upload.
	 *
	 * @return void
	 */
	public function testTablesNamedAfterTheBundledYearAreRefused(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/nl-2026/');
		$this->tableSets->validate(PackFixtures::tables('nl-2026'));
	}//end testTablesNamedAfterTheBundledYearAreRefused()

	/**
	 * REQ-PKU-001 A missing parameter group is refused.
	 *
	 * @return void
	 */
	public function testTablesMissingTheZvwGroupAreRefused(): void {
		$tables = PackFixtures::tables();
		unset($tables['parameters']['zvw']);

		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/zvw/');
		$this->tableSets->validate($tables);
	}//end testTablesMissingTheZvwGroupAreRefused()

	/**
	 * REQ-PKU-001 An unverified leaf must say what to check it against.
	 *
	 * @return void
	 */
	public function testAnUnverifiedLeafWithoutCheckAgainstIsRefused(): void {
		$tables = PackFixtures::tables();
		$tables['parameters']['zvw']['inhouding']['verified'] = false;

		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/zvw\.inhouding/');
		$this->tableSets->validate($tables);
	}//end testAnUnverifiedLeafWithoutCheckAgainstIsRefused()

	/**
	 * REQ-PKU-001 A bare figure without a source is refused.
	 *
	 * @return void
	 */
	public function testAFigureWithoutASourceIsRefused(): void {
		$tables = PackFixtures::tables();
		$tables['parameters']['zvw']['inhouding'] = 4.85;

		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/zvw\.inhouding/');
		$this->tableSets->validate($tables);
	}//end testAFigureWithoutASourceIsRefused()

	/**
	 * REQ-PKU-001 The tables must be the ones the pack declares.
	 *
	 * @return void
	 */
	public function testTablesThePackDoesNotDeclareAreRefused(): void {
		try {
			$this->packs->upload(PackFixtures::pack(95150, 'nl-2027'), false, PackFixtures::tables('nl-2029'));
			self::fail('tables the pack does not use must be refused');
		} catch (DslException $e) {
			self::assertStringContainsString('nl-2029', $e->getMessage());
		}

		self::assertSame([], $this->store->state->saves);
	}//end testTablesThePackDoesNotDeclareAreRefused()

	/**
	 * REQ-PKU-003 Withdrawing a wrong pack: it and its tables stop resolving.
	 *
	 * @return void
	 */
	public function testADeactivatedPackNoLongerResolves(): void {
		$this->packs->upload(PackFixtures::pack(), false, PackFixtures::tables());
		$packId = array_key_first($this->store->state->objects['JurisdictionPack']);

		$result = $this->packs->deactivate((string)$packId);

		self::assertFalse($result['active']);
		$stored = $this->store->state->objects['JurisdictionPack'][$packId];
		self::assertFalse($stored['active']);
		self::assertSame('nl-2027', $stored['packId'], 'the full record is written back, not only the flag');
		self::assertSame([], RegisterSchemaValidator::errors('JurisdictionPack', $stored));
		self::assertFalse(array_values($this->store->state->objects['TaxTableSet'])[0]['active'], 'tables no active pack uses are deactivated with it');
		self::assertNull($this->packs->activePack('NL', 2027));

		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/NL 2027/');
		(new PackRepository($this->packs))->resolve('NL', '2027-02');
	}//end testADeactivatedPackNoLongerResolves()

	/**
	 * REQ-PKU-003 Tables still used by another active pack stay active.
	 *
	 * @return void
	 */
	public function testTablesAnotherActivePackUsesStayActive(): void {
		$this->packs->upload(PackFixtures::pack(), false, PackFixtures::tables());
		$second = PackFixtures::pack();
		$second['id'] = 'nl-2027-b';
		$second['packVersion'] = '1.0.1';
		$this->packs->upload($second);
		$first = array_key_first($this->store->state->objects['JurisdictionPack']);

		$this->packs->deactivate((string)$first);

		self::assertTrue(array_values($this->store->state->objects['TaxTableSet'])[0]['active']);
	}//end testTablesAnotherActivePackUsesStayActive()

	/**
	 * REQ-PKU-002 Bundled 2026 resolves to the bundled pack and tables, self-test passed.
	 *
	 * @return void
	 */
	public function testTheBundledYearResolvesToTheBundledPackAndTables(): void {
		$answer = $this->years()->resolution('NL', 2026);

		self::assertTrue($answer['resolves']);
		self::assertSame('nl-2026', $answer['packId']);
		self::assertSame('bundled', $answer['packOrigin']);
		self::assertSame('nl-2026', $answer['tablesId']);
		self::assertSame('bundled', $answer['tablesOrigin']);
		self::assertTrue($answer['selfTest']['passed']);
	}//end testTheBundledYearResolvesToTheBundledPackAndTables()

	/**
	 * REQ-PKU-002 Checking January before the first run of an uploaded year.
	 *
	 * @return void
	 */
	public function testAnUploadedYearResolvesToTheUpload(): void {
		$this->packs->upload(PackFixtures::pack(), false, PackFixtures::tables());

		$answer = $this->years()->resolution('NL', 2027);

		self::assertTrue($answer['resolves']);
		self::assertSame('nl-2027', $answer['packId']);
		self::assertSame('uploaded', $answer['packOrigin']);
		self::assertSame('nl-2027', $answer['tablesId']);
		self::assertSame('uploaded', $answer['tablesOrigin']);
		self::assertTrue($answer['selfTest']['passed']);
	}//end testAnUploadedYearResolvesToTheUpload()

	/**
	 * REQ-PKU-002 A year with nothing to pay it with.
	 *
	 * @return void
	 */
	public function testAYearWithoutAPackSaysSo(): void {
		$answer = $this->years()->resolution('NL', 2028);

		self::assertFalse($answer['resolves']);
		self::assertStringContainsString('NL 2028', $answer['message']);
	}//end testAYearWithoutAPackSaysSo()

	/**
	 * The year-transition service over the same two homes.
	 *
	 * @return YearTransitionService
	 */
	private function years(): YearTransitionService {
		return new YearTransitionService(packs: new PackRepository($this->packs), validator: new PackValidator());
	}//end years()

}//end class
