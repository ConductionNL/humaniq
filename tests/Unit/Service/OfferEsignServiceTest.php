<?php

/**
 * Unit tests for OfferEsignService.
 *
 * Pins the offer-esign contract: the aanbod-stage guard (REQ-OFFR-002), the
 * duck-typed skip when docudesk is absent and its stability across repeated
 * calls (REQ-OFFR-004), the dataRefs/adHocData assembly with no Employee ref
 * (REQ-OFFR-002/D3), the real Nextcloud file id captured via `File::getId()`
 * (REQ-OFFR-002), the VERIFIED real `signers` field shape (never a fabricated
 * `signerIds`) plus the provenance fields threaded into `createRequest()`
 * (REQ-OFFR-003), the CLI-session gap (`RuntimeException('No authenticated
 * user')`) surfacing as a `failed` outcome and never escaping uncaught
 * (REQ-OFFR-003/D5), the single-slot idempotency pre-check
 * (supersede/no-op/retry, REQ-OFFR-005), the no-auto-hire boundary
 * (REQ-OFFR-006 -- `Application.status` is never written, not even on
 * COMPLETED), and the PUT-semantic guard (a partial offer-esign write always
 * carries `status` and every other existing field forward unchanged). Drives
 * the service through fake ObjectService/FileService/DocumentService/
 * TemplateService/SigningService doubles (fake collaborators, not fakes of
 * the service logic under test) since the real OpenRegister/docudesk
 * services are sibling-app dependencies not available in this standalone
 * suite -- mirrors the `HrDocumentServiceTest` pattern.
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
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\OfferApplicationRepository;
use OCA\Humaniq\Service\OfferEsignService;
use OCA\Humaniq\Service\OfferLetterService;
use OCA\Humaniq\Service\OfferSigningRecoveryService;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for OfferEsignService.
 *
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md
 */
class OfferEsignServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double: `find()` resolves one row by id
	 * from the seeded rows, `setRegister()->setSchema()->findAll()` returns
	 * every seeded row for the current schema (the sync-all backlog scan),
	 * and `saveObject()` records every write and reflects it back into the
	 * seeded rows so a subsequent lookup within the same test sees it.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
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
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				private array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $id The object id.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): ?array {
				foreach (($this->rowsBySchema[$schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

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

		};

	}//end fakeObjectService()

	/**
	 * Build a fake docudesk DocumentService double.
	 *
	 * @param bool $throws Whether generateDocument() throws.
	 * @param string|null $content The returned PDF content, or null for empty (simulates a bad render).
	 *
	 * @return object
	 */
	private function fakeDocumentService(bool $throws = false, ?string $content = '%PDF-1.4 fake'): object {
		return new class($throws, $content) {
			/**
			 * Every generateDocument() call, as `['templateId' => ..., 'dataRefs' => ..., 'options' => ...]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param bool $throws Whether generateDocument() throws.
			 * @param string|null $content The returned PDF content.
			 */
			public function __construct(
				private readonly bool $throws,
				private readonly ?string $content,
			) {

			}//end __construct()

			/**
			 * @param string $templateId The template id.
			 * @param array<int, mixed> $dataRefs The data refs.
			 * @param array<string, mixed> $options The render options.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws \RuntimeException When simulating a docudesk render failure.
			 */
			public function generateDocument(string $templateId, array $dataRefs, array $options): array {
				$this->calls[] = ['templateId' => $templateId, 'dataRefs' => $dataRefs, 'options' => $options];

				if ($this->throws === true) {
					throw new \RuntimeException('docudesk render failed');
				}

				return ['content' => ($this->content ?? ''), 'format' => 'pdf', 'metadata' => [], 'warnings' => []];
			}//end generateDocument()

		};

	}//end fakeDocumentService()

	/**
	 * Build a fake docudesk TemplateService double.
	 *
	 * @param array<int, array<string, mixed>> $templates Templates returned by getTemplatesByNamespace().
	 * @param bool $throws Whether getTemplatesByNamespace() throws.
	 *
	 * @return object
	 */
	private function fakeTemplateService(array $templates = [], bool $throws = false): object {
		return new class($templates, $throws) {
			/**
			 * @param array<int, array<string, mixed>> $templates Templates.
			 * @param bool $throws Whether the call throws.
			 */
			public function __construct(
				private readonly array $templates,
				private readonly bool $throws,
			) {

			}//end __construct()

			/**
			 * @param string $namespace The template namespace.
			 *
			 * @return array<int, array<string, mixed>>
			 *
			 * @throws \RuntimeException When simulating an unresolvable template register.
			 */
			public function getTemplatesByNamespace(string $namespace): array {
				if ($this->throws === true) {
					throw new \RuntimeException('template register unavailable');
				}

				return $this->templates;
			}//end getTemplatesByNamespace()

		};

	}//end fakeTemplateService()

	/**
	 * Build a fake OpenRegister FileService double whose addFile() returns
	 * an object exposing getId() -- the real Nextcloud file id
	 * (REQ-OFFR-002), not merely a path.
	 *
	 * @param bool $throws Whether addFile() throws.
	 * @param int $fileId The file id the fake File node returns from getId().
	 *
	 * @return object
	 */
	private function fakeFileService(bool $throws = false, int $fileId = 42): object {
		return new class($throws, $fileId) {
			/**
			 * Every addFile() call, as `[objectEntity, fileName, content]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param bool $throws Whether addFile() throws.
			 * @param int $fileId The file id to return.
			 */
			public function __construct(
				private readonly bool $throws,
				private readonly int $fileId,
			) {

			}//end __construct()

			/**
			 * @param mixed $objectEntity The object id/entity to attach the file to.
			 * @param string $fileName The file name.
			 * @param string $content The file content.
			 *
			 * @return object A fake File with a getId() method.
			 *
			 * @throws \RuntimeException When simulating a storage failure.
			 */
			public function addFile(mixed $objectEntity, string $fileName, string $content): object {
				$this->calls[] = ['objectEntity' => $objectEntity, 'fileName' => $fileName, 'content' => $content];

				if ($this->throws === true) {
					throw new \RuntimeException('storage failed');
				}

				return new class($this->fileId) {
					public function __construct(
						private readonly int $id,
					) {

					}//end __construct()

					public function getId(): int {
						return $this->id;
					}//end getId()

				};

			}//end addFile()

		};

	}//end fakeFileService()

	/**
	 * Build a fake docudesk SigningService double -- the VERIFIED real
	 * contract (design.md Context): `createRequest()` and `cancelRequest()`
	 * can simulate the D5 "No authenticated user" CLI-session gap;
	 * `getRequest()`/`listRequests()` never have that guard, mirroring the
	 * real service.
	 *
	 * @param bool $createThrows Whether createRequest() throws RuntimeException('No authenticated user').
	 * @param array<string, mixed> $createResponse The array createRequest() returns on success.
	 * @param bool $cancelThrows Whether cancelRequest() throws.
	 * @param array<string, array<string, mixed>> $requestsById Records getRequest() can resolve, keyed by id.
	 * @param array<int, array<string, mixed>> $listRequestsResult The array listRequests() returns (defect-3 orphan-recovery lookup).
	 * @param bool $listRequestsThrows Whether listRequests() throws.
	 *
	 * @return object
	 */
	private function fakeSigningService(
		bool $createThrows = false,
		array $createResponse = ['id' => 'req-1', 'status' => 'PENDING'],
		bool $cancelThrows = false,
		array $requestsById = [],
		array $listRequestsResult = [],
		bool $listRequestsThrows = false,
	): object {
		return new class($createThrows, $createResponse, $cancelThrows, $requestsById, $listRequestsResult, $listRequestsThrows) {
			/**
			 * Every createRequest() call's $data argument.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $createCalls = [];

			/**
			 * Every cancelRequest() call's requestId argument.
			 *
			 * @var array<int, string>
			 */
			public array $cancelCalls = [];

			/**
			 * Every listRequests() call's [callerUserId, isAdmin] argument pair.
			 *
			 * @var array<int, array{0: string, 1: bool}>
			 */
			public array $listRequestsCalls = [];

			/**
			 * @param bool $createThrows Whether createRequest() throws.
			 * @param array<string, mixed> $createResponse The success return value.
			 * @param bool $cancelThrows Whether cancelRequest() throws.
			 * @param array<string, array<string, mixed>> $requestsById getRequest() lookup table.
			 * @param array<int, array<string, mixed>> $listRequestsResult listRequests() return value.
			 * @param bool $listRequestsThrows Whether listRequests() throws.
			 */
			public function __construct(
				private readonly bool $createThrows,
				private readonly array $createResponse,
				private readonly bool $cancelThrows,
				private readonly array $requestsById,
				private readonly array $listRequestsResult,
				private readonly bool $listRequestsThrows,
			) {

			}//end __construct()

			/**
			 * @param string $callerUserId UID of the calling user ('' = skip check).
			 * @param bool $isAdmin True when the caller is an NC admin.
			 *
			 * @return array<int, array<string, mixed>>
			 *
			 * @throws \RuntimeException Simulating a listRequests() failure.
			 */
			public function listRequests(string $callerUserId = '', bool $isAdmin = false): array {
				$this->listRequestsCalls[] = [$callerUserId, $isAdmin];

				if ($this->listRequestsThrows === true) {
					throw new \RuntimeException('listRequests unavailable');
				}

				return $this->listRequestsResult;
			}//end listRequests()

			/**
			 * @param array<string, mixed> $data The signing request data.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws \RuntimeException Simulating the D5 no-session CLI gap.
			 */
			public function createRequest(array $data): array {
				$this->createCalls[] = $data;

				if ($this->createThrows === true) {
					throw new \RuntimeException('No authenticated user');
				}

				return $this->createResponse;
			}//end createRequest()

			/**
			 * @param string $requestId The signing request id.
			 *
			 * @return array<string, mixed>|null
			 *
			 * @throws \RuntimeException Simulating the D5 no-session CLI gap.
			 */
			public function cancelRequest(string $requestId): ?array {
				$this->cancelCalls[] = $requestId;

				if ($this->cancelThrows === true) {
					throw new \RuntimeException('No authenticated user');
				}

				return ['id' => $requestId, 'status' => 'CANCELLED'];
			}//end cancelRequest()

			/**
			 * @param string $requestId The signing request id.
			 * @param string $callerUserId Unused by the fake.
			 * @param bool $isAdmin Unused by the fake.
			 *
			 * @return array<string, mixed>|null
			 *
			 * @throws \RuntimeException When the id is not in the seeded lookup table (real not-found contract).
			 */
			public function getRequest(string $requestId, string $callerUserId = '', bool $isAdmin = false): ?array {
				if (array_key_exists($requestId, $this->requestsById) === false) {
					throw new \RuntimeException('Signing request not found');
				}

				return $this->requestsById[$requestId];
			}//end getRequest()

		};

	}//end fakeSigningService()

	/**
	 * Build a fake `IUserManager` double whose `getByEmail()` resolves the
	 * given email->uid map -- the defect-2 fix's resolution collaborator.
	 * Any email not in the map resolves to an empty array (no user found).
	 *
	 * @param array<string, string> $emailToUid Known email -> Nextcloud uid pairs.
	 *
	 * @return IUserManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function fakeUserManager(array $emailToUid = ['sanne.voorbeeld@example.org' => 'sanne-nc']): IUserManager {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->willReturnCallback(
			function (string $email) use ($emailToUid): array {
				if (isset($emailToUid[$email]) === false) {
					return [];
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($emailToUid[$email]);

				return [$user];
			}
		);

		return $userManager;
	}//end fakeUserManager()

	/**
	 * Build a fully-wired OfferEsignService plus its fake collaborators.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param bool $docudeskInstalled Whether IAppManager::isInstalled('docudesk') returns true.
	 * @param object|null $documentService A fake DocumentService, or null for the default success double.
	 * @param object|null $templateService A fake TemplateService, or null for the default (empty) double.
	 * @param object|null $fileService A fake FileService, or null for the default success double.
	 * @param object|null $signingService A fake SigningService, or null for the default success double.
	 * @param string $configuredTemplateId Value returned by getDocumentsTemplateId() (empty means discovery).
	 * @param IUserManager|null $userManager A fake IUserManager, or null for the default double resolving the standard candidate fixture email.
	 *
	 * @return array{0: OfferEsignService, 1: object, 2: object, 3: object}
	 */
	private function service(
		array $rowsBySchema = [],
		bool $docudeskInstalled = true,
		?object $documentService = null,
		?object $templateService = null,
		?object $fileService = null,
		?object $signingService = null,
		string $configuredTemplateId = 'T1',
		?IUserManager $userManager = null,
	): array {
		$fakeObjects = $this->fakeObjectService($rowsBySchema);
		$documentSvc = $documentService ?? $this->fakeDocumentService();
		$templateSvc = $templateService ?? $this->fakeTemplateService();
		$fileSvc = $fileService ?? $this->fakeFileService();
		$signingSvc = $signingService ?? $this->fakeSigningService();
		$userMgr = $userManager ?? $this->fakeUserManager();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\Service\ObjectService', $fakeObjects],
				['OCA\OpenRegister\Service\FileService', $fileSvc],
				['OCA\DocuDesk\Service\DocumentService', $documentSvc],
				['OCA\DocuDesk\Service\TemplateService', $templateSvc],
				['OCA\DocuDesk\Service\SigningService', $signingSvc],
			]
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($docudeskInstalled);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getDocumentsTemplateId')->willReturn($configuredTemplateId);
		$settings->method('getDocumentsEmployerBlock')->willReturn(
			['name' => 'Voorbeeld Werkgever B.V.', 'address' => 'Voorbeeldstraat 1', 'kvkNumber' => '12345678']
		);
		$settings->method('getOfferSigningDeadlineDays')->willReturn(14);

		$logger = $this->createMock(LoggerInterface::class);

		$letterService = new OfferLetterService($container, $settings);
		$applications = new OfferApplicationRepository($container, $settings, $logger);
		$signingRecovery = new OfferSigningRecoveryService($userMgr, $logger);

		$service = new OfferEsignService($container, $appManager, $settings, $letterService, $applications, $signingRecovery, $logger);

		return [$service, $fakeObjects, $signingSvc, $fileSvc];
	}//end service()

	/**
	 * The seeded aanbod-stage Application fixture.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function application(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'app-1',
				'vacancyId' => 'vac-1',
				'candidateName' => 'Sanne Voorbeeld',
				'email' => 'sanne.voorbeeld@example.org',
				'status' => 'aanbod',
				'talentPoolOptIn' => false,
				'offerLetterFileId' => null,
				'offerSigningRequestId' => null,
				'offerSigningStatus' => null,
			],
			$overrides
		);

	}//end application()

	/**
	 * Objects saved to a given schema, in save order.
	 *
	 * @param object $fake The fake ObjectService.
	 * @param string $schema The schema name.
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
	 * REQ-OFFR-002 Scenario "Wrong stage rejected".
	 *
	 * @return void
	 */
	public function testWrongStageRejected(): void {
		$documentService = $this->fakeDocumentService();
		[$service] = $this->service(['Application' => [$this->application(['status' => 'gesprek'])]], documentService: $documentService);

		$result = $service->requestSignature('app-1');

		$this->assertSame('usage-error', $result['status']);
		$this->assertCount(0, $documentService->calls, 'No template selected, no docudesk call is made.');

	}//end testWrongStageRejected()

	/**
	 * REQ-OFFR-002/REQ-OFFR-004 Scenario "Docudesk absent degrades gracefully".
	 *
	 * @return void
	 */
	public function testDocudeskAbsentDegradesGracefully(): void {
		[$service, $fake] = $this->service(['Application' => [$this->application()]], docudeskInstalled: false);

		$result = $service->requestSignature('app-1');

		$this->assertSame('skipped-no-docudesk', $result['status']);
		$this->assertNull($result['offerLetterFileId']);

		$saves = $this->savedFor($fake, 'Application');
		$final = end($saves);
		$this->assertSame('skipped-no-docudesk', $final['offerSigningStatus']);
		$this->assertNull($final['offerLetterFileId']);

	}//end testDocudeskAbsentDegradesGracefully()

	/**
	 * REQ-OFFR-004 Scenario "Repeated skip is stable".
	 *
	 * @return void
	 */
	public function testRepeatedSkipIsStable(): void {
		[$service] = $this->service(['Application' => [$this->application()]], docudeskInstalled: false);

		$first = $service->requestSignature('app-1');
		$second = $service->requestSignature('app-1');

		$this->assertSame('skipped-no-docudesk', $first['status']);
		$this->assertSame('skipped-no-docudesk', $second['status']);
		$this->assertNull($first['offerSigningRequestId']);
		$this->assertNull($second['offerSigningRequestId']);

	}//end testRepeatedSkipIsStable()

	/**
	 * REQ-OFFR-002/D3 Scenario "dataRefs assembly carries no Employee ref".
	 *
	 * @return void
	 */
	public function testDataRefsAssemblyCarriesNoEmployeeRefAndNoPiiInAdHocData(): void {
		$documentService = $this->fakeDocumentService();
		[$service] = $this->service(['Application' => [$this->application()]], documentService: $documentService);

		$service->requestSignature('app-1', 'admin');

		$this->assertCount(1, $documentService->calls);
		$call = $documentService->calls[0];

		$this->assertSame(
			[
				['register' => 'humaniq', 'schema' => 'Application', 'id' => 'app-1'],
				['register' => 'humaniq', 'schema' => 'Vacancy', 'id' => 'vac-1'],
			],
			$call['dataRefs']
		);

		$this->assertSame('pdf', $call['options']['format']);
		$this->assertSame('admin', $call['options']['userId']);
		$this->assertSame('aanbiedingsbrief', $call['options']['adHocData']['document']['type']);
		$this->assertSame('Voorbeeld Werkgever B.V.', $call['options']['adHocData']['employer']['name']);
		// No Application/Vacancy field values copied into adHocData (design.md D3).
		$this->assertArrayNotHasKey('candidateName', $call['options']['adHocData']);
		$this->assertArrayNotHasKey('title', $call['options']['adHocData']);

	}//end testDataRefsAssemblyCarriesNoEmployeeRefAndNoPiiInAdHocData()

	/**
	 * REQ-OFFR-002 Scenario "Successful generation stores a real Nextcloud
	 * file id" -- File::getId(), not a path string.
	 *
	 * @return void
	 */
	public function testSuccessfulGenerationStoresRealNextcloudFileId(): void {
		[$service, $fake] = $this->service(
			['Application' => [$this->application()]],
			fileService: $this->fakeFileService(fileId: 777)
		);

		$result = $service->requestSignature('app-1');

		$this->assertSame(777, $result['offerLetterFileId']);
		$this->assertIsInt($result['offerLetterFileId']);

		$saves = $this->savedFor($fake, 'Application');
		$withFile = null;
		foreach ($saves as $save) {
			if (($save['offerLetterFileId'] ?? null) === 777) {
				$withFile = $save;
			}
		}

		$this->assertNotNull($withFile, 'offerLetterFileId=777 was persisted onto the Application.');

	}//end testSuccessfulGenerationStoresRealNextcloudFileId()

	/**
	 * REQ-OFFR-003 Scenario "Real signers payload, not signerIds".
	 *
	 * @return void
	 */
	public function testRealSignersPayloadNotSignerIds(): void {
		$signingService = $this->fakeSigningService();
		[$service] = $this->service(['Application' => [$this->application()]], signingService: $signingService);

		$service->requestSignature('app-1');

		$this->assertCount(1, $signingService->createCalls);
		$data = $signingService->createCalls[0];

		$this->assertArrayNotHasKey('signerIds', $data, 'createRequest() never receives a fabricated signerIds field.');
		$this->assertSame(
			[
				['userId' => 'sanne-nc', 'displayName' => 'Sanne Voorbeeld', 'email' => 'sanne.voorbeeld@example.org', 'order' => 0],
			],
			$data['signers'],
			'The candidate email resolves to a real Nextcloud user id (defect-2 fix) -- never an empty userId.'
		);

	}//end testRealSignersPayloadNotSignerIds()

	/**
	 * REQ-OFFR-003 Scenario "Provenance fields correlate back to humaniq".
	 *
	 * @return void
	 */
	public function testProvenanceFieldsCorrelateBackToHumaniq(): void {
		$signingService = $this->fakeSigningService();
		[$service] = $this->service(['Application' => [$this->application()]], signingService: $signingService);

		$service->requestSignature('app-1');

		$data = $signingService->createCalls[0];
		$this->assertSame('hrmq', $data['sourceApp']);
		$this->assertSame('Application', $data['subjectSchema']);
		$this->assertSame('app-1', $data['subjectId']);
		$this->assertSame('humaniq', $data['subjectRegister'] ?? null, 'subjectRegister carries the humaniq register slug.');
		$this->assertSame('app-1', $data['correlationId']);

	}//end testProvenanceFieldsCorrelateBackToHumaniq()

	/**
	 * REQ-OFFR-003 Scenario "CLI session gap surfaces as a failed outcome,
	 * never an uncaught exception" -- the D5 verified auth gap.
	 *
	 * @return void
	 */
	public function testCliSessionGapSurfacesAsFailedOutcomeNeverUncaughtException(): void {
		[$service] = $this->service(
			['Application' => [$this->application()]],
			signingService: $this->fakeSigningService(createThrows: true)
		);

		// No exception propagates out of the service (PHPUnit would fail the
		// test if one did) -- the outcome is `failed` instead.
		$result = $service->requestSignature('app-1');

		$this->assertSame('failed', $result['status']);
		$this->assertStringContainsString('No authenticated user', $result['message']);
		$this->assertSame('failed', $result['offerSigningStatus']);

	}//end testCliSessionGapSurfacesAsFailedOutcomeNeverUncaughtException()

	/**
	 * Defect-2 fix: a candidate email with no matching Nextcloud user fails
	 * CLEANLY -- BEFORE createRequest() is ever called -- rather than
	 * sending `signers[0].userId = ''` through to docudesk (which used to
	 * crash the whole request on the signerRecord's NOT NULL `user_id`
	 * column, live-verified against docudesk 0.0.37).
	 *
	 * @return void
	 */
	public function testNoNextcloudUserForCandidateFailsCleanlyWithoutCallingDocudesk(): void {
		$signingService = $this->fakeSigningService();
		[$service, $fake] = $this->service(
			['Application' => [$this->application()]],
			signingService: $signingService,
			userManager: $this->fakeUserManager([])
		);

		$result = $service->requestSignature('app-1');

		$this->assertSame('failed', $result['status']);
		$this->assertStringContainsString('no-nextcloud-user-for-candidate', $result['message']);
		$this->assertStringContainsString('sanne.voorbeeld@example.org', $result['message']);
		$this->assertSame('failed', $result['offerSigningStatus']);
		$this->assertCount(0, $signingService->createCalls, 'createRequest() is never reached when the candidate cannot be resolved to a Nextcloud user.');

		$saves = $this->savedFor($fake, 'Application');
		$final = end($saves);
		$this->assertSame('failed', $final['offerSigningStatus']);

	}//end testNoNextcloudUserForCandidateFailsCleanlyWithoutCallingDocudesk()

	/**
	 * Defect-3 fix: when createRequest() throws AFTER docudesk already
	 * wrote the signing-request row (e.g. an unrelated docudesk-side
	 * failure in the signer-record loop), the orphaned request is
	 * recovered via listRequests() -- keyed on BOTH correlationId and
	 * documentFileId -- when EXACTLY one candidate matches, so the orphan
	 * stays reachable via syncSignatureStatus()/cancelRequest() instead of
	 * being silently stranded.
	 *
	 * @return void
	 */
	public function testOrphanedSigningRequestRecoveredWhenExactlyOneMatch(): void {
		$signingService = $this->fakeSigningService(
			createThrows: true,
			listRequestsResult: [
				['id' => 'req-orphan', 'correlationId' => 'app-1', 'documentFileId' => '42', 'status' => 'PENDING'],
				['id' => 'req-unrelated', 'correlationId' => 'app-99', 'documentFileId' => '7', 'status' => 'PENDING'],
			]
		);
		[$service, $fake] = $this->service(
			['Application' => [$this->application()]],
			signingService: $signingService,
			fileService: $this->fakeFileService(fileId: 42)
		);

		$result = $service->requestSignature('app-1');

		$this->assertSame('failed', $result['status']);
		$this->assertSame('req-orphan', $result['offerSigningRequestId'], 'The recovered orphan id is persisted onto the Application -- reachable, not stranded.');
		$this->assertStringContainsString('req-orphan', $result['message']);

		$saves = $this->savedFor($fake, 'Application');
		$final = end($saves);
		$this->assertSame('req-orphan', $final['offerSigningRequestId']);
		$this->assertSame('failed', $final['offerSigningStatus']);

	}//end testOrphanedSigningRequestRecoveredWhenExactlyOneMatch()

	/**
	 * Defect-3 fix, the honest converse: when the orphan-recovery lookup
	 * cannot determine a SINGLE unambiguous match (none found, or docudesk's
	 * listRequests() itself fails), `offerSigningRequestId` is left null
	 * rather than guessing -- but the failure message still documents the
	 * correlationId so a human can search for it manually (never silently
	 * "nothing happened").
	 *
	 * @return void
	 */
	public function testOrphanedSigningRequestNotGuessedWhenNoMatch(): void {
		$signingService = $this->fakeSigningService(createThrows: true, listRequestsResult: []);
		[$service, $fake] = $this->service(
			['Application' => [$this->application()]],
			signingService: $signingService,
			fileService: $this->fakeFileService(fileId: 42)
		);

		$result = $service->requestSignature('app-1');

		$this->assertSame('failed', $result['status']);
		$this->assertNull($result['offerSigningRequestId']);
		$this->assertStringContainsString('correlationId="app-1"', $result['message'], 'The failure message documents how to find it manually.');

		$saves = $this->savedFor($fake, 'Application');
		$final = end($saves);
		$this->assertNull($final['offerSigningRequestId'] ?? null);
		$this->assertSame('failed', $final['offerSigningStatus']);

	}//end testOrphanedSigningRequestNotGuessedWhenNoMatch()

	/**
	 * REQ-OFFR-003 Scenario "Successful request persists id and status".
	 *
	 * @return void
	 */
	public function testSuccessfulRequestPersistsIdAndStatus(): void {
		[$service, $fake] = $this->service(
			['Application' => [$this->application()]],
			signingService: $this->fakeSigningService(createResponse: ['id' => 'req-123', 'status' => 'PENDING'])
		);

		$result = $service->requestSignature('app-1');

		$this->assertSame('requested', $result['status']);
		$this->assertSame('req-123', $result['offerSigningRequestId']);
		$this->assertSame('PENDING', $result['offerSigningStatus']);

		$saves = $this->savedFor($fake, 'Application');
		$final = end($saves);
		$this->assertSame('req-123', $final['offerSigningRequestId']);
		$this->assertSame('PENDING', $final['offerSigningStatus']);

	}//end testSuccessfulRequestPersistsIdAndStatus()

	/**
	 * REQ-OFFR-005 Scenario "Stale pending request is superseded, not
	 * duplicated".
	 *
	 * @return void
	 */
	public function testStalePendingRequestIsSupersededNotDuplicated(): void {
		$signingService = $this->fakeSigningService(createResponse: ['id' => 'req-new', 'status' => 'PENDING']);
		[$service] = $this->service(
			[
				'Application' => [
					$this->application(['offerSigningStatus' => 'PENDING', 'offerSigningRequestId' => 'req-old']),
				],
			],
			signingService: $signingService
		);

		$result = $service->requestSignature('app-1');

		$this->assertSame(['req-old'], $signingService->cancelCalls, 'cancelRequest() is attempted against the stale request.');
		$this->assertSame('requested', $result['status']);
		$this->assertSame('req-new', $result['offerSigningRequestId'], 'The Application ends up pointing at the NEW request, never req-old.');

	}//end testStalePendingRequestIsSupersededNotDuplicated()

	/**
	 * REQ-OFFR-005 Scenario "Completed request is a no-op".
	 *
	 * @return void
	 */
	public function testCompletedRequestIsANoOp(): void {
		$documentService = $this->fakeDocumentService();
		$signingService = $this->fakeSigningService();
		[$service] = $this->service(
			[
				'Application' => [
					$this->application(
						[
							'offerSigningStatus' => 'COMPLETED',
							'offerSigningRequestId' => 'req-done',
							'offerLetterFileId' => 99,
						]
					),
				],
			],
			documentService: $documentService,
			signingService: $signingService
		);

		$result = $service->requestSignature('app-1');

		$this->assertSame('already-signed', $result['status']);
		$this->assertSame(99, $result['offerLetterFileId']);
		$this->assertSame('req-done', $result['offerSigningRequestId']);
		$this->assertCount(0, $documentService->calls, 'No new letter is generated.');
		$this->assertCount(0, $signingService->createCalls, 'No new signing request is created.');

	}//end testCompletedRequestIsANoOp()

	/**
	 * REQ-OFFR-005 Scenario "Declined request is retryable" -- no cancel
	 * attempt against an already-terminal request.
	 *
	 * @return void
	 */
	public function testDeclinedRequestIsRetryableWithNoCancelAttempt(): void {
		$signingService = $this->fakeSigningService(createResponse: ['id' => 'req-2', 'status' => 'PENDING']);
		[$service] = $this->service(
			[
				'Application' => [
					$this->application(['offerSigningStatus' => 'DECLINED', 'offerSigningRequestId' => 'req-1']),
				],
			],
			signingService: $signingService
		);

		$result = $service->requestSignature('app-1');

		$this->assertSame([], $signingService->cancelCalls, 'DECLINED is already terminal -- no cancel attempted.');
		$this->assertSame('requested', $result['status']);
		$this->assertSame('req-2', $result['offerSigningRequestId']);

	}//end testDeclinedRequestIsRetryableWithNoCancelAttempt()

	/**
	 * The PUT-semantic guard: a partial offer-esign write (only
	 * offerLetterFileId at that point in the pipeline) still carries the
	 * Application's CURRENT `status` forward unchanged in the payload handed
	 * to saveObject() -- the exact trap fixed on receipt-ocr. Also confirms
	 * candidateName/email survive every intermediate save.
	 *
	 * @return void
	 */
	public function testPartialWritesNeverNullApplicationStatusOrOtherFields(): void {
		[$service, $fake] = $this->service(['Application' => [$this->application()]]);

		$service->requestSignature('app-1');

		$saves = $this->savedFor($fake, 'Application');
		$this->assertNotEmpty($saves);

		foreach ($saves as $i => $save) {
			$this->assertSame('aanbod', $save['status'] ?? null, 'save #' . $i . ' must carry Application.status forward unchanged.');
			$this->assertSame('Sanne Voorbeeld', $save['candidateName'] ?? null, 'save #' . $i . ' must carry candidateName forward unchanged.');
			$this->assertSame('sanne.voorbeeld@example.org', $save['email'] ?? null, 'save #' . $i . ' must carry email forward unchanged.');
		}

	}//end testPartialWritesNeverNullApplicationStatusOrOtherFields()

	/**
	 * REQ-OFFR-006 Scenario "Completed signature does not auto-hire", driven
	 * through `syncSignatureStatus()`.
	 *
	 * @return void
	 */
	public function testSyncSignatureStatusNeverAdvancesApplicationStatusEvenOnCompleted(): void {
		$signingService = $this->fakeSigningService(
			requestsById: ['req-1' => ['id' => 'req-1', 'status' => 'COMPLETED']]
		);
		[$service, $fake] = $this->service(
			[
				'Application' => [
					$this->application(['offerSigningStatus' => 'IN_PROGRESS', 'offerSigningRequestId' => 'req-1']),
				],
			],
			signingService: $signingService
		);

		$results = $service->syncSignatureStatus('app-1');

		$this->assertCount(1, $results);
		$this->assertSame('synced', $results[0]['status']);
		$this->assertSame('COMPLETED', $results[0]['offerSigningStatus']);

		$saves = $this->savedFor($fake, 'Application');
		$final = end($saves);
		$this->assertSame('COMPLETED', $final['offerSigningStatus']);
		// The no-auto-hire boundary (REQ-OFFR-006): Application.status stays
		// aanbod -- no aannemen transition is executed by this method.
		$this->assertSame('aanbod', $final['status']);

	}//end testSyncSignatureStatusNeverAdvancesApplicationStatusEvenOnCompleted()

	/**
	 * REQ-OFFR-006 Scenario "Sync is read-only and CLI-safe" -- getRequest()
	 * carries no session guard, so a lookup succeeds with no simulated
	 * "No authenticated user" throw anywhere in the sync path.
	 *
	 * @return void
	 */
	public function testSyncSignatureStatusDefaultScopeOnlyTargetsActiveRequests(): void {
		$signingService = $this->fakeSigningService(
			requestsById: [
				'req-active' => ['id' => 'req-active', 'status' => 'IN_PROGRESS'],
				'req-completed' => ['id' => 'req-completed', 'status' => 'COMPLETED'],
			]
		);
		[$service, $fake] = $this->service(
			[
				'Application' => [
					$this->application(['id' => 'app-pending', 'offerSigningStatus' => 'PENDING', 'offerSigningRequestId' => 'req-active']),
					$this->application(['id' => 'app-completed', 'offerSigningStatus' => 'COMPLETED', 'offerSigningRequestId' => 'req-completed']),
					$this->application(['id' => 'app-none', 'offerSigningStatus' => null, 'offerSigningRequestId' => null]),
				],
			],
			signingService: $signingService
		);

		$results = $service->syncSignatureStatus(null);

		$this->assertCount(1, $results, 'Only the PENDING/IN_PROGRESS Application is polled by default.');
		$this->assertSame('app-pending', $results[0]['applicationId']);

		$saves = $this->savedFor($fake, 'Application');
		$this->assertCount(1, $saves, 'Only the polled Application is written.');

	}//end testSyncSignatureStatusDefaultScopeOnlyTargetsActiveRequests()

	/**
	 * A `getRequest()` not-found (RuntimeException) leaves the Application's
	 * fields unchanged rather than throwing (REQ-OFFR-006, task 11).
	 *
	 * @return void
	 */
	public function testSyncGetRequestNotFoundLeavesFieldsUnchanged(): void {
		[$service, $fake] = $this->service(
			[
				'Application' => [
					$this->application(['offerSigningStatus' => 'PENDING', 'offerSigningRequestId' => 'req-ghost']),
				],
			],
			signingService: $this->fakeSigningService(requestsById: [])
		);

		$results = $service->syncSignatureStatus('app-1');

		$this->assertSame('not-found', $results[0]['status']);
		$this->assertSame('PENDING', $results[0]['offerSigningStatus'], 'Unchanged -- no write occurred.');
		$this->assertSame([], $fake->saved, 'No saveObject() call at all when the request cannot be resolved.');

	}//end testSyncGetRequestNotFoundLeavesFieldsUnchanged()

}//end class
