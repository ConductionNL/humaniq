<?php

/**
 * Unit tests for LoonbeslagController.
 *
 * Pins the guarded activate/settle/withdraw endpoint contract (design.md D5,
 * spec.md REQ-BESLAG-006): the SAME two-gate shape as
 * `PayrollController::mutations()`/`wkrAssess()` -- a non-admin/HR caller is
 * refused 403 BEFORE any ObjectService resolve (no probe possible), an
 * unknown/unauthorized `loonbeslagId` collapses to 404, a wrong-status
 * precondition is refused 400 before any write, and the happy path stamps
 * the transition-specific by/at fields. Drives the controller through a fake
 * ObjectService double (a fake collaborator, not a fake of the logic under
 * test) since the real OpenRegister ObjectService is a sibling-app
 * dependency not available in this standalone suite.
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
 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\LoonbeslagController;
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
 * Tests for LoonbeslagController.
 *
 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
 */
class LoonbeslagControllerTest extends TestCase
{


    /**
     * A Loonbeslag fixture, overridable per test.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function loonbeslag(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'         => 'lb-1',
                'employeeId' => 'emp-1',
                'creditor'   => 'Gerechtsdeurwaarderskantoor Van Dijk',
                'dossierRef' => 'GDW-2026-00123',
                'status'     => 'concept',
            ],
            $overrides
        );

    }//end loonbeslag()


    /**
     * REQ-BESLAG-006 Scenario 1 — a non-admin/HR caller is refused BEFORE any
     * resolve, for every transition, including an unknown id.
     *
     * @return void
     */
    public function testNonAdminCallerIsRefusedBeforeAnyResolve(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: false, loonbeslagRow: null);

        $activate = $controller->activate('does-not-exist');
        $this->assertSame(Http::STATUS_FORBIDDEN, $activate->getStatus());

        $settle = $controller->settle('does-not-exist');
        $this->assertSame(Http::STATUS_FORBIDDEN, $settle->getStatus());

        $withdraw = $controller->withdraw('does-not-exist', 'reason');
        $this->assertSame(Http::STATUS_FORBIDDEN, $withdraw->getStatus());

