<?php

/**
 * Unit tests for OfferController.
 *
 * Pins the guarded `requestSignature()` endpoint contract (REQ-OFFR-007): a
 * non-admin/HR caller is refused 403 BEFORE any ObjectService resolve or
 * service call, an unknown/unauthorized `applicationId` collapses to 404
 * before any docudesk call, a resolved Application not in status `aanbod` is
 * refused 400 before any service call, and the happy path delegates to
 * `OfferEsignService::requestSignature()` with the caller's uid. Drives the
 * controller through a fake ObjectService double (a fake collaborator, not a
 * fake of the logic under test) plus a mocked `OfferEsignService`, mirroring
 * the `LoonbeslagControllerTest`/`DocumentController` precedent.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Controller
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
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\OfferController;
use OCA\Hrmq\Service\OfferEsignService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for OfferController.
 *
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-007
 */
class OfferControllerTest extends TestCase
{


    /**
     * An aanbod-stage Application fixture, overridable per test.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function application(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'            => 'app-1',
                'candidateName' => 'Sanne Voorbeeld',
                'status'        => 'aanbod',
            ],
            $overrides
        );

    }//end application()


    /**
     * REQ-OFFR-007 Scenario "Non-admin/non-HR caller rejected before any
     * resolve".
     *
     * @return void
     */
    public function testNonAdminCallerIsRefusedBeforeAnyResolveOrServiceCall(): void
    {
        [$controller, $fake, $offerEsignService] = $this->buildController(isAdmin: false, applicationRow: $this->application());
        $offerEsignService->expects($this->never())->method('requestSignature');

        $response = $controller->requestSignature('app-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertFalse($fake->findCalled, 'No ObjectService resolve occurs for a non-admin/HR caller.');

    }//end testNonAdminCallerIsRefusedBeforeAnyResolveOrServiceCall()


    /**
     * REQ-OFFR-007 Scenario "Unauthorized or unknown applicationId collapses
     * to 404".
     *
     * @return void
     */
    public function testUnknownOrUnauthorizedApplicationIdReturns404(): void
    {
        [$controller, , $offerEsignService] = $this->buildController(isAdmin: true, applicationRow: null);
        $offerEsignService->expects($this->never())->method('requestSignature');

        $response = $controller->requestSignature('app-ghost');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testUnknownOrUnauthorizedApplicationIdReturns404()


    /**
     * A missing applicationId is refused 400 before any resolve.
     *
     * @return void
     */
    public function testMissingApplicationIdReturns400BeforeAnyResolve(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, applicationRow: null);

        $response = $controller->requestSignature(null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($fake->findCalled);

    }//end testMissingApplicationIdReturns400BeforeAnyResolve()


    /**
     * REQ-OFFR-007 Scenario "Wrong-stage Application rejected by the
     * controller".
     *
     * @return void
     */
    public function testWrongStageApplicationReturns400WithNoServiceCall(): void
    {
        [$controller, , $offerEsignService] = $this->buildController(isAdmin: true, applicationRow: $this->application(['status' => 'screening']));
        $offerEsignService->expects($this->never())->method('requestSignature');

        $response = $controller->requestSignature('app-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testWrongStageApplicationReturns400WithNoServiceCall()


    /**
     * The happy path delegates to `OfferEsignService::requestSignature()`
     * with the resolved applicationId and the caller's uid, returning its
     * outcome verbatim.
     *
     * @return void
     */
    public function testHappyPathDelegatesToTheServiceWithCallerUid(): void
    {
        [$controller, , $offerEsignService] = $this->buildController(isAdmin: true, applicationRow: $this->application());

        $outcome = [
            'applicationId'         => 'app-1',
            'status'                => 'requested',
            'message'               => 'Aanbiedingsbrief gegenereerd en e-handtekeningaanvraag aangemaakt.',
            'offerLetterFileId'     => 42,
            'offerSigningRequestId' => 'req-1',
            'offerSigningStatus'    => 'PENDING',
        ];

        $offerEsignService->expects($this->once())
            ->method('requestSignature')
            ->with('app-1', 'hr-admin')
            ->willReturn($outcome);

        $response = $controller->requestSignature('app-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($outcome, $response->getData());

    }//end testHappyPathDelegatesToTheServiceWithCallerUid()


    /**
     * Build an `OfferController` with a fake container-resolved
     * ObjectService whose `find()` returns the fixed `$applicationRow` (null
     * simulating unknown/unauthorized) and a mocked `OfferEsignService`.
     *
     * @param bool                       $isAdmin        Whether the fake caller is an admin/HR principal.
     * @param array<string, mixed>|null $applicationRow The row `find()` should return.
     *
     * @return array{0: OfferController, 1: object, 2: OfferEsignService&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function buildController(bool $isAdmin, ?array $applicationRow): array
    {
        $request = $this->createMock(IRequest::class);

        $fake = new class ($applicationRow) {

            /**
             * @var array<string, mixed>|null
             */
            public ?array $row;

            /**
             * @var bool
             */
            public bool $findCalled = false;

            /**
             * @param array<string, mixed>|null $row The row find() should return.
             */
            public function __construct(?array $row)
            {
                $this->row = $row;

            }//end __construct()


            /**
             * @param string      $id       The object id.
             * @param string|null $register Register slug (unused by the fake).
             * @param string|null $schema   Schema name (unused by the fake).
             *
             * @return array<string, mixed>|null
             */
            public function find(string $id, ?string $register=null, ?string $schema=null): ?array
            {
                $this->findCalled = true;
                return $this->row;

            }//end find()


        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

        $offerEsignService = $this->createMock(OfferEsignService::class);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('hr-admin');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        $logger = $this->createMock(LoggerInterface::class);

        return [
            new OfferController($request, $container, $offerEsignService, $settings, $userSession, $groupManager, $logger),
            $fake,
            $offerEsignService,
        ];

    }//end buildController()


}//end class
