<?php

/**
 * RegisterPrefillService tests
 *
 * Fill a company car from the RDW vehicle register and an employee from the
 * BRP, through integriq, writing only empty fields and listing every field
 * where the register differs (people-register-prefill). The RDW rows are a
 * recorded answer of the open data API for plate GZS78Z (29 Sep 2026); the
 * BRP answer follows Haal Centraal BRP Personen bevragen v2.
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
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\BrpPersonMapper;
use OCA\Humaniq\Service\RdwVehicleMapper;
use OCA\Humaniq\Service\RegisterLookupGateway;
use OCA\Humaniq\Service\RegisterPrefillService;
use OCA\Humaniq\Service\RegisterPrefillUnavailableException;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Prefill from the RDW and the BRP.
 */
class RegisterPrefillServiceTest extends TestCase {

	/**
	 * The calls the call-service double received.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * The recorded RDW vehicle row for GZS78Z (trimmed to the fields read).
	 *
	 * @var array<string, string>
	 */
	private const RDW_VEHICLE = [
		'kenteken' => 'GZS78Z',
		'voertuigsoort' => 'Personenauto',
		'merk' => 'TOYOTA',
		'handelsbenaming' => 'TOYOTA COROLLA',
		'datum_eerste_toelating' => '20250115',
		'catalogusprijs' => '42262',
	];

	/**
	 * The recorded RDW fuel rows for GZS78Z: a hybrid.
	 *
	 * @var list<array<string, string>>
	 */
	private const RDW_FUEL = [
		['kenteken' => 'GZS78Z', 'brandstof_volgnummer' => '1', 'brandstof_omschrijving' => 'Elektriciteit'],
		['kenteken' => 'GZS78Z', 'brandstof_volgnummer' => '2', 'brandstof_omschrijving' => 'Benzine'],
	];

	/**
	 * A BRP Personen answer for one person.
	 *
	 * @var array<string, mixed>
	 */
	private const BRP_ANSWER = [
		'type' => 'RaadpleegMetBurgerservicenummer',
		'personen' => [
			[
				'burgerservicenummer' => '999993653',
				'naam' => ['voornamen' => 'Suzanne', 'voorvoegsel' => 'van', 'geslachtsnaam' => 'Dijk'],
				'geboorte' => ['datum' => ['type' => 'Datum', 'datum' => '1985-04-12']],
				'verblijfplaats' => [
					'type' => 'Adres',
					'verblijfadres' => [
						'officieleStraatnaam' => 'Lange Voorhout',
						'korteStraatnaam' => 'Lange Voorhout',
						'huisnummer' => 12,
						'huisletter' => 'a',
						'huisnummertoevoeging' => '2',
						'postcode' => '2514EE',
						'woonplaats' => '\'s-Gravenhage',
					],
				],
			],
		],
	];

