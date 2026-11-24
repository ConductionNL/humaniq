<?php

/**
 * Employee document extraction tests
 *
 * Read document against filinq's own EmployeeDocumentExtractionService,
 * MrzParser, EmploymentContractParser and ContractTermExtractor (vendored in
 * tests/stubs/Filinq from filinq#2323) over a text stub: an empty field is
 * filled, a field HR typed is left and listed, absent filinq records
 * skipped-no-docudesk, a thrown filinq error records failed, and one read
 * contract makes at most one contract.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Humaniq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Filinq\Service\Contract\ContractTermExtractor;
use OCA\Filinq\Service\DocumentTextSource;
use OCA\Filinq\Service\EmployeeDocumentExtractionService as FilinqDocuments;
use OCA\Filinq\Service\Extraction\EmploymentContractParser;
use OCA\Filinq\Service\Extraction\MrzParser;
use OCA\Humaniq\Service\EmployeeDocumentExtractionService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Read document through the real filinq rules.
 */
class EmployeeDocumentExtractionServiceTest extends TestCase {

	/**
	 * A passport whose zone names DE BOER-JANSEN, born 1974-08-12 (check digits of the ICAO specimen).
	 */
	private const PASSPORT = "P<UTODE<BOER<JANSEN<<ANNA<MARIA<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10\n";

	/**
	 * A signed contract.
	 */
	private const CONTRACT = "Werknemer: Anna de Boer\nmet ingang van 1 juli 2026 voor bepaalde tijd\ntot en met 30-06-2027\n32 uur per week, € 18,50 bruto per uur\n";

	/**
	 * What the store saved, as [payload, schema, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: string, 2: string|null}>
	 */
	private array $saved = [];

	/**
	 * The case's extraction records.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $documents = [];

	/**
	 * The employee, as stored.
	 *
	 * @var array<string, mixed>
	 */
	private array $employee = ['id' => 'emp-1', 'firstName' => 'Anna', 'lastName' => 'de Boer', 'dateOfBirth' => null, 'bsn' => '', 'startDate' => '2026-07-01'];

