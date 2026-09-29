<?php

/**
 * HrDocumentSigningService tests
 *
 * Signing a generated HR document through filinq: signers follow the
 * document type, one request at a time, and a completed contract signature
 * marks the contract as written (people-esign-hr-documents D2, D3, D5).
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
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\FilinqSigningGateway;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HrDocumentSigningService;
use OCA\Humaniq\Service\OfferSigningRecoveryService;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Request and sync signatures on generated HR documents.
 */
class HrDocumentSigningServiceTest extends TestCase {

	/**
	 * The employee's id.
	 *
	 * @var string
	 */
	private const EMPLOYEE = '5a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	/**
	 * The contract's id.
	 *
	 * @var string
	 */
	private const CONTRACT = '6b2c3d4e-5f6a-4b7c-9d8e-0f1a2b3c4d5e';

	/**
	 * The register double.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The filinq signing double.
	 *
	 * @var object
	 */
	public object $signing;

	/**
	 * Seed an administration with a signatory, Sanne de Boer and her contract.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Voorbeeld BV', 'signatoryUserId' => 'directie']);
		$this->store->seed('Employee', self::EMPLOYEE, ['firstName' => 'Sanne', 'lastName' => 'de Boer', 'nextcloudUserId' => 's.deboer', 'administrationId' => 'ADM-001']);
		$this->store->seed('EmploymentContract', self::CONTRACT, ['employeeId' => self::EMPLOYEE, 'contractType' => 'onbepaald', 'writtenContract' => false, 'hoursPerWeek' => 36]);
		$this->signing = new class {

			/**
			 * The raised requests.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $created = [];

			/**
			 * The status getRequest() answers.
			 *
			 * @var string
			 */
			public string $status = 'PENDING';

			/**
			 * Mirror of filinq's SigningService::createRequest().
			 *
			 * @param array<string, mixed> $data The request.
			 *
			 * @return array<string, mixed>
			 */
			public function createRequest(array $data): array {
				$this->created[] = $data;
				return ['id' => 'req-' . count($this->created), 'status' => 'PENDING'];
			}//end createRequest()

