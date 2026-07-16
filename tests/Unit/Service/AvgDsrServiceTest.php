<?php

/**
 * Unit tests for AvgDsrService.
 *
 * Pins the avg-dsr compliance contract (design.md D1/D2/D4/D5/D6): the
 * statutory-retention guard is STRUCTURAL -- a retention-locked object (a
 * populated `retainedUntil`/`identityDocumentRetainedUntil` dated on or
 * after today, or the AWR art. 52 lid 4 7-year fallback derivation for the
 * payroll/loonadministratie family) is NEVER passed into
 * `eraseObjectsForSubject()` or `rectifyObjectForSubject()`, always reported
 * in the outcome's `retained` list (REQ-DSR-005); erasure is always a
 * zero-write preview before an explicitly-confirmed execute tied to that
 * SAME recorded preview (REQ-DSR-006); export renders `findObjectsForSubject()`
 * exactly once for either right (REQ-DSR-003); rectification is a direct,
 * no-retention-guard pass-through recording only changed field NAMES
 * (REQ-DSR-007); and the raw `bsn` value is resolved transiently and never
 * persisted onto `DsrRequest` or logged (REQ-DSR-002). Drives the service
 * through fake ObjectService/DsarService doubles (fake collaborators, not
 * fakes of the logic under test) since the real OpenRegister services are a
 * sibling-app dependency not available in this standalone suite -- the
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
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-002
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-003
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-006
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-007
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
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
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
     * The retention guard NEVER passes a retention-locked object into
     * `eraseObjectsForSubject()` or `rectifyObjectForSubject()` (REQ-DSR-005):
     * given a mix of one retained Payslip and one eligible object, the
     * guarded execute NEVER calls `eraseObjectsForSubject()` at all, and
     * `rectifyObjectForSubject()` is called ONLY with the eligible object's
     * internal id -- the retained object's internal id never appears in
     * either DsarService write call.
     *
     * @return void
     */
    public function testRetainedObjectIsNeverPassedToEitherEraseCall(): void
    {
        $futureRetainedUntil = date('Y-m-d', strtotime('+1 year'));

        $retainedPayslip = $this->fakeEntity(
            internalId: 5001,
            data: ['retainedUntil' => $futureRetainedUntil, 'period' => date('Y-m')],
            uuid: 'payslip-retained-uuid',
            schema: 'Payslip'
        );
        $eligibleContract = $this->fakeEntity(
            internalId: 5002,
            data: [],
            uuid: 'contract-eligible-uuid',
            schema: 'EmploymentContract'
        );

        $objectService = $this->fakeObjectService(
            [
                'emp-1'                 => $this->fakeEntity(1, ['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                'dsr-1'                 => $this->fakeEntity(
                    2,
                    [
                        'employeeId'          => 'emp-1',
                        'status'              => 'in_behandeling',
                        'retainedObjectRefs'  => '[]',
                    ],
                    'dsr-1',
                    'DsrRequest'
                ),
                'payslip-retained-uuid' => $retainedPayslip,
                'contract-eligible-uuid' => $eligibleContract,
            ]
        );

        $dsarService = $this->fakeDsarService(
            [
                ['object' => $retainedPayslip->jsonSerialize()],
                ['object' => $eligibleContract->jsonSerialize()],
            ]
        );

        $service = $this->buildService($objectService, $dsarService);

        $outcome = $service->eraseSubject('emp-1', 'dsr-1');

        $this->assertSame('voldaan', $outcome['status']);
        $this->assertSame([], $dsarService->eraseCalls, 'eraseObjectsForSubject() must NEVER be called when anything is retained.');
        $this->assertCount(1, $dsarService->rectifyCalls, 'Exactly one rectify call -- the eligible object only.');
        $this->assertSame(5002, $dsarService->rectifyCalls[0]['objectId'], 'Only the ELIGIBLE object\'s internal id is rectified.');

        foreach ($dsarService->rectifyCalls as $call) {
            $this->assertNotSame(5001, $call['objectId'], 'The RETAINED object\'s internal id must never be passed to rectifyObjectForSubject().');
        }

        $this->assertCount(1, $outcome['retained']);
        $this->assertSame('payslip-retained-uuid', $outcome['retained'][0]['uuid']);
        $this->assertSame('retained (wettelijke bewaarplicht)', $outcome['retained'][0]['label']);
        $this->assertCount(1, $outcome['erased']);
        $this->assertSame('contract-eligible-uuid', $outcome['erased'][0]['uuid']);

    }//end testRetainedObjectIsNeverPassedToEitherEraseCall()


    /**
     * REQ-DSR-005 fast path: when nothing is retained, ONE wholesale
     * `eraseObjectsForSubject()` call runs and `rectifyObjectForSubject()` is
     * never called.
     *
     * @return void
     */
    public function testFastPathWholesaleEraseWhenNothingRetained(): void
    {
        $eligible = $this->fakeEntity(6001, [], 'eligible-uuid', 'EmploymentContract');

        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(1, ['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                'dsr-1' => $this->fakeEntity(2, ['status' => 'in_behandeling', 'retainedObjectRefs' => '[]'], 'dsr-1', 'DsrRequest'),
            ]
        );

        $dsarService = $this->fakeDsarService([['object' => $eligible->jsonSerialize()]]);

        $service = $this->buildService($objectService, $dsarService);
        $outcome = $service->eraseSubject('emp-1', 'dsr-1');

        $this->assertSame('voldaan', $outcome['status']);
        $this->assertCount(1, $dsarService->eraseCalls);
        $this->assertSame([], $dsarService->rectifyCalls);
        $this->assertSame([], $outcome['retained']);

    }//end testFastPathWholesaleEraseWhenNothingRetained()


    /**
     * REQ-DSR-006: `eraseSubject()` refuses (controlled, zero writes) when
     * the referenced DsrRequest has no recorded preview.
     *
     * @return void
     */
    public function testEraseSubjectRefusedWithoutRecordedPreview(): void
    {
        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(1, ['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                // status ontvangen, retainedObjectRefs still null -- no preview recorded.
                'dsr-1' => $this->fakeEntity(2, ['status' => 'ontvangen', 'retainedObjectRefs' => null], 'dsr-1', 'DsrRequest'),
            ]
        );
        $dsarService = $this->fakeDsarService([]);

        $service = $this->buildService($objectService, $dsarService);
        $outcome = $service->eraseSubject('emp-1', 'dsr-1');

        $this->assertSame('refused', $outcome['status']);
        $this->assertSame([], $dsarService->eraseCalls);
        $this->assertSame([], $dsarService->rectifyCalls);
        $this->assertSame([], $objectService->saved, 'A refused precondition performs zero writes.');

    }//end testEraseSubjectRefusedWithoutRecordedPreview()


    /**
     * REQ-DSR-006: `previewErasure()` performs zero writes to any subject's
     * data object -- neither DsarService write method is called, regardless
     * of whether a `$dsrRequestId` is given.
     *
     * @return void
     */
    public function testPreviewErasurePerformsZeroWrites(): void
    {
        $retained = $this->fakeEntity(7001, ['retainedUntil' => date('Y-m-d', strtotime('+1 year'))], 'retained-uuid', 'Payslip');
        $eligible = $this->fakeEntity(7002, [], 'eligible-uuid', 'EmploymentContract');

        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(1, ['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                'dsr-1' => $this->fakeEntity(2, ['status' => 'ontvangen', 'retainedObjectRefs' => null], 'dsr-1', 'DsrRequest'),
            ]
        );
        $dsarService = $this->fakeDsarService([['object' => $retained->jsonSerialize()], ['object' => $eligible->jsonSerialize()]]);

        $service = $this->buildService($objectService, $dsarService);
        $preview = $service->previewErasure('emp-1', 'dsr-1');

        $this->assertSame([], $dsarService->eraseCalls);
        $this->assertSame([], $dsarService->rectifyCalls);
        $this->assertCount(1, $preview['retained']);
        $this->assertCount(1, $preview['wouldErase']);

        // The ONLY write is the DsrRequest bookkeeping record itself, never a
        // subject's data object.
        $this->assertCount(1, $objectService->saved);
        $this->assertSame('dsr-1', $objectService->saved[0]['id']);
        $this->assertSame('in_behandeling', $objectService->saved[0]['status']);
        $this->assertNotNull($objectService->saved[0]['retainedObjectRefs'], 'The preview marker is always recorded, even for an empty retained list.');

    }//end testPreviewErasurePerformsZeroWrites()


    /**
     * REQ-DSR-005: a populated `retainedUntil` in the PAST wins over the AWR
     * fallback derivation -- classified erase-eligible, not retained.
     *
     * @return void
     */
    public function testPopulatedPastRetainedUntilWinsOverDerivedFallback(): void
    {
        $lapsed = $this->fakeEntity(
            8001,
            ['retainedUntil' => date('Y-m-d', strtotime('-1 year')), 'period' => date('Y-m')],
            'lapsed-uuid',
            'Payslip'
        );

        $objectService = $this->fakeObjectService(['emp-1' => $this->fakeEntity(1, ['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee')]);
        $dsarService   = $this->fakeDsarService([['object' => $lapsed->jsonSerialize()]]);

        $service        = $this->buildService($objectService, $dsarService);
        $classification = $service->classifyForErasure('emp-1');

        $this->assertSame([], $classification['retained']);
        $this->assertCount(1, $classification['eligible']);
        $this->assertSame('lapsed-uuid', $classification['eligible'][0]['uuid']);

    }//end testPopulatedPastRetainedUntilWinsOverDerivedFallback()

    /**
     * REQ-DSR-005: an object outside the payroll/loonadministratie family
     * with no populated retention field is NOT retention-locked, even with a
     * period-shaped field -- the family list is a closed set.
     *
     * @return void
     */
    public function testObjectOutsideFamilyWithNoRetentionFieldIsEligible(): void
    {
        $offRegisterFamily = $this->fakeEntity(9001, ['period' => date('Y-m')], 'off-family-uuid', 'Timesheet');

        $objectService = $this->fakeObjectService(['emp-1' => $this->fakeEntity(1, ['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee')]);
        $dsarService   = $this->fakeDsarService([['object' => $offRegisterFamily->jsonSerialize()]]);

        $service        = $this->buildService($objectService, $dsarService);
        $classification = $service->classifyForErasure('emp-1');

        $this->assertSame([], $classification['retained']);
        $this->assertCount(1, $classification['eligible']);

    }//end testObjectOutsideFamilyWithNoRetentionFieldIsEligible()


    /**
     * REQ-DSR-003: `exportForSubject()` calls `findObjectsForSubject()`
     * exactly once per export request, for either right.
     *
     * @return void
     */
    public function testExportCallsFindObjectsForSubjectExactlyOncePerRight(): void
    {
        $matched = $this->fakeEntity(1, [], 'matched-uuid', 'Payslip');

        $objectService = $this->fakeObjectService(['emp-1' => $this->fakeEntity(1, ['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee')]);
        $dsarService   = $this->fakeDsarService([['object' => $matched->jsonSerialize(), 'gdprEntities' => []]]);

        $service = $this->buildService($objectService, $dsarService);

        $inzage = $service->exportForSubject('emp-1', 'inzage');
        $this->assertSame(1, $dsarService->findCallCount);
        $this->assertSame('inzage', $inzage['right']);
        $this->assertSame(1, $inzage['count']);

        $portabiliteit = $service->exportForSubject('emp-1', 'portabiliteit');
        $this->assertSame(2, $dsarService->findCallCount, 'A SECOND export call makes exactly one MORE findObjectsForSubject() call.');
        $this->assertSame('portabiliteit', $portabiliteit['right']);
        $this->assertSame(1, $portabiliteit['count']);

    }//end testExportCallsFindObjectsForSubjectExactlyOncePerRight()


    /**
     * REQ-DSR-007: rectification records only the changed field NAMES, never
     * before/after values, and applies no retention guard.
     *
     * @return void
     */
    public function testRectifyRecordsOnlyFieldNamesNoRetentionGuard(): void
    {
        $objectService = $this->fakeObjectService(
            ['dsr-1' => $this->fakeEntity(2, ['status' => 'in_behandeling'], 'dsr-1', 'DsrRequest')]
        );
        $dsarService = $this->fakeDsarService([]);
        $dsarService->rectifyResults[999] = ['id' => 'emp-1-uuid', 'lastName' => 'Corrected'];

        $service = $this->buildService($objectService, $dsarService);
        $result  = $service->rectifySubjectObject(999, ['lastName' => 'Corrected'], 'dsr-1');

        $this->assertNotNull($result);
        $this->assertCount(1, $dsarService->rectifyCalls);
        $this->assertSame(999, $dsarService->rectifyCalls[0]['objectId']);

        $saved = $objectService->saved[0];
        $this->assertSame('voldaan', $saved['status']);
        $this->assertStringContainsString('lastName', $saved['outcomeSummary']);
        $this->assertStringNotContainsString('Corrected', $saved['outcomeSummary'], 'Only the field NAME is recorded, never the new value.');

    }//end testRectifyRecordsOnlyFieldNamesNoRetentionGuard()


    /**
     * REQ-DSR-007: a failed rectification (rectifyObjectForSubject returns
     * null) is reported -- DsrRequest -> afgewezen with a rejectionReason,
     * never silently dropped.
     *
     * @return void
     */
    public function testFailedRectificationIsReportedNotSilentlyDropped(): void
    {
        $objectService = $this->fakeObjectService(
            ['dsr-1' => $this->fakeEntity(2, ['status' => 'in_behandeling'], 'dsr-1', 'DsrRequest')]
        );
        $dsarService                      = $this->fakeDsarService([]);
        $dsarService->rectifyResults[999] = null;

        $service = $this->buildService($objectService, $dsarService);
        $result  = $service->rectifySubjectObject(999, ['lastName' => 'X'], 'dsr-1');

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
        $eligible = $this->fakeEntity(1, [], 'eligible-uuid', 'EmploymentContract');

        $objectService = $this->fakeObjectService(
            [
                'emp-1' => $this->fakeEntity(1, ['bsn' => self::SENTINEL_BSN], 'emp-1-uuid', 'Employee'),
                'dsr-1' => $this->fakeEntity(2, ['status' => 'in_behandeling', 'retainedObjectRefs' => '[]'], 'dsr-1', 'DsrRequest'),
            ]
        );
        $dsarService = $this->fakeDsarService([['object' => $eligible->jsonSerialize()]]);
        $logger      = $this->spyLogger();

        $service = $this->buildService($objectService, $dsarService, logger: $logger);

        $service->exportForSubject('emp-1', 'inzage', 'dsr-1');
        $service->previewErasure('emp-1', 'dsr-1');
        $service->eraseSubject('emp-1', 'dsr-1');

        foreach ($objectService->saved as $saved) {
            $encoded = json_encode($saved);
            $this->assertStringNotContainsString(self::SENTINEL_BSN, (string) $encoded, 'The raw bsn value must never be written onto a DsrRequest.');
        }

        foreach ($logger->messages as $message) {
            $this->assertStringNotContainsString(self::SENTINEL_BSN, $message, 'The raw bsn value must never be logged.');
        }

    }//end testBsnValueNeverPersistedOrLogged()


    /**
     * Build an AvgDsrService wired to fake ObjectService/DsarService doubles.
     *
     * @param object               $objectService The fake ObjectService.
     * @param object               $dsarService   The fake DsarService.
     * @param string               $currentUid    The active session uid.
     * @param LoggerInterface|null $logger        Optional logger double (a spy, or a plain mock when unused by the test).
     *
     * @return AvgDsrService
     */
    private function buildService(object $objectService, object $dsarService, string $currentUid='admin', ?LoggerInterface $logger=null): AvgDsrService
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService, $dsarService) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }

                if ($id === 'OCA\OpenRegister\Service\DsarService') {
                    return $dsarService;
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
     * A fake ObjectEntity double exposing the SAME `getId(): int` +
     * `jsonSerialize(): array` contract as OpenRegister's real ObjectEntity
     * (`id` overwritten with the string uuid, `@self` carries schema/register).
     *
     * @param int                   $internalId The real (int) primary key `rectifyObjectForSubject()` requires.
     * @param array<string, mixed>  $data       The object's own data fields.
     * @param string                $uuid       The object's string uuid.
     * @param string                $schema     The object's schema.
     * @param string                $register   The object's register.
     *
     * @return object
     */
    private function fakeEntity(int $internalId, array $data, string $uuid, string $schema, string $register='hrmq'): object
    {
        return new class ($internalId, $data, $uuid, $schema, $register) {


            /**
             * @param int                  $internalId Internal int id.
             * @param array<string, mixed> $data       Object data.
             * @param string               $uuid       String uuid.
             * @param string               $schema     Schema name.
             * @param string               $register   Register slug.
             */
            public function __construct(
                private readonly int $internalId,
                private readonly array $data,
                private readonly string $uuid,
                private readonly string $schema,
                private readonly string $register,
            ) {

            }//end __construct()


            /**
             * @return int
             */
            public function getId(): int
            {
                return $this->internalId;

            }//end getId()


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
     * A fake DsarService double: `findObjectsForSubject()` returns the
     * configured envelopes and increments `findCallCount`;
     * `eraseObjectsForSubject()`/`rectifyObjectForSubject()` record every
     * call.
     *
     * @param array<int, array<string, mixed>> $envelopes The envelopes findObjectsForSubject() returns.
     *
     * @return object
     */
    private function fakeDsarService(array $envelopes): object
    {
        return new class ($envelopes) {


            /**
             * @var array<int, array<string, mixed>>
             */
            private array $envelopes;

            /**
             * @var int
             */
            public int $findCallCount = 0;

            /**
             * @var array<int, array<string, mixed>>
             */
            public array $eraseCalls = [];

            /**
             * @var array<int, array{objectId: int, changes: array<string, mixed>}>
             */
            public array $rectifyCalls = [];

            /**
             * Per-objectId configured rectifyObjectForSubject() return value.
             *
             * @var array<int, array<string, mixed>|null>
             */
            public array $rectifyResults = [];


            /**
             * @param array<int, array<string, mixed>> $envelopes Configured envelopes.
             */
            public function __construct(array $envelopes)
            {
                $this->envelopes = $envelopes;

            }//end __construct()


            /**
             * @param string      $subject Subject value.
             * @param string|null $type    Unused by the fake.
             * @param string      $mode    Unused by the fake.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findObjectsForSubject(string $subject, ?string $type=null, string $mode='exact'): array
            {
                $this->findCallCount++;
                return $this->envelopes;

            }//end findObjectsForSubject()


            /**
             * @param string      $subject Subject value.
             * @param string|null $type    Type filter.
             * @param bool        $dryRun  Dry-run flag.
             *
             * @return array<string, mixed>
             */
            public function eraseObjectsForSubject(string $subject, ?string $type=null, bool $dryRun=false): array
            {
                $this->eraseCalls[] = ['subject' => $subject, 'type' => $type, 'dryRun' => $dryRun];
                return [
                    'subject'      => $subject,
                    'type'         => $type,
                    'dryRun'       => $dryRun,
                    'matchedCount' => count($this->envelopes),
                    'erased'       => array_map(
                        static fn(array $e): array => [
                            'uuid'     => (string) ($e['object']['id'] ?? ''),
                            'register' => (string) ($e['object']['@self']['register'] ?? ''),
                            'schema'   => (string) ($e['object']['@self']['schema'] ?? ''),
                        ],
                        $this->envelopes
                    ),
                    'failed'       => [],
                    'complete'     => true,
                    'failedCount'  => 0,
                ];

            }//end eraseObjectsForSubject()


            /**
             * @param int                   $objectId Internal id.
             * @param array<string, mixed>  $changes  Changes.
             *
             * @return array<string, mixed>|null
             */
            public function rectifyObjectForSubject(int $objectId, array $changes): ?array
            {
                $this->rectifyCalls[] = ['objectId' => $objectId, 'changes' => $changes];
                if (array_key_exists($objectId, $this->rectifyResults) === true) {
                    return $this->rectifyResults[$objectId];
                }

                return ['id' => $objectId, 'updated' => true];

            }//end rectifyObjectForSubject()
        };

    }//end fakeDsarService()


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
