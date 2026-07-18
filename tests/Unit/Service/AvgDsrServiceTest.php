<?php

/**
 * Unit tests for AvgDsrService.
 *
 * hrmq#99 (consume-not-rebuild correction): pins the avg-dsr contract as it
 * now consumes OpenRegister's guarded, RBAC/tenant-scoped
 * `Gdpr\DataSubjectRequestService` directly -- retention enforcement is the
 * GUARDED SERVICE's own (a legal hold / immutable archival status refuses an
 * object, reported in `held`, REQ-DSR-005), never a bespoke hrmq
 * classification. `AvgDsrService` performs NO retention computation of its
 * own: `eraseSubject()`/`previewErasure()` call `erase()` directly and relay
 * its `held`/`erased`/`failed` buckets unchanged. Export renders
 * `findSubjectData()` exactly once for either right (REQ-DSR-003); erasure is
 * always a zero-write preview (`erase(..., dryRun: true)`) before an
 * explicitly-confirmed execute tied to that SAME recorded preview
 * (REQ-DSR-006); rectification calls `rectify()` directly with the object's
 * id/uuid (no int-id resolution workaround, REQ-DSR-007); and the raw `bsn`
 * value is resolved transiently and never persisted onto `DsrRequest` or
 * logged (REQ-DSR-002). Drives the service through a fake ObjectService/
 * guarded-service double (fake collaborators, not fakes of the logic under
 * test) since the real OpenRegister services are a sibling-app dependency
 * not available in this standalone suite -- the
 * LoonbeslagControllerTest/AdministrationServiceTest precedent.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-002
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-003
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\AvgDsrService;
use OCA\Hrmq\Service\SettingsService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AvgDsrService.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */
class AvgDsrServiceTest extends TestCase
{

    /**
     * A distinctive sentinel bsn value -- never expected to appear in any
     * DsrRequest write or logged message (REQ-DSR-002).
     *
     * @var string
     */
    private const SENTINEL_BSN = 'SENTINEL-BSN-999999999';