        $this->assertFalse($fake->findCalled, 'No ObjectService resolve occurs for a non-admin/HR caller.');
        $this->assertSame([], $fake->saved);

    }//end testNonAdminCallerIsRefusedBeforeAnyResolve()


    /**
     * REQ-BESLAG-006 Scenario 2 — an unknown/unauthorized loonbeslagId never
     * reaches the write (404, collapsing unknown and unauthorized to the same
     * status).
     *
     * @return void
     */
    public function testUnknownOrUnauthorizedIdReturns404(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: null);

        $response = $controller->settle('lb-ghost');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame([], $fake->saved);

    }//end testUnknownOrUnauthorizedIdReturns404()


    /**
     * A missing loonbeslagId is refused 400 before any resolve.
     *
     * @return void
     */
    public function testMissingLoonbeslagIdReturns400BeforeAnyResolve(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: null);

        $response = $controller->activate(null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($fake->findCalled);

    }//end testMissingLoonbeslagIdReturns400BeforeAnyResolve()


    /**
     * REQ-BESLAG-006 Scenario 3 — settle refuses a non-actief Loonbeslag
     * (400), status unchanged.
     *
     * @return void
     */
    public function testSettleRefusesANonActiefLoonbeslag(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: $this->loonbeslag(['status' => 'concept']));

        $response = $controller->settle('lb-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame([], $fake->saved, 'No write occurs on a precondition failure.');

    }//end testSettleRefusesANonActiefLoonbeslag()


    /**
     * activate() refuses a non-concept Loonbeslag (400).
     *
     * @return void
     */
    public function testActivateRefusesANonConceptLoonbeslag(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: $this->loonbeslag(['status' => 'actief']));

        $response = $controller->activate('lb-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame([], $fake->saved);

    }//end testActivateRefusesANonConceptLoonbeslag()


    /**
     * The happy path: activate() stamps status=actief + activatedBy/At.
     *
     * @return void
     */
    public function testActivateHappyPathStampsTheTransition(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: $this->loonbeslag(['status' => 'concept']));

        $response = $controller->activate('lb-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $fake->saved);

        $saved = $fake->saved[0];
        $this->assertSame('actief', $saved['status']);
        $this->assertSame('hr-admin', $saved['activatedBy']);
        $this->assertNotEmpty($saved['activatedAt']);

        $data = $response->getData();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('actief', $data['loonbeslag']['status']);

    }//end testActivateHappyPathStampsTheTransition()


    /**
     * The happy path: settle() stamps status=voldaan + settledBy/At.
     *
     * @return void
     */
    public function testSettleHappyPathStampsTheTransition(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: $this->loonbeslag(['status' => 'actief']));

        $response = $controller->settle('lb-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $saved = $fake->saved[0];
        $this->assertSame('voldaan', $saved['status']);
        $this->assertSame('hr-admin', $saved['settledBy']);
        $this->assertNotEmpty($saved['settledAt']);

    }//end testSettleHappyPathStampsTheTransition()


    /**
     * withdraw() requires a non-empty reason (400, no write) before any
     * resolve is even attempted.
     *
     * @return void
     */
    public function testWithdrawRequiresANonEmptyReason(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: $this->loonbeslag(['status' => 'actief']));

        $response = $controller->withdraw('lb-1', null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame([], $fake->saved);
        $this->assertFalse($fake->findCalled);

    }//end testWithdrawRequiresANonEmptyReason()


    /**
     * withdraw() refuses an already-terminal (voldaan) Loonbeslag (400).
     *
     * @return void
     */
    public function testWithdrawRefusesAnAlreadyTerminalLoonbeslag(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: $this->loonbeslag(['status' => 'voldaan']));

        $response = $controller->withdraw('lb-1', 'Schikking getroffen.');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame([], $fake->saved);

    }//end testWithdrawRefusesAnAlreadyTerminalLoonbeslag()


    /**
     * The happy path: withdraw() stamps status=ingetrokken + withdrawnBy/At/
     * Reason, from either concept or actief.
     *
     * @return void
     */
    public function testWithdrawHappyPathStampsTheTransition(): void
    {
        [$controller, $fake] = $this->buildController(isAdmin: true, loonbeslagRow: $this->loonbeslag(['status' => 'actief']));

        $response = $controller->withdraw('lb-1', 'Schikking getroffen met de deurwaarder.');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $saved = $fake->saved[0];
        $this->assertSame('ingetrokken', $saved['status']);
        $this->assertSame('hr-admin', $saved['withdrawnBy']);
        $this->assertNotEmpty($saved['withdrawnAt']);
        $this->assertSame('Schikking getroffen met de deurwaarder.', $saved['withdrawnReason']);

    }//end testWithdrawHappyPathStampsTheTransition()


    /**
     * Build a `LoonbeslagController` with a fake container-resolved
     * ObjectService whose `find()` returns the fixed `$loonbeslagRow` (null
     * simulating unknown/unauthorized) and `saveObject()` records every
     * write.
     *
     * @param bool                       $isAdmin       Whether the fake caller is an admin/HR principal.
     * @param array<string, mixed>|null $loonbeslagRow The row `find()` should return.
     *
     * @return array{0: LoonbeslagController, 1: object}
     */
    private function buildController(bool $isAdmin, ?array $loonbeslagRow): array
    {
        $request = $this->createMock(IRequest::class);

        $fake = new class ($loonbeslagRow) {

            /**
             * @var array<string, mixed>|null
             */
            public ?array $row;

            /**
             * @var array<int, array<string, mixed>>
             */
            public array $saved = [];

            /**
             * @var bool
             */
            public bool $findCalled = false;

            /**
             * @var int
             */
            private int $nextId = 1;

            /**
             * @param array<string, mixed>|null $row The row find() should return.
             */
            public function __construct(?array $row)
            {
                $this->row = $row;
            }

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
            }

            /**
             * @param array<string, mixed> $object        The object to save.
             * @param string|null          $register      Register slug (unused by the fake).
             * @param string|null          $schema        Schema name (unused by the fake).
             * @param string|null          $uuid          Existing id when updating.
             * @param bool                 $_rbac         Unused by the fake.
             * @param bool                 $_multitenancy Unused by the fake.
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
                $id    = ($uuid ?? ('generated-'.$this->nextId++));
                $saved = array_merge($object, ['id' => $id]);
                $this->saved[] = $saved;
                return $saved;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('hr-admin');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        $logger = $this->createMock(LoggerInterface::class);

        return [new LoonbeslagController($request, $container, $settings, $userSession, $groupManager, $logger), $fake];

    }//end buildController()


}//end class
