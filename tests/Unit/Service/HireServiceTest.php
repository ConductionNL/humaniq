<?php

/**
 * From a hired application to an employee and an onboarding case, and back
 * onto a former employee's record when the person returns.
 *
 * Every payload is validated against the register fragment it is saved into,
 * because OpenRegister drops what a schema does not declare.
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
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HireMatchService;
use OCA\Humaniq\Service\HireService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Create, link, stay idempotent, and attach without a duplicate.
 */
class HireServiceTest extends TestCase {

	private const EMPLOYEE_UUID = '0f7d3c1e-5b52-4a8e-9a55-2d0f0b7a6c01';
	private const ONBOARDING_UUID = '9b0e6a52-1c3d-4f7e-8a90-6d5e4c3b2a10';
	private const VACANCY_UUID = '4c1b2a3d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/** @var array<string, array<string, mixed>> */
	private array $store = [];

	/** @var list<array{payload: array<string, mixed>, schema: string, uuid: ?string}> */
	private array $saved = [];

	/** @var list<array<string, mixed>> */
	private array $employees = [];

	private HoursRegisterGateway&MockObject $gateway;

	protected function setUp(): void {
		$this->store = [
			'app-sanne' => [
				'id' => 'app-sanne',
				'@self' => ['id' => 'app-sanne'],
				'vacancyId' => self::VACANCY_UUID,
				'candidateName' => 'Sanne de Boer',
				'email' => 'sanne.deboer@example.org',
				'phone' => '+31 6 12345678',
				'status' => 'aangenomen',
				'talentPoolOptIn' => false,
				'administrationId' => 'ADM-001',
			],
			'app-offer' => ['id' => 'app-offer', 'vacancyId' => self::VACANCY_UUID, 'candidateName' => 'Tom Aanbod', 'email' => 'tom@example.org', 'status' => 'aanbod'],
			'emp-smit' => ['id' => 'emp-smit', 'firstName' => 'Pieter', 'lastName' => 'Smit', 'dateOfBirth' => '1985-03-03', 'startDate' => '2015-01-01', 'endDate' => '2023-06-30', 'bsn' => '111222333', 'administrationId' => 'ADM-001'],
			'emp-active' => ['id' => 'emp-active', 'firstName' => 'Kim', 'lastName' => 'Bos', 'startDate' => '2020-01-01', 'endDate' => null],
		];
		$this->employees = [$this->store['emp-smit'], $this->store['emp-active']];

		$this->gateway = $this->createMock(HoursRegisterGateway::class);
		$this->gateway->method('findObjectData')->willReturnCallback(fn (string $id): ?array => ($this->store[$id] ?? null));
		$this->gateway->method('loadAll')->willReturnCallback(fn (string $schema): array => ($schema === 'Employee' ? $this->employees : []));
		$this->gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null): object {
			$this->saved[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid];
			$id = ($uuid ?? ($schema === 'Employee' ? self::EMPLOYEE_UUID : self::ONBOARDING_UUID));
			if ($schema === 'job-application' && $uuid !== null) {
				$this->store[$uuid] = array_merge($payload, ['id' => $uuid]);
			}

			return new class($id) {
				/**
				 * @param string $id The saved uuid.
				 */
				public function __construct(private readonly string $id) {
				}

				/**
				 * @return string
				 */
				public function getUuid(): string {
					return $this->id;
				}
			};
		});
	}//end setUp()

	/**
	 * The service under test, with the real match service.
	 *
	 * @return HireService
	 */
	private function service(): HireService {
		return new HireService($this->gateway, new HireMatchService($this->gateway));
	}//end service()

	/**
	 * The saves of one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array{payload: array<string, mixed>, schema: string, uuid: ?string}>
	 */
	private function savesOf(string $schema): array {
		return array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === $schema));
	}//end savesOf()

	/**
	 * Every field written is declared, and the payload is valid for the schema.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return void
	 */
	private static function assertFits(string $schema, array $payload): void {
		$declared = RegisterSchemaValidator::schema($schema);
		self::assertSame([], array_values(array_diff(array_keys($payload), array_keys($declared['properties']))), 'Every field written is a ' . $schema . ' property; OpenRegister drops the others.');
		self::assertSame([], RegisterSchemaValidator::errors($schema, $payload));
	}//end assertFits()

	/**
	 * The proposed split keeps a Dutch prefix with the last name.
	 *
	 * @return void
	 */
	public function testTheProposedNameKeepsThePrefixWithTheLastName(): void {
		self::assertSame(['firstName' => 'Sanne', 'lastName' => 'de Boer'], HireService::proposedName('Sanne de Boer'));
		self::assertSame(['firstName' => '', 'lastName' => 'Cher'], HireService::proposedName(' Cher '));
	}//end testTheProposedNameKeepsThePrefixWithTheLastName()

	/**
	 * An HR adviser hires without typing the name again: an employee with the
	 * application's contact details, an onboarding case at aangenomen, and the
	 * application linked with its status unchanged.
	 *
	 * @return void
	 */
	public function testAHireCreatesTheEmployeeTheCaseAndTheLink(): void {
		$result = $this->service()->hire('app-sanne', ['startDate' => '2026-11-01', 'firstName' => 'Sanne', 'lastName' => 'de Boer', 'dateOfBirth' => '1994-07-12']);

		self::assertSame('created', $result['outcome']);
		self::assertSame(self::EMPLOYEE_UUID, $result['employeeId']);
		self::assertSame(self::ONBOARDING_UUID, $result['onboardingId']);

		$employee = $this->savesOf('Employee');
		self::assertCount(1, $employee);
		self::assertNull($employee[0]['uuid']);
		self::assertSame('sanne.deboer@example.org', $employee[0]['payload']['privateEmail']);
		self::assertSame('+31 6 12345678', $employee[0]['payload']['phone']);
		self::assertSame('2026-11-01', $employee[0]['payload']['startDate']);
		self::assertSame('ADM-001', $employee[0]['payload']['administrationId']);
		self::assertFits('Employee', $employee[0]['payload']);

		$case = $this->savesOf('Onboarding');
		self::assertCount(1, $case);
		self::assertSame(['employeeId' => self::EMPLOYEE_UUID, 'startDate' => '2026-11-01', 'status' => 'aangenomen', 'administrationId' => 'ADM-001'], $case[0]['payload']);
		self::assertFits('Onboarding', $case[0]['payload']);

		$link = $this->savesOf('job-application');
		self::assertCount(1, $link);
		self::assertSame('app-sanne', $link[0]['uuid']);
		self::assertSame('aangenomen', $link[0]['payload']['status'], 'The status is carried unchanged, so no transition fires.');
		self::assertSame(self::EMPLOYEE_UUID, $link[0]['payload']['employeeId']);
		self::assertSame('Sanne de Boer', $link[0]['payload']['candidateName'], 'OpenRegister saves are a full replace: the whole application is carried.');
		self::assertArrayNotHasKey('@self', $link[0]['payload']);
		self::assertArrayNotHasKey('id', $link[0]['payload']);
		self::assertFits('job-application', $link[0]['payload']);
	}//end testAHireCreatesTheEmployeeTheCaseAndTheLink()

	/**
	 * A double click creates one employee: the second call writes nothing and
	 * names the linked employee.
	 *
	 * @return void
	 */
	public function testASecondCallCreatesNothing(): void {
		$service = $this->service();
		$service->hire('app-sanne', ['startDate' => '2026-11-01', 'firstName' => 'Sanne', 'lastName' => 'de Boer']);
		$written = count($this->saved);

		$again = $service->hire('app-sanne', ['startDate' => '2026-11-01', 'firstName' => 'Sanne', 'lastName' => 'de Boer', 'createNew' => true]);

		self::assertSame('already-linked', $again['outcome']);
		self::assertSame(self::EMPLOYEE_UUID, $again['employeeId']);
		self::assertCount($written, $this->saved);
	}//end testASecondCallCreatesNothing()

	/**
	 * Matches stop the hire until HR chooses.
	 *
	 * @return void
	 */
	public function testMatchesStopTheHireUntilHrChooses(): void {
		$this->store['app-sanne']['candidateName'] = 'Pieter Smit';

		$result = $this->service()->hire('app-sanne', ['startDate' => '2026-11-01', 'firstName' => 'Pieter', 'lastName' => 'Smit', 'dateOfBirth' => '1985-03-03']);

		self::assertSame('matches', $result['outcome']);
		self::assertSame('emp-smit', $result['matches'][0]['employeeId']);
		self::assertSame('name-and-birth-date', $result['matches'][0]['matchedOn']);
		self::assertSame([], $this->saved);
	}//end testMatchesStopTheHireUntilHrChooses()

	/**
	 * HR can create a new record on purpose despite a match.
	 *
	 * @return void
	 */
	public function testCreateNewOverridesTheMatches(): void {
		$result = $this->service()->hire('app-sanne', ['startDate' => '2026-11-01', 'lastName' => 'Smit', 'dateOfBirth' => '1985-03-03', 'createNew' => true]);

		self::assertSame('created', $result['outcome']);
		self::assertCount(1, $this->savesOf('Employee'));
	}//end testCreateNewOverridesTheMatches()

	/**
	 * A former employee comes back: the end date clears, the start date moves,
	 * a new case starts on that record and no contract is touched.
	 *
	 * @return void
	 */
	public function testAttachingToAFormerEmployeeReopensTheRecord(): void {
		$result = $this->service()->hire('app-sanne', ['startDate' => '2026-11-01', 'lastName' => 'Smit', 'attachToEmployeeId' => 'emp-smit']);

		self::assertSame('attached', $result['outcome']);
		self::assertSame('emp-smit', $result['employeeId']);

		$employee = $this->savesOf('Employee');
		self::assertCount(1, $employee);
		self::assertSame('emp-smit', $employee[0]['uuid']);
		self::assertNull($employee[0]['payload']['endDate']);
		self::assertSame('2026-11-01', $employee[0]['payload']['startDate']);
		self::assertSame('Pieter', $employee[0]['payload']['firstName'], 'The rest of the record is carried unchanged.');
		self::assertSame('111222333', $employee[0]['payload']['bsn']);
		self::assertArrayNotHasKey('id', $employee[0]['payload']);
		self::assertFits('Employee', $employee[0]['payload']);

		self::assertSame('emp-smit', $this->savesOf('Onboarding')[0]['payload']['employeeId']);
		self::assertSame('emp-smit', $this->savesOf('job-application')[0]['payload']['employeeId']);
		self::assertSame([], $this->savesOf('EmploymentContract'));
	}//end testAttachingToAFormerEmployeeReopensTheRecord()

	/**
	 * An internal move onto an active employee changes no dates: only the case
	 * and the link are written.
	 *
	 * @return void
	 */
	public function testAttachingToAnActiveEmployeeWritesNoDates(): void {
		$result = $this->service()->hire('app-sanne', ['startDate' => '2026-11-01', 'lastName' => 'Bos', 'attachToEmployeeId' => 'emp-active']);

		self::assertSame('attached', $result['outcome']);
		self::assertSame([], $this->savesOf('Employee'));
		self::assertCount(1, $this->savesOf('Onboarding'));
	}//end testAttachingToAnActiveEmployeeWritesNoDates()

	/**
	 * Only a hired application becomes an employee, a start date and a last
	 * name are needed, and an unknown record cannot be attached to.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame('not-hired', $this->service()->hire('app-offer', ['startDate' => '2026-11-01', 'lastName' => 'Aanbod'])['outcome']);
		self::assertSame('not-found', $this->service()->hire('app-none', ['startDate' => '2026-11-01', 'lastName' => 'X'])['outcome']);
		self::assertSame('invalid', $this->service()->hire('app-sanne', ['startDate' => '1 november', 'lastName' => 'de Boer'])['outcome']);
		self::assertSame('invalid', $this->service()->hire('app-sanne', ['startDate' => '2026-11-01', 'lastName' => ' '])['outcome']);
		self::assertSame('invalid', $this->service()->hire('app-sanne', ['startDate' => '2026-11-01', 'lastName' => 'X', 'attachToEmployeeId' => 'emp-none'])['outcome']);
		self::assertSame([], $this->saved);
	}//end testRefusals()

}//end class