	/**
	 * The service, with filinq present, absent, or throwing.
	 *
	 * @param string $filinq 'present', 'absent' or 'throws'.
	 * @param bool   $hr     Whether the caller is HR.
	 *
	 * @return EmployeeDocumentExtractionService
	 */
	private function service(string $filinq = 'present', bool $hr = true): EmployeeDocumentExtractionService {
		$source = new DocumentTextSource();
		$source->texts = [11 => self::PASSPORT, 12 => self::CONTRACT];
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-15'));
		$documents = new FilinqDocuments(textSource: $source, mrz: new MrzParser(), contract: new EmploymentContractParser(terms: new ContractTermExtractor()), time: $time);
		if ($filinq === 'throws') {
			$documents = new class {
				/**
				 * Fails as a broken filinq would.
				 *
				 * @param array<string, mixed> $data        The request.
				 * @param string               $requestedBy Who asked.
				 *
				 * @return array<string, mixed>
				 */
				public function extractEmployeeDocument(array $data, string $requestedBy): array {
					throw new \RuntimeException('OCR backend down');
				}//end extractEmployeeDocument()
			};
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($filinq, $documents): object {
				if ($filinq !== 'absent' && $id === FilinqDocuments::class) {
					return $documents;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->willReturnCallback(fn (string $schema, array $filters): array => $this->documents);
		$store->method('findObjectData')->willReturnCallback(fn (string $uuid, string $schema): ?array => ($schema === 'Employee' ? $this->employee : null));
		$store->method('save')->willReturnCallback(
			function (array $payload, string $schema, ?string $uuid = null): object {
				$this->saved[] = [$payload, $schema, $uuid];
				return new class ($uuid ?? 'new-' . count($this->saved)) {
					/**
					 * @param string $uuid The id.
					 */
					public function __construct(private readonly string $uuid) {
					}//end __construct()

					/**
					 * @return string
					 */
					public function getUuid(): string {
						return $this->uuid;
					}//end getUuid()
				};
			}
		);

		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn($hr);

		return new EmployeeDocumentExtractionService(container: $container, store: $store, roles: $roles);
	}//end service()

	/**
	 * The onboarding case.
	 *
	 * @return array<string, mixed>
	 */
	private function onboarding(): array {
		return ['id' => 'onb-1', 'employeeId' => 'emp-1', 'startDate' => '2026-07-01', 'administrationId' => 'ADM-001'];
	}//end onboarding()

	/**
	 * The saves to a schema, in order.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function savedTo(string $schema): array {
		return array_values(array_map(static fn (array $save): array => $save[0], array_filter($this->saved, static fn (array $save): bool => $save[1] === $schema)));
	}//end savedTo()

	/**
	 * "A value HR typed is never replaced": the last name stays "de Boer",
	 * listed as read but not applied; the empty date of birth is filled.
	 *
	 * @return void
	 */
	public function testAValueHrTypedIsNeverReplaced(): void {
		$this->documents = [['id' => 'doc-1', 'onboardingId' => 'onb-1', 'docType' => 'identity-document', 'documentFile' => 11, 'status' => 'pending']];

		$answer = $this->service()->read(onboarding: $this->onboarding(), userId: 'hr-demo');

		$this->assertSame('read', $answer['status']);
		[$employee] = $this->savedTo('Employee');
		$this->assertSame('de Boer', $employee['lastName']);
		$this->assertSame('1974-08-12', $employee['dateOfBirth']);
		$this->assertSame('Anna', $employee['firstName']);
		[$record] = $this->savedTo('EmployeeDocumentExtraction');
		$this->assertSame('extracted', $record['status']);
		$this->assertSame('DE BOER JANSEN', $record['extractedFields']['lastName']['value']);
		$this->assertSame(0.95, $record['extractedFields']['lastName']['confidence']);
		$this->assertSame(['dateOfBirth'], $record['appliedFields']);
		$this->assertSame(['firstName', 'lastName'], $record['notAppliedFields']);
		// ZE184226B is no BSN: the elfproef keeps it off the employee.
		$this->assertSame('', $employee['bsn']);
	}//end testAValueHrTypedIsNeverReplaced()

	/**
	 * "A signed contract fills the gaps": the record keeps the start date,
	 * hours and wage, and the contract is made once.
	 *
	 * @return void
	 */
	public function testASignedContractMakesOneContract(): void {
		$this->documents = [['id' => 'doc-2', 'onboardingId' => 'onb-1', 'docType' => 'employment-contract', 'documentFile' => ['id' => '12'], 'status' => 'pending']];
		$service = $this->service();

		$service->read(onboarding: $this->onboarding(), userId: 'hr-demo');
		[$record] = $this->savedTo('EmployeeDocumentExtraction');
		$this->assertSame('2026-07-01', $record['extractedFields']['startDate']['value']);
		$this->assertSame(32.0, $record['extractedFields']['hoursPerWeek']['value']);
		$this->assertSame(18.5, $record['extractedFields']['hourlyWage']['value']);
		$this->assertSame([], $this->savedTo('Employee'));

		$this->documents = [array_merge($record, ['id' => 'doc-2'])];
		$made = $service->contractFrom(onboarding: $this->onboarding(), userId: 'hr-demo');
		$this->assertSame('created', $made['status']);
		[$contract] = $this->savedTo('EmploymentContract');
		$this->assertSame(['employeeId' => 'emp-1', 'type' => 'temporary', 'writtenContract' => true, 'startDate' => '2026-07-01', 'endDate' => '2027-06-30', 'hoursPerWeek' => 32.0, 'hourlyWage' => 18.5, 'administrationId' => 'ADM-001'], $contract);

		$this->documents = [array_merge($record, ['id' => 'doc-2', 'contractId' => $made['contractId']])];
		$again = $service->contractFrom(onboarding: $this->onboarding(), userId: 'hr-demo');
		$this->assertSame('already-made', $again['status']);
		$this->assertCount(1, $this->savedTo('EmploymentContract'));
	}//end testASignedContractMakesOneContract()

	/**
	 * Without filinq the attempt is recorded as skipped and nothing is written.
	 *
	 * @return void
	 */
	public function testAbsentFilinqRecordsSkipped(): void {
		$this->documents = [['id' => 'doc-1', 'onboardingId' => 'onb-1', 'docType' => 'identity-document', 'documentFile' => 11, 'status' => 'pending']];

		$this->service(filinq: 'absent')->read(onboarding: $this->onboarding(), userId: 'hr-demo');

		[$record] = $this->savedTo('EmployeeDocumentExtraction');
		$this->assertSame('skipped-no-docudesk', $record['status']);
		$this->assertSame([], $this->savedTo('Employee'));
	}//end testAbsentFilinqRecordsSkipped()

	/**
	 * A thrown filinq error records failed.
	 *
	 * @return void
	 */
	public function testAThrownFilinqErrorRecordsFailed(): void {
		$this->documents = [['id' => 'doc-1', 'onboardingId' => 'onb-1', 'docType' => 'identity-document', 'documentFile' => 11, 'status' => 'pending']];

		$this->service(filinq: 'throws')->read(onboarding: $this->onboarding(), userId: 'hr-demo');

		[$record] = $this->savedTo('EmployeeDocumentExtraction');
		$this->assertSame('failed', $record['status']);
		$this->assertStringContainsString('OCR backend down', $record['message']);
		$this->assertSame([], $this->savedTo('Employee'));
	}//end testAThrownFilinqErrorRecordsFailed()

	/**
	 * A read document is not read again; with none left nothing happens.
	 *
	 * @return void
	 */
	public function testAReadDocumentIsNotReadAgain(): void {
		$this->documents = [['id' => 'doc-1', 'onboardingId' => 'onb-1', 'docType' => 'identity-document', 'documentFile' => 11, 'status' => 'extracted']];

		$answer = $this->service()->read(onboarding: $this->onboarding(), userId: 'hr-demo');

		$this->assertSame('nothing-to-read', $answer['status']);
		$this->assertSame([], $this->saved);
	}//end testAReadDocumentIsNotReadAgain()

	/**
	 * Only HR reads documents or makes a contract.
	 *
	 * @return void
	 */
	public function testOnlyHr(): void {
		$service = $this->service(hr: false);

		$this->assertSame('forbidden', $service->read(onboarding: $this->onboarding(), userId: 'employee')['status']);
		$this->assertSame('forbidden', $service->contractFrom(onboarding: $this->onboarding(), userId: 'employee')['status']);
		$this->assertSame([], $this->saved);
	}//end testOnlyHr()

	/**
	 * Without a read contract there is nothing to make a contract from.
	 *
	 * @return void
	 */
	public function testNoReadContractMakesNoContract(): void {
		$this->documents = [['id' => 'doc-1', 'onboardingId' => 'onb-1', 'docType' => 'employment-contract', 'status' => 'pending']];

		$this->assertSame('no-document', $this->service()->contractFrom(onboarding: $this->onboarding(), userId: 'hr-demo')['status']);
		$this->assertSame([], $this->saved);
	}//end testNoReadContractMakesNoContract()
}//end class
