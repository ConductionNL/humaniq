<?php

/**
 * Unit tests for HrDocumentService.
 *
 * Pins the hrmq-docudesk-documents contract: payload/dataRefs assembly
 * (REQ-HDD-002), config-first/discovery-second/fail-closed template selection
 * (REQ-HDD-003), the D4 storage step and its failure path, the D5 duck-typed
 * skip path when docudesk is absent, and the D6 idempotency pre-check (double
 * invocation, stale-pending supersession). Drives the service through fake
 * ObjectService/FileService/DocumentService/TemplateService doubles (fake
 * collaborators, not fakes of the service logic under test) since the real
 * OpenRegister/docudesk services are sibling-app dependencies not available in
 * this standalone suite -- mirrors the PayrollGLPostServiceTest pattern.
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
 *
 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\HrDocumentService;
use OCA\Hrmq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for HrDocumentService.
 *
 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md
 */
class HrDocumentServiceTest extends TestCase
{


    /**
     * Build a fake ObjectService double: `findAll()` returns the seeded rows
     * for the current schema, `saveObject()` records every write (assignable
     * to a generated id when no uuid is given) and reflects it back into the
     * seeded rows so a subsequent idempotency probe within the same test sees
     * it.
     *
     * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
     *
     * @return object The fake ObjectService.
     */
    private function fakeObjectService(array $rowsBySchema=[]): object
    {
        return new class ($rowsBySchema) {

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
             * @param string $register Register slug (unused by the fake).
             *
             * @return self
             */
            public function setRegister(string $register): self
            {
                return $this;

            }//end setRegister()


            /**
             * @param string $schema Schema name.
             *
             * @return self
             */
            public function setSchema(string $schema): self
            {
                $this->schema = $schema;
                return $this;

            }//end setSchema()


            /**
             * @param array<string, mixed> $options Query options (unused by the fake).
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $options=[]): array
            {
                return $this->rowsBySchema[$this->schema] ?? [];

            }//end findAll()


            /**
             * @param array<string, mixed> $object        The object to save.
             * @param string|null          $register      Register slug (unused by the fake).
             * @param string|null          $schema        Schema name.
             * @param string|null          $uuid          Existing id when updating.
             * @param bool                 $_rbac         Unused by the fake.
             * @param bool                 $_multitenancy Unused by the fake.
             *
             * @return array<string, mixed> The saved object (with its id).
             */
            public function saveObject(
                array $object,
                ?string $register=null,
                ?string $schema=null,
                ?string $uuid=null,
                bool $_rbac=true,
                bool $_multitenancy=true
            ): array {
                $targetSchema = ($schema ?? $this->schema);
                $id           = ($uuid ?? ('generated-'.$targetSchema.'-'.$this->nextId++));
                $saved        = array_merge($object, ['id' => $id]);

                $this->saved[] = ['schema' => $targetSchema, 'object' => $saved];

                $rows     = ($this->rowsBySchema[$targetSchema] ?? []);
                $replaced = false;
                foreach ($rows as $i => $row) {
                    if ((string) ($row['id'] ?? '') === $id) {
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
     * Build a fake docudesk DocumentService double. Every call's args are
     * captured on the returned object's public `calls` property.
     *
     * @param bool        $throws  Whether generateDocument() throws.
     * @param string|null $content The returned PDF content, or null for empty (simulates a bad render).
     *
     * @return object
     */
    private function fakeDocumentService(bool $throws=false, ?string $content='%PDF-1.4 fake'): object
    {
        return new class ($throws, $content) {

            /**
             * Every generateDocument() call, as `['templateId' => ..., 'dataRefs' => ..., 'options' => ...]`.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $calls = [];

            /**
             * @param bool        $throws  Whether generateDocument() throws.
             * @param string|null $content The returned PDF content.
             */
            public function __construct(
                private readonly bool $throws,
                private readonly ?string $content,
            ) {

            }//end __construct()


            /**
             * @param string               $templateId The template id.
             * @param array<int, mixed>    $dataRefs   The data refs.
             * @param array<string, mixed> $options    The render options.
             *
             * @return array<string, mixed>
             *
             * @throws \RuntimeException When simulating a docudesk render failure.
             */
            public function generateDocument(string $templateId, array $dataRefs, array $options): array
            {
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
     * @param bool                               $throws    Whether getTemplatesByNamespace() throws.
     *
     * @return object
     */
    private function fakeTemplateService(array $templates=[], bool $throws=false): object
    {
        return new class ($templates, $throws) {

            /**
             * @param array<int, array<string, mixed>> $templates Templates.
             * @param bool                               $throws    Whether the call throws.
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
            public function getTemplatesByNamespace(string $namespace): array
            {
                if ($this->throws === true) {
                    throw new \RuntimeException('template register unavailable');
                }

                return $this->templates;

            }//end getTemplatesByNamespace()


        };

    }//end fakeTemplateService()


    /**
     * Build a fake OpenRegister FileService double.
     *
     * @param bool $throws Whether addFile() throws.
     *
     * @return object
     */
    private function fakeFileService(bool $throws=false): object
    {
        return new class ($throws) {

            /**
             * Every addFile() call, as `[objectEntity, fileName, content]`.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $calls = [];

            /**
             * @param bool $throws Whether addFile() throws.
             */
            public function __construct(
                private readonly bool $throws,
            ) {

            }//end __construct()


            /**
             * @param mixed  $objectEntity The object id/entity to attach the file to.
             * @param string $fileName     The file name.
             * @param string $content      The file content.
             *
             * @return object A fake File with a getPath() method.
             *
             * @throws \RuntimeException When simulating a storage failure.
             */
            public function addFile(mixed $objectEntity, string $fileName, string $content): object
            {
                $this->calls[] = ['objectEntity' => $objectEntity, 'fileName' => $fileName, 'content' => $content];

                if ($this->throws === true) {
                    throw new \RuntimeException('storage failed');
                }

                return new class ($fileName) {


                    public function __construct(private readonly string $path)
                    {

                    }//end __construct()


                    public function getPath(): string
                    {
                        return '/files/'.$this->path;

                    }//end getPath()


                };

            }//end addFile()


        };

    }//end fakeFileService()


    /**
     * Build a fully-wired HrDocumentService plus its fake collaborators.
     *
     * @param array<string, array<int, array<string, mixed>>> $rowsBySchema      Seed rows keyed by schema.
     * @param bool                                              $docudeskInstalled Whether IAppManager::isInstalled('docudesk') returns true.
     * @param object|null                                       $documentService   A fake DocumentService, or null for the default success double.
     * @param object|null                                       $templateService   A fake TemplateService, or null for the default (empty) double.
     * @param object|null                                       $fileService       A fake FileService, or null for the default success double.
     * @param string                                            $configuredTemplateId Value returned by getDocumentsTemplateId() (empty means discovery).
     *
     * @return array{0: HrDocumentService, 1: object, 2: object}
     */
    private function service(
        array $rowsBySchema=[],
        bool $docudeskInstalled=true,
        ?object $documentService=null,
        ?object $templateService=null,
        ?object $fileService=null,
        string $configuredTemplateId=''
    ): array {
        $fakeObjects  = $this->fakeObjectService($rowsBySchema);
        $documentSvc  = $documentService ?? $this->fakeDocumentService();
        $templateSvc  = $templateService ?? $this->fakeTemplateService();
        $fileSvc      = $fileService ?? $this->fakeFileService();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap(
            [
                ['OCA\OpenRegister\Service\ObjectService', $fakeObjects],
                ['OCA\OpenRegister\Service\FileService', $fileSvc],
                ['OCA\DocuDesk\Service\DocumentService', $documentSvc],
                ['OCA\DocuDesk\Service\TemplateService', $templateSvc],
            ]
        );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn($docudeskInstalled);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');
        $settings->method('getDocumentsTemplateId')->willReturn($configuredTemplateId);
        $settings->method('getDocumentsEmployerBlock')->willReturn(
            ['name' => 'Voorbeeld Werkgever B.V.', 'address' => 'Voorbeeldstraat 1', 'kvkNumber' => '12345678']
        );

        $logger = $this->createMock(LoggerInterface::class);

        return [new HrDocumentService($container, $appManager, $settings, $logger), $fakeObjects, $fileSvc];

    }//end service()


    /**
     * The seeded Employee fixture.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function employee(array $overrides=[]): array
    {
        return array_merge(['id' => 'emp-1', 'employeeNumber' => 'EMP-0001', 'lastName' => 'Jansen'], $overrides);

    }//end employee()


    /**
     * The seeded permanent, written EmploymentContract fixture.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function contract(array $overrides=[]): array
    {
        return array_merge(
            ['id' => 'contract-1', 'employeeId' => 'emp-1', 'type' => 'permanent', 'writtenContract' => true],
            $overrides
        );

    }//end contract()


    /**
     * Objects saved to a given schema, in save order.
     *
     * @param object $fake   The fake ObjectService.
     * @param string $schema The schema name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedFor(object $fake, string $schema): array
    {
        $out = [];
        foreach ($fake->saved as $entry) {
            if ($entry['schema'] === $schema) {
                $out[] = $entry['object'];
            }
        }

        return $out;

    }//end savedFor()


    /**
     * @return void
     */
    public function testGenerateAssemblesDataRefsAndAdHocDataWithoutFlatteningObjectFields(): void
    {
        $documentService = $this->fakeDocumentService();
        [$service] = $this->service(
            ['Employee' => [$this->employee()], 'EmploymentContract' => [$this->contract()]],
            documentService: $documentService,
            configuredTemplateId: 'T1'
        );

        $result = $service->generate('emp-1', 'contract-1', 'arbeidsovereenkomst', 'admin');

        $this->assertSame('generated', $result['status']);
        $this->assertCount(1, $documentService->calls);

        $call = $documentService->calls[0];
        $this->assertSame('T1', $call['templateId']);
        $this->assertSame(
            [
                ['register' => 'hrmq', 'schema' => 'Employee', 'id' => 'emp-1'],
                ['register' => 'hrmq', 'schema' => 'EmploymentContract', 'id' => 'contract-1'],
            ],
            $call['dataRefs']
        );
        $this->assertSame('pdf', $call['options']['format']);
        $this->assertSame('admin', $call['options']['userId']);
        $this->assertSame('Voorbeeld Werkgever B.V.', $call['options']['adHocData']['employer']['name']);
        $this->assertSame('arbeidsovereenkomst', $call['options']['adHocData']['document']['type']);
        // No Employee/Contract field values copied into adHocData (design.md D2).
        $this->assertArrayNotHasKey('lastName', $call['options']['adHocData']);
        $this->assertArrayNotHasKey('type', $call['options']['adHocData']);

    }//end testGenerateAssemblesDataRefsAndAdHocDataWithoutFlatteningObjectFields()


    /**
     * @return void
     */
    public function testGenerateOmitsContractRefWhenContractIdIsNull(): void
    {
        $documentService = $this->fakeDocumentService();
        [$service] = $this->service(['Employee' => [$this->employee()]], documentService: $documentService, configuredTemplateId: 'T1');

        $result = $service->generate('emp-1', null, 'werkgeversverklaring', null);

        $this->assertSame('generated', $result['status']);
        $this->assertNull($result['contractId']);
        $this->assertSame([['register' => 'hrmq', 'schema' => 'Employee', 'id' => 'emp-1']], $documentService->calls[0]['dataRefs']);
        $this->assertSame('system', $documentService->calls[0]['options']['userId']);

    }//end testGenerateOmitsContractRefWhenContractIdIsNull()


    /**
     * @return void
     */
    public function testGenerateStoresTheReturnedBinaryAndRecordsTheFilePath(): void
    {
        [$service, $fake, $fileService] = $this->service(
            ['Employee' => [$this->employee()]],
            configuredTemplateId: 'T1'
        );

        $result = $service->generate('emp-1', null, 'aanbiedingsbrief', null);

        $this->assertSame('generated', $result['status']);
        $this->assertCount(1, $fileService->calls);
        $this->assertStringContainsString('aanbiedingsbrief-EMP-0001-', $fileService->calls[0]['fileName']);
        $this->assertStringEndsWith('.pdf', $fileService->calls[0]['fileName']);

        $docSaves = $this->savedFor($fake, 'GeneratedDocument');
        $final     = end($docSaves);
        $this->assertSame('generated', $final['status']);
        $this->assertNotNull($final['filePath']);
        $this->assertNotNull($final['generatedAt']);
        $this->assertNull($final['errorMessage']);

    }//end testGenerateStoresTheReturnedBinaryAndRecordsTheFilePath()


    /**
     * @return void
     */
    public function testGenerateRecordsFailedWhenStorageThrowsAfterASuccessfulRender(): void
    {
        [$service, $fake] = $this->service(
            ['Employee' => [$this->employee()]],
            fileService: $this->fakeFileService(throws: true),
            configuredTemplateId: 'T1'
        );

        $result = $service->generate('emp-1', null, 'getuigschrift', null);

        $this->assertSame('failed', $result['status']);

        $docSaves = $this->savedFor($fake, 'GeneratedDocument');
        $final     = end($docSaves);
        $this->assertSame('failed', $final['status']);
        $this->assertNull($final['filePath'] ?? null);
        $this->assertNotEmpty($final['errorMessage']);

    }//end testGenerateRecordsFailedWhenStorageThrowsAfterASuccessfulRender()


    /**
     * @return void
     */
    public function testGenerateRecordsFailedWhenDocudeskRenderThrows(): void
    {
        [$service, $fake] = $this->service(
            ['Employee' => [$this->employee()]],
            documentService: $this->fakeDocumentService(throws: true),
            configuredTemplateId: 'T1'
        );

        $result = $service->generate('emp-1', null, 'werkgeversverklaring', null);

        $this->assertSame('failed', $result['status']);

        $docSaves = $this->savedFor($fake, 'GeneratedDocument');
        $final     = end($docSaves);
        $this->assertSame('failed', $final['status']);
        $this->assertStringContainsString('docudesk', $final['errorMessage']);

    }//end testGenerateRecordsFailedWhenDocudeskRenderThrows()


    /**
     * @return void
     */
    public function testGenerateRecordsSkippedNoDocudeskWhenNotInstalled(): void
    {
        [$service, $fake, $fileService] = $this->service(
            ['Employee' => [$this->employee()]],
            docudeskInstalled: false
        );

        $result = $service->generate('emp-1', 'contract-1', 'arbeidsovereenkomst', null);

        $this->assertSame('skipped-no-docudesk', $result['status']);
        $this->assertCount(0, $fileService->calls);

        $docSaves = $this->savedFor($fake, 'GeneratedDocument');
        $this->assertCount(1, $docSaves);
        $this->assertSame('skipped-no-docudesk', $docSaves[0]['status']);

    }//end testGenerateRecordsSkippedNoDocudeskWhenNotInstalled()


    /**
     * @return void
     */
    public function testGenerateRecordsFailedWhenNoTemplateMatchesDiscovery(): void
    {
        [$service, $fake] = $this->service(
            ['Employee' => [$this->employee()]],
            templateService: $this->fakeTemplateService([])
        );

        $result = $service->generate('emp-1', 'contract-1', 'arbeidsovereenkomst', null);

        $this->assertSame('failed', $result['status']);

        $docSaves = $this->savedFor($fake, 'GeneratedDocument');
        $this->assertCount(1, $docSaves);
        $this->assertSame('failed', $docSaves[0]['status']);
        $this->assertStringContainsString('Geen docudesk-sjabloon', $docSaves[0]['errorMessage']);

    }//end testGenerateRecordsFailedWhenNoTemplateMatchesDiscovery()


    /**
     * @return void
     */
    public function testGenerateFailsClosedWhenDiscoveryFindsAmbiguousTemplates(): void
    {
        $templates = [
            ['id' => 'tpl-a', 'name' => 'Getuigschrift A', 'category' => 'getuigschrift', 'namespace' => 'hrmq'],
            ['id' => 'tpl-b', 'name' => 'Getuigschrift B', 'category' => 'getuigschrift', 'namespace' => 'hrmq'],
        ];
        [$service, $fake, $fileService] = $this->service(
            ['Employee' => [$this->employee()]],
            templateService: $this->fakeTemplateService($templates)
        );

        $result = $service->generate('emp-1', null, 'getuigschrift', null);

        $this->assertSame('failed', $result['status']);
        $this->assertCount(0, $fileService->calls);

        $docSaves = $this->savedFor($fake, 'GeneratedDocument');
        $this->assertStringContainsString('Getuigschrift A', $docSaves[0]['errorMessage']);
        $this->assertStringContainsString('Getuigschrift B', $docSaves[0]['errorMessage']);

    }//end testGenerateFailsClosedWhenDiscoveryFindsAmbiguousTemplates()


    /**
     * @return void
     */
    public function testGenerateUsesTheConfiguredTemplateOverDiscovery(): void
    {
        $templates = [['id' => 'discovered', 'name' => 'Discovered', 'category' => 'arbeidsovereenkomst']];
        $documentService = $this->fakeDocumentService();
        [$service] = $this->service(
            ['Employee' => [$this->employee()]],
            documentService: $documentService,
            templateService: $this->fakeTemplateService($templates),
            configuredTemplateId: 'T1'
        );

        $result = $service->generate('emp-1', 'contract-1', 'arbeidsovereenkomst', null);

        $this->assertSame('generated', $result['status']);
        $this->assertSame('T1', $documentService->calls[0]['templateId']);

    }//end testGenerateUsesTheConfiguredTemplateOverDiscovery()


    /**
     * @return void
     */
    public function testGenerateSelectsTheSingleDiscoveredTemplateByCategory(): void
    {
        $templates = [
            ['id' => 'tpl-arb', 'name' => 'Arbeidsovereenkomst', 'category' => 'arbeidsovereenkomst'],
            ['id' => 'tpl-getuig', 'name' => 'Getuigschrift', 'category' => 'getuigschrift'],
        ];
        $documentService = $this->fakeDocumentService();
        [$service] = $this->service(
            ['Employee' => [$this->employee()]],
            documentService: $documentService,
            templateService: $this->fakeTemplateService($templates)
        );

        $result = $service->generate('emp-1', 'contract-1', 'arbeidsovereenkomst', null);

        $this->assertSame('generated', $result['status']);
        $this->assertSame('tpl-arb', $documentService->calls[0]['templateId']);

    }//end testGenerateSelectsTheSingleDiscoveredTemplateByCategory()


    /**
     * @return void
     */
    public function testGenerateIsIdempotentOnDoubleInvocation(): void
    {
        [$service, $fake, $fileService] = $this->service(['Employee' => [$this->employee()]], configuredTemplateId: 'T1');

        $first  = $service->generate('emp-1', 'contract-1', 'arbeidsovereenkomst', null);
        $second = $service->generate('emp-1', 'contract-1', 'arbeidsovereenkomst', null);

        $this->assertSame('generated', $first['status']);
        $this->assertSame('already-generated', $second['status']);
        $this->assertCount(1, $fileService->calls);

    }//end testGenerateIsIdempotentOnDoubleInvocation()


    /**
     * @return void
     */
    public function testGenerateSupersedesAStalePendingRecordThenSucceeds(): void
    {
        $rows = [
            'Employee' => [$this->employee()],
            'GeneratedDocument' => [
                ['id' => 'gd-1', 'documentType' => 'arbeidsovereenkomst', 'employeeId' => 'emp-1', 'contractId' => 'contract-1', 'status' => 'pending'],
            ],
        ];
        [$service, $fake] = $this->service($rows, configuredTemplateId: 'T1');

        $result = $service->generate('emp-1', 'contract-1', 'arbeidsovereenkomst', null);

        $this->assertSame('generated', $result['status']);

        $docSaves = $this->savedFor($fake, 'GeneratedDocument');
        $statuses  = array_column($docSaves, 'status');
        $this->assertContains('failed', $statuses);
        $this->assertContains('generated', $statuses);

    }//end testGenerateSupersedesAStalePendingRecordThenSucceeds()


    /**
     * @return void
     */
    public function testGenerateFailsWhenEmployeeIdIsBlank(): void
    {
        [$service] = $this->service();

        $result = $service->generate('', null, 'arbeidsovereenkomst', null);

        $this->assertSame('failed', $result['status']);

    }//end testGenerateFailsWhenEmployeeIdIsBlank()


    /**
     * @return void
     */
    public function testGenerateBacklogProcessesOnlyPermanentWrittenContractsWithoutAnActiveDocument(): void
    {
        $rows = [
            'Employee'           => [$this->employee(), $this->employee(['id' => 'emp-2', 'employeeNumber' => 'EMP-0002'])],
            'EmploymentContract' => [
                $this->contract(['id' => 'contract-1', 'employeeId' => 'emp-1']),
                $this->contract(['id' => 'contract-2', 'employeeId' => 'emp-2', 'type' => 'temporary', 'writtenContract' => false]),
            ],
            'GeneratedDocument'  => [
                ['id' => 'gd-existing', 'documentType' => 'arbeidsovereenkomst', 'employeeId' => 'emp-1', 'contractId' => 'contract-1', 'status' => 'generated'],
            ],
        ];
        [$service] = $this->service($rows, configuredTemplateId: 'T1');

        $results = $service->generateBacklog();

        // Only the permanent+written contract is in the backlog; its existing
        // generated document makes it a no-op; the temporary contract is never
        // selected (design.md D7).
        $this->assertCount(1, $results);
        $this->assertSame('contract-1', $results[0]['contractId']);
        $this->assertSame('already-generated', $results[0]['status']);

    }//end testGenerateBacklogProcessesOnlyPermanentWrittenContractsWithoutAnActiveDocument()


    /**
     * @return void
     */
    public function testGenerateBacklogRefusesANonDefaultTypeWithoutEmployee(): void
    {
        [$service] = $this->service();

        $results = $service->generateBacklog('werkgeversverklaring', null);

        $this->assertCount(1, $results);
        $this->assertSame('usage-error', $results[0]['status']);

    }//end testGenerateBacklogRefusesANonDefaultTypeWithoutEmployee()


    /**
     * @return void
     */
    public function testGenerateBacklogGeneratesAnEmployeeLevelTypeWhenEmployeeIsGiven(): void
    {
        [$service, $fake] = $this->service(['Employee' => [$this->employee()]], configuredTemplateId: 'T1');

        $results = $service->generateBacklog('werkgeversverklaring', 'emp-1');

        $this->assertCount(1, $results);
        $this->assertSame('generated', $results[0]['status']);
        $this->assertNull($results[0]['contractId']);

    }//end testGenerateBacklogGeneratesAnEmployeeLevelTypeWhenEmployeeIsGiven()


}//end class