	/**
	 * The service over an instance with or without integriq and sources.
	 *
	 * @param bool                            $installed Whether integriq is installed.
	 * @param array<string, string>           $sources   Configured source per register.
	 * @param array<string, array<mixed>|int> $answers   Response body (or status code) per endpoint.
	 *
	 * @return RegisterPrefillService
	 */
	private function service(bool $installed, array $sources, array $answers=[]): RegisterPrefillService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'integriq');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterPrefillSource')->willReturnCallback(static fn (string $register): string => ($sources[$register] ?? ''));

		$environment = new class {

			/**
			 * Mirror of integriq's EnvironmentService::resolveSource().
			 *
			 * @param string $sourceRef The source uuid.
			 *
			 * @return ObjectEntity|null
			 */
			public function resolveSource(string $sourceRef): ?ObjectEntity {
				if (str_starts_with($sourceRef, 'src-') === false) {
					return null;
				}

				$source = new ObjectEntity();
				$source->setUuid($sourceRef);
				return $source;
			}//end resolveSource()

		};
		$call = new class ($this, $answers) {

			/**
			 * Constructor.
			 *
			 * @param RegisterPrefillServiceTest      $test    Collects the calls.
			 * @param array<string, array<mixed>|int> $answers Body or status per endpoint.
			 */
			public function __construct(private RegisterPrefillServiceTest $test, private array $answers) {
			}//end __construct()

			/**
			 * Mirror of integriq's CallService::call().
			 *
			 * @param ObjectEntity         $source       The source.
			 * @param string               $endpoint     The path.
			 * @param string               $method       The verb.
			 * @param array<string, mixed> $config       The request config.
			 * @param bool                 $asynchronous Unused.
			 * @param bool                 $createCerts  Unused.
			 * @param bool                 $overruleAuth Unused.
			 * @param bool                 $read         Unused.
			 * @param bool                 $support      Unused.
			 * @param mixed                $sink         Unused.
			 * @param mixed                $trace        Unused.
			 * @param bool                 $persistLog   Whether a CallLog is written.
			 *
			 * @return ObjectEntity The CallLog.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter,PHPMD.ExcessiveParameterList,PHPMD.BooleanArgumentFlag)
			 */
			public function call(
				ObjectEntity $source,
				string $endpoint='',
				string $method='GET',
				array $config=[],
				bool $asynchronous=false,
				bool $createCerts=true,
				bool $overruleAuth=false,
				bool $read=false,
				bool $support=false,
				mixed $sink=null,
				mixed $trace=null,
				bool $persistLog=true
			): ObjectEntity {
				$this->test->calls[] = ['source' => $source->getUuid(), 'endpoint' => $endpoint, 'method' => $method, 'config' => $config, 'persistLog' => $persistLog];
				$answer = ($this->answers[$endpoint] ?? []);
				$response = is_int($answer) === true ? ['statusCode' => $answer, 'body' => ''] : ['statusCode' => 200, 'body' => json_encode($answer)];
				$log = new ObjectEntity();
				$log->setObject(['response' => $response]);
				return $log;
			}//end call()

		};

		return new RegisterPrefillService(
			gateway: new RegisterLookupGateway(
				container: new FakeContainer(['OCA\Integriq\Service\EnvironmentService' => $environment, 'OCA\Integriq\Service\CallService' => $call]),
				appManager: $apps,
				settings: $settings
			),
			rdw: new RdwVehicleMapper(),
			brp: new BrpPersonMapper()
		);
	}//end service()

	/**
	 * The RDW answers for GZS78Z.
	 *
	 * @return array<string, array<mixed>>
	 */
	private function rdwAnswers(): array {
		return [RegisterPrefillService::RDW_VEHICLE_ENDPOINT => [self::RDW_VEHICLE], RegisterPrefillService::RDW_FUEL_ENDPOINT => self::RDW_FUEL];
	}//end rdwAnswers()

	/**
	 * Scenario: a new lease car is filled from its plate.
	 *
	 * @return void
	 */
	public function testANewLeaseCarIsFilledFromItsPlate(): void {
		$asset = ['name' => 'Lease car', 'category' => 'vehicle', 'status' => 'available', 'licencePlate' => 'gz-s78-z'];
		$result = $this->service(true, ['rdw' => 'src-rdw'], $this->rdwAnswers())->vehicle($asset);

		self::assertSame(
			['make' => 'TOYOTA', 'model' => 'TOYOTA COROLLA', 'firstAdmissionDate' => '2025-01-15', 'listPrice' => 42262.0, 'fuelType' => 'hybrid'],
			$result['filled']
		);
		self::assertSame([], $result['differs']);
		self::assertSame('GZS78Z', $this->calls[0]['config']['query']['kenteken']);
		self::assertSame('src-rdw', $this->calls[0]['source']);

		// The payload the controller saves lands in the real Asset schema.
		self::assertSame([], RegisterSchemaValidator::errors('Asset', array_merge($asset, $result['filled'])));
	}//end testANewLeaseCarIsFilledFromItsPlate()

	/**
	 * Scenario: a stored catalogue value is not overwritten.
	 *
	 * @return void
	 */
	public function testAStoredCatalogueValueIsNotOverwritten(): void {
		$asset = ['category' => 'vehicle', 'licencePlate' => 'GZS78Z', 'listPrice' => 41000, 'make' => 'TOYOTA'];
		$result = $this->service(true, ['rdw' => 'src-rdw'], $this->rdwAnswers())->vehicle($asset);

		self::assertArrayNotHasKey('listPrice', $result['filled']);
		self::assertArrayNotHasKey('make', $result['filled']);
		self::assertSame([['field' => 'listPrice', 'stored' => 41000, 'register' => 42262.0]], $result['differs']);
	}//end testAStoredCatalogueValueIsNotOverwritten()

	/**
	 * A plate the register does not know, a non-vehicle, or no plate is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownPlateOrANonVehicleIsRefused(): void {
		$cases = [
			[['category' => 'vehicle', 'licencePlate' => 'AB-12-CD'], [RegisterPrefillService::RDW_VEHICLE_ENDPOINT => []]],
			[['category' => 'laptop', 'licencePlate' => 'GZS78Z'], $this->rdwAnswers()],
			[['category' => 'vehicle', 'licencePlate' => ''], $this->rdwAnswers()],
		];
		foreach ($cases as [$asset, $answers]) {
			try {
				$this->service(true, ['rdw' => 'src-rdw'], $answers)->vehicle($asset);
				self::fail('Expected a refusal.');
			} catch (RegisterPrefillUnavailableException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}
	}//end testAnUnknownPlateOrANonVehicleIsRefused()

	/**
	 * Without integriq, without a source or on an error answer nothing is filled.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqOrASourceNothingIsFilled(): void {
		$asset = ['category' => 'vehicle', 'licencePlate' => 'GZS78Z'];
		$cases = [
			'skipped-no-integriq' => [false, ['rdw' => 'src-rdw'], $this->rdwAnswers()],
			'no-source' => [true, [], $this->rdwAnswers()],
			'error' => [true, ['rdw' => 'src-rdw'], [RegisterPrefillService::RDW_VEHICLE_ENDPOINT => 503]],
		];
		foreach ($cases as $reason => [$installed, $sources, $answers]) {
			try {
				$this->service($installed, $sources, $answers)->vehicle($asset);
				self::fail('Expected a refusal: ' . $reason);
			} catch (RegisterPrefillUnavailableException $e) {
				self::assertSame($reason, $e->getReason());
			}
		}
	}//end testWithoutIntegriqOrASourceNothingIsFilled()

	/**
	 * The fuel rows map to the Asset enum.
	 *
	 * @return void
	 */
	public function testTheFuelRowsMapToTheFuelType(): void {
		$mapper = new RdwVehicleMapper();
		$cases = [
			'gasoline' => ['Benzine'],
			'diesel' => ['Diesel'],
			'fullyElectric' => ['Elektriciteit'],
			'hydrogen' => ['Waterstof'],
			'hybrid' => ['Elektriciteit', 'Benzine'],
			'other' => ['LPG'],
		];
		foreach ($cases as $expected => $fuels) {
			$rows = array_map(static fn (string $fuel): array => ['brandstof_omschrijving' => $fuel], $fuels);
			self::assertSame($expected, $mapper->map(self::RDW_VEHICLE, $rows)['fuelType'], implode('+', $fuels));
		}

		self::assertArrayNotHasKey('fuelType', $mapper->map(self::RDW_VEHICLE, []));
	}//end testTheFuelRowsMapToTheFuelType()

	/**
	 * Scenario: a municipality fills a new employee's address.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
	 */
	public function testAMunicipalityFillsANewEmployeesAddress(): void {
		$employee = ['employeeNumber' => 'E-9', 'bsn' => '999993653', 'firstName' => 'Suzanne', 'lastName' => 'Dijk'];
		$result = $this->service(true, ['brp' => 'src-brp'], [RegisterPrefillService::BRP_ENDPOINT => self::BRP_ANSWER])->employee($employee);

		self::assertSame(
			['dateOfBirth' => '1985-04-12', 'straat' => 'Lange Voorhout', 'huisnummer' => '12a-2', 'postcode' => '2514 EE', 'woonplaats' => '\'s-Gravenhage'],
			$result['filled']
		);
		self::assertSame([['field' => 'lastName', 'stored' => 'Dijk', 'register' => 'van Dijk']], $result['differs']);

		// The BSN goes in the POST body to the BRP source only, and no CallLog keeps it.
		self::assertSame('POST', $this->calls[0]['method']);
		self::assertFalse($this->calls[0]['persistLog']);
		self::assertStringContainsString('999993653', (string)$this->calls[0]['config']['body']);
		self::assertArrayNotHasKey('query', $this->calls[0]['config']);
	}//end testAMunicipalityFillsANewEmployeesAddress()

	/**
	 * An employee without a BSN, or one the BRP does not know, is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
	 */
	public function testAnEmployeeWithoutABsnOrUnknownIsRefused(): void {
		foreach ([['bsn' => ''], ['bsn' => '999993653']] as $employee) {
			try {
				$this->service(true, ['brp' => 'src-brp'], [RegisterPrefillService::BRP_ENDPOINT => ['personen' => []]])->employee($employee);
				self::fail('Expected a refusal.');
			} catch (RegisterPrefillUnavailableException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}
	}//end testAnEmployeeWithoutABsnOrUnknownIsRefused()

}//end class