    /**
     * REQ-DSR-005: the guarded service's `held` bucket is relayed unchanged
     * into the outcome's `retained` list -- `AvgDsrService` computes nothing
     * itself.
     *
     * @return void
     */
    public function testHeldObjectIsReportedRetainedNeverErased(): void
    {
        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                'dsr-1' => $this->fakeEntity(['status' => 'in_behandeling', 'retainedObjectRefs' => '[]'], 'dsr-1', 'DsrRequest'),
            ]
        );

        $guarded = $this->fakeGuardedService(
            erased: [['uuid' => 'contract-eligible-uuid', 'register' => 'hrmq', 'schema' => 'EmploymentContract']],
            held: [['uuid' => 'payslip-retained-uuid', 'reason' => 'legal-hold']],
            failed: []
        );

        $service = $this->buildService($objectService, $guarded);
        $outcome = $service->eraseSubject('emp-1', 'dsr-1');

        $this->assertSame('voldaan', $outcome['status']);
        $this->assertCount(1, $guarded->eraseCalls, 'erase() is called exactly once -- no per-object loop in hrmq.');
        $this->assertFalse($guarded->eraseCalls[0]['dryRun']);

        $this->assertCount(1, $outcome['retained']);
        $this->assertSame('payslip-retained-uuid', $outcome['retained'][0]['uuid']);
        $this->assertSame('legal-hold', $outcome['retained'][0]['reason']);
        $this->assertCount(1, $outcome['erased']);
        $this->assertSame('contract-eligible-uuid', $outcome['erased'][0]['uuid']);

    }//end testHeldObjectIsReportedRetainedNeverErased()


    /**
     * REQ-DSR-005: when nothing is held, the outcome's `retained` list is
     * empty and everything the guarded service erased is reported.
     *
     * @return void
     */
    public function testNothingHeldReportsAnEmptyRetainedList(): void
    {
        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                'dsr-1' => $this->fakeEntity(['status' => 'in_behandeling', 'retainedObjectRefs' => '[]'], 'dsr-1', 'DsrRequest'),
            ]
        );

        $guarded = $this->fakeGuardedService(
            erased: [['uuid' => 'eligible-uuid', 'register' => 'hrmq', 'schema' => 'EmploymentContract']],
            held: [],
            failed: []
        );

        $service = $this->buildService($objectService, $guarded);
        $outcome = $service->eraseSubject('emp-1', 'dsr-1');

        $this->assertSame('voldaan', $outcome['status']);
        $this->assertSame([], $outcome['retained']);
        $this->assertCount(1, $outcome['erased']);

    }//end testNothingHeldReportsAnEmptyRetainedList()


    /**
     * REQ-DSR-006: `eraseSubject()` refuses (controlled, zero writes) when
     * the referenced DsrRequest has no recorded preview -- the guarded
     * service's `erase()` is never called.
     *
     * @return void
     */
    public function testEraseSubjectRefusedWithoutRecordedPreview(): void
    {
        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                // status ontvangen, retainedObjectRefs still null -- no preview recorded.
                'dsr-1' => $this->fakeEntity(['status' => 'ontvangen', 'retainedObjectRefs' => null], 'dsr-1', 'DsrRequest'),
            ]
        );
        $guarded = $this->fakeGuardedService(erased: [], held: [], failed: []);

        $service = $this->buildService($objectService, $guarded);
        $outcome = $service->eraseSubject('emp-1', 'dsr-1');

        $this->assertSame('refused', $outcome['status']);
        $this->assertSame([], $guarded->eraseCalls);
        $this->assertSame([], $objectService->saved, 'A refused precondition performs zero writes.');

    }//end testEraseSubjectRefusedWithoutRecordedPreview()


    /**
     * REQ-DSR-006: `previewErasure()` calls `erase(..., dryRun: true)` --
     * zero writes to any subject's data object -- and records the preview
     * marker on the DsrRequest as the ONLY write.
     *
     * @return void
     */
    public function testPreviewErasureCallsDryRunAndPerformsZeroObjectWrites(): void
    {
        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                'dsr-1' => $this->fakeEntity(['status' => 'ontvangen', 'retainedObjectRefs' => null], 'dsr-1', 'DsrRequest'),
            ]
        );
        $guarded = $this->fakeGuardedService(
            erased: [['uuid' => 'eligible-uuid', 'register' => 'hrmq', 'schema' => 'EmploymentContract']],
            held: [['uuid' => 'retained-uuid', 'reason' => 'legal-hold']],
            failed: []
        );

        $service = $this->buildService($objectService, $guarded);
        $preview = $service->previewErasure('emp-1', 'dsr-1');

        $this->assertCount(1, $guarded->eraseCalls);
        $this->assertTrue($guarded->eraseCalls[0]['dryRun'], 'previewErasure() MUST call erase() with dryRun: true.');
        $this->assertCount(1, $preview['retained']);
        $this->assertCount(1, $preview['wouldErase']);

        // The ONLY write is the DsrRequest bookkeeping record itself, never a
        // subject's data object.
        $this->assertCount(1, $objectService->saved);
        $this->assertSame('dsr-1', $objectService->saved[0]['id']);
        $this->assertSame('in_behandeling', $objectService->saved[0]['status']);
        $this->assertNotNull($objectService->saved[0]['retainedObjectRefs'], 'The preview marker is always recorded, even for an empty retained list.');

    }//end testPreviewErasureCallsDryRunAndPerformsZeroObjectWrites()


    /**
     * REQ-DSR-003: `exportForSubject()` calls `findSubjectData()` exactly
     * once per export request, for either right.
     *
     * @return void
     */
    public function testExportCallsFindSubjectDataExactlyOncePerRight(): void
    {
        $matched = $this->fakeEntity([], 'matched-uuid', 'Payslip');

        $objectService = $this->fakeObjectService(['emp-1' => $this->fakeEntity(['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee')]);
        $guarded       = $this->fakeGuardedService(erased: [], held: [], failed: []);
        $guarded->findSubjectDataResult = [['object' => $matched->jsonSerialize(), 'gdprEntities' => []]];

        $service = $this->buildService($objectService, $guarded);

        $inzage = $service->exportForSubject('emp-1', 'inzage');
        $this->assertSame(1, $guarded->findCallCount);
        $this->assertSame('inzage', $inzage['right']);
        $this->assertSame(1, $inzage['count']);

        $portabiliteit = $service->exportForSubject('emp-1', 'portabiliteit');
        $this->assertSame(2, $guarded->findCallCount, 'A SECOND export call makes exactly one MORE findSubjectData() call.');
        $this->assertSame('portabiliteit', $portabiliteit['right']);
        $this->assertSame(1, $portabiliteit['count']);

    }//end testExportCallsFindSubjectDataExactlyOncePerRight()


    /**
     * REQ-DSR-007: rectification calls the guarded service's `rectify()`
     * with the object identifier directly (a plain id/uuid string, no
     * internal-int-id resolution workaround) and records only the changed
     * field NAMES, never before/after values.
     *
     * @return void
     */
    public function testRectifyCallsGuardedRectifyWithIdentifierDirectly(): void
    {
        $objectService = $this->fakeObjectService(
            ['dsr-1' => $this->fakeEntity(['status' => 'in_behandeling'], 'dsr-1', 'DsrRequest')]
        );
        $guarded                                    = $this->fakeGuardedService(erased: [], held: [], failed: []);
        $guarded->rectifyResults['emp-1-uuid'] = ['id' => 'emp-1-uuid', 'lastName' => 'Corrected'];

        $service = $this->buildService($objectService, $guarded);
        $result  = $service->rectifySubjectObject('emp-1-uuid', ['lastName' => 'Corrected'], 'dsr-1');

        $this->assertNotNull($result);
        $this->assertCount(1, $guarded->rectifyCalls);
        $this->assertSame('emp-1-uuid', $guarded->rectifyCalls[0]['objectIdentifier']);

        $saved = $objectService->saved[0];
        $this->assertSame('voldaan', $saved['status']);
        $this->assertStringContainsString('lastName', $saved['outcomeSummary']);
        $this->assertStringNotContainsString('Corrected', $saved['outcomeSummary'], 'Only the field NAME is recorded, never the new value.');

    }//end testRectifyCallsGuardedRectifyWithIdentifierDirectly()


    /**
     * REQ-DSR-007: a failed rectification (guarded rectify() returns null,
     * e.g. an immutable archival status) is reported -- DsrRequest ->
     * afgewezen with a rejectionReason, never silently dropped.
     *
     * @return void
     */
    public function testFailedRectificationIsReportedNotSilentlyDropped(): void
    {
        $objectService = $this->fakeObjectService(
            ['dsr-1' => $this->fakeEntity(['status' => 'in_behandeling'], 'dsr-1', 'DsrRequest')]
        );
        $guarded                                    = $this->fakeGuardedService(erased: [], held: [], failed: []);
        $guarded->rectifyResults['emp-1-uuid'] = null;

        $service = $this->buildService($objectService, $guarded);
        $result  = $service->rectifySubjectObject('emp-1-uuid', ['lastName' => 'X'], 'dsr-1');

        $this->assertNull($result);
        $saved = $objectService->saved[0];
        $this->assertSame('afgewezen', $saved['status']);
        $this->assertNotEmpty($saved['rejectionReason']);

    }//end testFailedRectificationIsReportedNotSilentlyDropped()


    /**
     * REQ-DSR-002: the raw bsn value is resolved transiently and never
     * appears in any DsrRequest write or logged message, across export,
     * preview, and erase.
     *
     * @return void
     */
    public function testBsnValueNeverPersistedOrLogged(): void
    {
        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                'dsr-1' => $this->fakeEntity(['status' => 'in_behandeling', 'retainedObjectRefs' => '[]'], 'dsr-1', 'DsrRequest'),
            ]
        );
        $guarded = $this->fakeGuardedService(
            erased: [['uuid' => 'eligible-uuid', 'register' => 'hrmq', 'schema' => 'EmploymentContract']],
            held: [],
            failed: []
        );
        $logger = $this->spyLogger();

        $service = $this->buildService($objectService, $guarded, logger: $logger);

        $service->exportForSubject('emp-1', 'inzage', 'dsr-1');
        $service->previewErasure('emp-1', 'dsr-1');
        $service->eraseSubject('emp-1', 'dsr-1');

        foreach ($objectService->saved as $saved) {
            $encoded = json_encode($saved);
            $this->assertStringNotContainsString(self::SENTINEL_BSN, (string) $encoded, 'The raw bsn value must never be written onto a DsrRequest.');
        }

        foreach ($guarded->eraseCalls as $call) {
            $this->assertSame(self::SENTINEL_BSN, $call['subjectId'], 'The resolved bsn IS the in-memory subjectId argument -- this assertion documents that, it is not itself a leak.');
        }

        foreach ($logger->messages as $message) {
            $this->assertStringNotContainsString(self::SENTINEL_BSN, $message, 'The raw bsn value must never be logged.');
        }

    }//end testBsnValueNeverPersistedOrLogged()


    /**
     * Build an AvgDsrService wired to fake ObjectService/guarded-service
     * doubles.
     *
     * @param object               $objectService The fake ObjectService.
     * @param object               $guarded       The fake `Gdpr\DataSubjectRequestService` double.
     * @param string               $currentUid    The active session uid.
     * @param LoggerInterface|null $logger        Optional logger double (a spy, or a plain mock when unused by the test).
     *
     * @return AvgDsrService
     */
    private function buildService(object $objectService, object $guarded, string $currentUid='admin', ?LoggerInterface $logger=null): AvgDsrService
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService, $guarded) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }

                if ($id === 'OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService') {
                    return $guarded;
                }

                throw new \RuntimeException('Unexpected container->get('.$id.')');
            }
        );

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($currentUid);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        return new AvgDsrService($container, $settings, $userSession, ($logger ?? $this->createMock(LoggerInterface::class)));

    }//end buildService()


    /**
     * A fake ObjectEntity double exposing the SAME `jsonSerialize(): array`
     * contract as OpenRegister's real ObjectEntity (`id` overwritten with the
     * string uuid, `@self` carries schema/register).
     *
     * @param array<string, mixed>  $data     The object's own data fields.
     * @param string                $uuid     The object's string uuid.
     * @param string                $schema   The object's schema.
     * @param string                $register The object's register.
     *
     * @return object
     */
    private function fakeEntity(array $data, string $uuid, string $schema, string $register='hrmq'): object
    {
        return new class ($data, $uuid, $schema, $register) {


            /**
             * @param array<string, mixed> $data     Object data.
             * @param string               $uuid     String uuid.
             * @param string               $schema   Schema name.
             * @param string               $register Register slug.
             */
            public function __construct(
                private readonly array $data,
                private readonly string $uuid,
                private readonly string $schema,
                private readonly string $register,
            ) {

            }//end __construct()


            /**
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                $object          = $this->data;
                $object['id']    = $this->uuid;
                $object['@self'] = [
                    'id'       => $this->uuid,
                    'schema'   => $this->schema,
                    'register' => $this->register,
                ];
                return $object;

            }//end jsonSerialize()
        };

    }//end fakeEntity()


    /**
     * A fake ObjectService double: `find()` returns the seeded entity for an
     * id (or null), `saveObject()` records every write.
     *
     * @param array<string, object> $entitiesById Seeded id -> fake entity map.
     *
     * @return object
     */
    private function fakeObjectService(array $entitiesById): object
    {
        return new class ($entitiesById) {


            /**
             * @var array<string, object>
             */
            private array $entitiesById;

            /**
             * @var array<int, array<string, mixed>>
             */
            public array $saved = [];


            /**
             * @param array<string, object> $entitiesById Seeded id -> fake entity map.
             */
            public function __construct(array $entitiesById)
            {
                $this->entitiesById = $entitiesById;

            }//end __construct()


            /**
             * @param string      $id       Object id.
             * @param string|null $register Register slug (unused by the fake).
             * @param string|null $schema   Schema name (unused by the fake).
             *
             * @return object|null
             */
            public function find(string $id, ?string $register=null, ?string $schema=null): ?object
            {
                return $this->entitiesById[$id] ?? null;

            }//end find()


            /**
             * @param array<string, mixed> $object        Object to save.
             * @param string|null          $register      Register slug (unused).
             * @param string|null          $schema        Schema name (unused).
             * @param string|null          $uuid          Existing id when updating.
             * @param bool                 $_rbac         Unused.
             * @param bool                 $_multitenancy Unused.
             *
             * @return array<string, mixed>
             */
            public function saveObject(
                array $object,
                ?string $register=null,
                ?string $schema=null,
                ?string $uuid=null,
                bool $_rbac=true,
                bool $_multitenancy=true
            ): array {
                $id             = ($uuid ?? ('generated-'.(count($this->saved) + 1)));
                $saved          = array_merge($object, ['id' => $id]);
                $this->saved[]  = $saved;
                return $saved;

            }//end saveObject()
        };

    }//end fakeObjectService()


    /**
     * A fake `Gdpr\DataSubjectRequestService` double: `findSubjectData()`
     * returns the configured result and increments `findCallCount`;
     * `erase()` records every call and returns the pre-configured
     * `erased`/`held`/`failed` split UNCHANGED regardless of `dryRun`
     * (mirroring the real guarded service, which classifies the SAME way for
     * a dry run -- only the actual mutation is skipped); `rectify()` records
     * every call.
     *
     * @param array<int, array<string, mixed>> $erased The pre-configured `erased` bucket.
     * @param array<int, array<string, mixed>> $held   The pre-configured `held` bucket.
     * @param array<int, array<string, mixed>> $failed The pre-configured `failed` bucket.
     *
     * @return object
     */
    private function fakeGuardedService(array $erased, array $held, array $failed): object
    {
        return new class ($erased, $held, $failed) {


            /**
             * @var array<int, array<string, mixed>>
             */
            private array $erased;

            /**
             * @var array<int, array<string, mixed>>
             */
            private array $held;

            /**
             * @var array<int, array<string, mixed>>
             */
            private array $failed;

            /**
             * @var array<int, array<string, mixed>>
             */
            public array $findSubjectDataResult = [];

            /**
             * @var int
             */
            public int $findCallCount = 0;

            /**
             * @var array<int, array{subjectId: string, type: string|null, eraseMode: string, dryRun: bool}>
             */
            public array $eraseCalls = [];

            /**
             * @var array<int, array{objectIdentifier: string, changes: array<string, mixed>}>
             */
            public array $rectifyCalls = [];

            /**
             * Per-objectIdentifier configured rectify() return value.
             *
             * @var array<string, array<string, mixed>|null>
             */
            public array $rectifyResults = [];


            /**
             * @param array<int, array<string, mixed>> $erased Configured `erased` bucket.
             * @param array<int, array<string, mixed>> $held   Configured `held` bucket.
             * @param array<int, array<string, mixed>> $failed Configured `failed` bucket.
             */
            public function __construct(array $erased, array $held, array $failed)
            {
                $this->erased = $erased;
                $this->held   = $held;
                $this->failed = $failed;

            }//end __construct()


            /**
             * @param string      $subjectId Subject value.
             * @param string|null $type      Unused by the fake.
             * @param string      $mode      Unused by the fake.
             * @param bool        $rbac      Unused by the fake.
             * @param bool        $multitenancy Unused by the fake.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findSubjectData(string $subjectId, ?string $type=null, string $mode='exact', bool $rbac=true, bool $multitenancy=true): array
            {
                $this->findCallCount++;
                return $this->findSubjectDataResult;

            }//end findSubjectData()


            /**
             * @param string      $subjectId Subject value.
             * @param string|null $type      Type filter.
             * @param string      $eraseMode Erase mode.
             * @param bool        $dryRun    Dry-run flag.
             *
             * @return array<string, mixed>
             */
            public function erase(string $subjectId, ?string $type=null, string $eraseMode='pseudonymise', bool $dryRun=false): array
            {
                $this->eraseCalls[] = ['subjectId' => $subjectId, 'type' => $type, 'eraseMode' => $eraseMode, 'dryRun' => $dryRun];

                return [
                    'subject'      => $subjectId,
                    'type'         => $type,
                    'eraseMode'    => $eraseMode,
                    'dryRun'       => $dryRun,
                    'matchedCount' => (count($this->erased) + count($this->held) + count($this->failed)),
                    'erased'       => $this->erased,
                    'held'         => $this->held,
                    'failed'       => $this->failed,
                    'complete'     => ($this->failed === []),
                    'failedCount'  => count($this->failed),
                    'heldCount'    => count($this->held),
                ];

            }//end erase()


            /**
             * @param string               $objectIdentifier Object id/uuid.
             * @param array<string, mixed> $changes          Changes.
             *
             * @return array<string, mixed>|null
             */
            public function rectify(string $objectIdentifier, array $changes): ?array
            {
                $this->rectifyCalls[] = ['objectIdentifier' => $objectIdentifier, 'changes' => $changes];
                if (array_key_exists($objectIdentifier, $this->rectifyResults) === true) {
                    return $this->rectifyResults[$objectIdentifier];
                }

                return ['id' => $objectIdentifier, 'updated' => true];

            }//end rectify()
        };

    }//end fakeGuardedService()


    /**
     * A spy LoggerInterface that records every message text (interpolated
     * with its context values, so a leaked value passed via context is
     * caught too).
     *
     * @return object&LoggerInterface
     */
    private function spyLogger(): object
    {
        return new class implements LoggerInterface {

            /**
             * @var array<int, string>
             */
            public array $messages = [];


            /**
             * @param mixed              $level   Log level (unused).
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function log($level, string|\Stringable $message, array $context=[]): void
            {
                $this->messages[] = ((string) $message).' '.json_encode($context);

            }//end log()


            /**
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function emergency(string|\Stringable $message, array $context=[]): void
            {
                $this->log('emergency', $message, $context);

            }//end emergency()


            /**
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function alert(string|\Stringable $message, array $context=[]): void
            {
                $this->log('alert', $message, $context);

            }//end alert()


            /**
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function critical(string|\Stringable $message, array $context=[]): void
            {
                $this->log('critical', $message, $context);

            }//end critical()


            /**
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function error(string|\Stringable $message, array $context=[]): void
            {
                $this->log('error', $message, $context);

            }//end error()


            /**
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function warning(string|\Stringable $message, array $context=[]): void
            {
                $this->log('warning', $message, $context);

            }//end warning()


            /**
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function notice(string|\Stringable $message, array $context=[]): void
            {
                $this->log('notice', $message, $context);

            }//end notice()


            /**
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function info(string|\Stringable $message, array $context=[]): void
            {
                $this->log('info', $message, $context);

            }//end info()


            /**
             * @param string|\Stringable $message Message.
             * @param array<mixed>       $context Context.
             *
             * @return void
             */
            public function debug(string|\Stringable $message, array $context=[]): void
            {
                $this->log('debug', $message, $context);

            }//end debug()
        };

    }//end spyLogger()


}//end class