			/**
			 * Mirror of filinq's SigningService::getRequest().
			 *
			 * @param string $requestId The request id.
			 *
			 * @return array<string, mixed>
			 */
			public function getRequest(string $requestId): array {
				return ['id' => $requestId, 'status' => $this->status];
			}//end getRequest()

		};
	}//end setUp()

	/**
	 * A generated document of a type.
	 *
	 * @param string               $type   The document type.
	 * @param array<string, mixed> $fields Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private function document(string $type, array $fields=[]): array {
		$document = array_merge(['documentType' => $type, 'employeeId' => self::EMPLOYEE, 'contractId' => self::CONTRACT, 'status' => 'generated', 'filePath' => 'humaniq/doc-1/' . $type . '.pdf'], $fields);
		$this->store->seed('HrGeneratedDocument', 'doc-1', $document);
		return array_merge($document, ['id' => 'doc-1']);
	}//end document()

	/**
	 * The service over filinq installed or not.
	 *
	 * @param bool $filinq Whether filinq is installed.
	 *
	 * @return HrDocumentSigningService
	 */
	private function service(bool $filinq=true): HrDocumentSigningService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('getOfferSigningDeadlineDays')->willReturn(14);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $filinq === true && $app === 'filinq');
		$files = new class {

			/**
			 * Mirror of OpenRegister's FileService::getFile().
			 *
			 * @param mixed      $object The object id.
			 * @param string|int $file   The file name.
			 *
			 * @return object|null
			 */
			public function getFile(mixed $object=null, string|int $file=''): ?object {
				return ($object === 'doc-1' && str_ends_with((string)$file, '.pdf') === true) ? new class {

					/**
					 * The file id.
					 *
					 * @return int
					 */
					public function getId(): int {
						return 4711;
					}//end getId()

				} : null;
			}//end getFile()

		};
		$container = new FakeContainer([
			'OCA\OpenRegister\Service\ObjectService' => $this->store,
			'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			'OCA\OpenRegister\Service\FileService' => $files,
			'OCA\Filinq\Service\SigningService' => $this->signing,
		]);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): ?IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			$user->method('getEMailAddress')->willReturn($uid . '@example.nl');
			return $user;
		});

		return new HrDocumentSigningService(
			signing: new FilinqSigningGateway(container: $container, appManager: $apps, recovery: new OfferSigningRecoveryService($users, new NullLogger()), logger: new NullLogger()),
			register: new HoursRegisterGateway(container: $container, settingsService: $settings, orgResolution: new OrgResolutionService()),
			container: $container,
			users: $users,
			settings: $settings
		);
	}//end service()

	/**
	 * Scenario: a new contract goes out for signing.
	 *
	 * @return void
	 */
	public function testANewContractGoesOutForSigning(): void {
		$outcome = $this->service()->requestSignature($this->document('arbeidsovereenkomst'), 'hr.adviseur');

		self::assertSame('requested', $outcome['status']);
		self::assertSame(['directie', 's.deboer'], array_column($this->signing->created[0]['signers'], 'userId'));
		self::assertSame([0, 1], array_column($this->signing->created[0]['signers'], 'order'));
		self::assertSame('4711', $this->signing->created[0]['documentFileId']);
		self::assertSame('doc-1', $this->signing->created[0]['correlationId']);

		$stored = $this->store->state->objects['HrGeneratedDocument']['doc-1'];
		self::assertSame('PENDING', $stored['signingStatus']);
		self::assertSame('req-1', $stored['signingRequestId']);
		self::assertSame('generated', $stored['status'], 'the save carries every other field');
		$stored = array_diff_key($stored, ['id' => true]);
		self::assertSame([], RegisterSchemaValidator::errors('HrGeneratedDocument', array_filter($stored, static fn ($v): bool => $v !== null)));
	}//end testANewContractGoesOutForSigning()

	/**
	 * Scenario: a werkgeversverklaring needs only the employer; without a
	 * signatory the acting HR user signs for the employer.
	 *
	 * @return void
	 */
	public function testAnEmployerStatementNeedsOnlyTheEmployer(): void {
		$this->service()->requestSignature($this->document('werkgeversverklaring'), 'hr.adviseur');
		self::assertSame(['directie'], array_column($this->signing->created[0]['signers'], 'userId'));

		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Voorbeeld BV']);
		$this->service()->requestSignature($this->document('getuigschrift'), 'hr.adviseur');
		self::assertSame(['hr.adviseur'], array_column($this->signing->created[1]['signers'], 'userId'));
	}//end testAnEmployerStatementNeedsOnlyTheEmployer()

	/**
	 * Scenario: an employee without an account is told why.
	 *
	 * @return void
	 */
	public function testAnEmployeeWithoutAnAccountIsToldWhy(): void {
		$this->store->seed('Employee', self::EMPLOYEE, ['firstName' => 'Sanne', 'lastName' => 'de Boer', 'administrationId' => 'ADM-001']);
		$outcome = $this->service()->requestSignature($this->document('arbeidsovereenkomst'), 'hr.adviseur');

		self::assertSame('failed', $outcome['status']);
		self::assertSame([], $this->signing->created);
		self::assertSame('failed', $this->store->state->objects['HrGeneratedDocument']['doc-1']['signingStatus']);
		self::assertStringContainsString('no-nextcloud-user-for-employee', (string)$this->store->state->objects['HrGeneratedDocument']['doc-1']['errorMessage']);
	}//end testAnEmployeeWithoutAnAccountIsToldWhy()

	/**
	 * A payslip or annual statement is refused; without filinq nothing is raised.
	 *
	 * @return void
	 */
	public function testAPayslipIsRefusedAndWithoutFilinqNothingIsRaised(): void {
		foreach (['loonstrook', 'jaaropgaaf'] as $type) {
			self::assertSame('refused', $this->service()->requestSignature($this->document($type), 'hr.adviseur')['status'], $type);
		}

		self::assertSame('skipped-no-docudesk', $this->service(false)->requestSignature($this->document('getuigschrift'), 'hr.adviseur')['status']);
		self::assertSame('skipped-no-docudesk', $this->store->state->objects['HrGeneratedDocument']['doc-1']['signingStatus']);
		self::assertSame([], $this->signing->created);
	}//end testAPayslipIsRefusedAndWithoutFilinqNothingIsRaised()

	/**
	 * Scenario: a double click raises one request; after a decline a new one may.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-002
	 */
	public function testADoubleClickRaisesOneRequest(): void {
		$outcome = $this->service()->requestSignature($this->document('getuigschrift', ['signingStatus' => 'IN_PROGRESS', 'signingRequestId' => 'req-old']), 'hr.adviseur');
		self::assertSame('existing', $outcome['status']);
		self::assertSame('req-old', $outcome['signingRequestId']);
		self::assertSame([], $this->signing->created);

		$this->service()->requestSignature($this->document('getuigschrift', ['signingStatus' => 'DECLINED', 'signingRequestId' => 'req-old']), 'hr.adviseur');
		self::assertCount(1, $this->signing->created);

		self::assertSame('already-signed', $this->service()->requestSignature($this->document('getuigschrift', ['signingStatus' => 'COMPLETED']), 'hr.adviseur')['status']);
	}//end testADoubleClickRaisesOneRequest()

	/**
	 * Scenario: the unemployment-fund rate check reads the signature.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
	 */
	public function testACompletedContractIsMarkedWritten(): void {
		$this->document('arbeidsovereenkomst', ['signingStatus' => 'IN_PROGRESS', 'signingRequestId' => 'req-9']);
		$this->signing->status = 'COMPLETED';

		$results = $this->service()->syncSignatures(null);

		self::assertSame('synced', $results[0]['status']);
		$contract = $this->store->state->objects['EmploymentContract'][self::CONTRACT];
		self::assertTrue($contract['writtenContract']);
		self::assertSame(36, $contract['hoursPerWeek'], 'every other contract field is carried');
		$document = $this->store->state->objects['HrGeneratedDocument']['doc-1'];
		self::assertSame('COMPLETED', $document['signingStatus']);
		self::assertSame(gmdate('Y-m-d'), substr((string)$document['signingCompletedAt'], 0, 10));
	}//end testACompletedContractIsMarkedWritten()

	/**
	 * A declined request leaves the contract as it was.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
	 */
	public function testADeclinedContractChangesNothing(): void {
		$this->document('arbeidsovereenkomst', ['signingStatus' => 'PENDING', 'signingRequestId' => 'req-9']);
		$this->signing->status = 'DECLINED';

		$this->service()->syncSignatures('doc-1');

		self::assertFalse($this->store->state->objects['EmploymentContract'][self::CONTRACT]['writtenContract']);
		self::assertSame('DECLINED', $this->store->state->objects['HrGeneratedDocument']['doc-1']['signingStatus']);
		self::assertNull($this->store->state->objects['HrGeneratedDocument']['doc-1']['signingCompletedAt'] ?? null);
	}//end testADeclinedContractChangesNothing()

}//end class
