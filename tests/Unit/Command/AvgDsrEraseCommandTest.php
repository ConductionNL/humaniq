<?php

/**
 * Unit tests for AvgDsrEraseCommand.
 *
 * Pins the CLI contract (avg-dsr design.md D3/D5/D6, REQ-DSR-004/-005/-006):
 * an unresolvable `--as-user` is refused with a one-line controlled message
 * and exit 1, and `AvgDsrService` is NEVER called (never an uncaught throw,
 * never a silent skip); `--confirm` REQUIRES `--dsr-request-id`; a bare
 * invocation ALWAYS previews (zero writes, `previewErasure()` only);
 * `--confirm` executes `eraseSubject()`, and the service's own "no recorded
 * preview" refusal surfaces as a controlled exit 1, never a crash. Drives
 * the command through a REAL `PrivilegedSessionResolver` backed by mocked
 * `IUserManager`/`IGroupManager`/`IUserSession` (the resolver is `final` and
 * therefore cannot be doubled directly) plus a mocked `AvgDsrService`.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Command
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Command;

use OCA\Hrmq\Command\AvgDsrEraseCommand;
use OCA\Hrmq\Command\PrivilegedSessionResolver;
use OCA\Hrmq\Service\AvgDsrService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for AvgDsrEraseCommand.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
 */
class AvgDsrEraseCommandTest extends TestCase
{


    /**
     * REQ-DSR-004: an unresolvable --as-user is refused BEFORE any
     * AvgDsrService call, exit 1, never an uncaught throw.
     *
     * @return void
     */
    public function testUnresolvableAsUserRefusedBeforeAnyServiceCall(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->never())->method('previewErasure');
        $service->expects($this->never())->method('eraseSubject');

        $command = new AvgDsrEraseCommand($service, $this->failingSessionResolver());
        $exit    = $this->runCommand($command, ['--employee' => 'emp-1', '--as-user' => 'nonexistent-uid']);

        $this->assertSame(1, $exit);

    }//end testUnresolvableAsUserRefusedBeforeAnyServiceCall()


    /**
     * REQ-DSR-006: --confirm without --dsr-request-id is refused before any
     * session establishment or service call (asserted here by proving
     * IUserManager::get() -- the first thing establish() would do -- is
     * never even reached).
     *
     * @return void
     */
    public function testConfirmWithoutDsrRequestIdRefused(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->never())->method('eraseSubject');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->expects($this->never())->method('get');

        $sessionResolver = new PrivilegedSessionResolver($userManager, $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class));

        $command = new AvgDsrEraseCommand($service, $sessionResolver);
        $exit    = $this->runCommand($command, ['--employee' => 'emp-1', '--as-user' => 'admin', '--confirm' => true]);

        $this->assertSame(1, $exit);

    }//end testConfirmWithoutDsrRequestIdRefused()


    /**
     * REQ-DSR-006: a bare invocation (no --confirm) ALWAYS previews -- ONLY
     * `previewErasure()` is called, never `eraseSubject()`.
     *
     * @return void
     */
    public function testBareInvocationAlwaysPreviews(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->once())
            ->method('previewErasure')
            ->with('emp-1', 'dsr-1')
            ->willReturn(['wouldErase' => [], 'retained' => [], 'failed' => []]);
        $service->expects($this->never())->method('eraseSubject');

        $command = new AvgDsrEraseCommand($service, $this->succeedingSessionResolver());
        $exit    = $this->runCommand($command, ['--employee' => 'emp-1', '--as-user' => 'admin', '--dsr-request-id' => 'dsr-1']);

        $this->assertSame(0, $exit);

    }//end testBareInvocationAlwaysPreviews()


    /**
     * REQ-DSR-006: --confirm with a valid --dsr-request-id executes
     * `eraseSubject()` (never `previewErasure()`).
     *
     * @return void
     */
    public function testConfirmExecutesErase(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->never())->method('previewErasure');
        $service->expects($this->once())
            ->method('eraseSubject')
            ->with('emp-1', 'dsr-1')
            ->willReturn(['status' => 'voldaan', 'erased' => [], 'retained' => [], 'failed' => []]);

        $command = new AvgDsrEraseCommand($service, $this->succeedingSessionResolver());
        $exit    = $this->runCommand(
            $command,
            ['--employee' => 'emp-1', '--as-user' => 'admin', '--dsr-request-id' => 'dsr-1', '--confirm' => true]
        );

        $this->assertSame(0, $exit);

    }//end testConfirmExecutesErase()


    /**
     * REQ-DSR-006: the service's own "no recorded preview" refusal surfaces
     * as a controlled exit 1, never a crash.
     *
     * @return void
     */
    public function testServiceRefusalSurfacesAsControlledExitOne(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->method('eraseSubject')->willReturn(['status' => 'refused', 'message' => 'Geen geregistreerd voorbeeld gevonden.']);

        $command = new AvgDsrEraseCommand($service, $this->succeedingSessionResolver());
        $exit    = $this->runCommand(
            $command,
            ['--employee' => 'emp-1', '--as-user' => 'admin', '--dsr-request-id' => 'dsr-1', '--confirm' => true]
        );

        $this->assertSame(1, $exit);

    }//end testServiceRefusalSurfacesAsControlledExitOne()


    /**
     * REQ-DSR-004: a RuntimeException from the service (defense-in-depth
     * against a stale race) is caught, never an uncaught throw, and exits 1.
     *
     * @return void
     */
    public function testRuntimeExceptionFromServiceIsCaughtNotUncaught(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->method('previewErasure')->willThrowException(new \RuntimeException('DSAR operations require administrator privileges'));

        $command = new AvgDsrEraseCommand($service, $this->succeedingSessionResolver());
        $exit    = $this->runCommand($command, ['--employee' => 'emp-1', '--as-user' => 'admin']);

        $this->assertSame(1, $exit);

    }//end testRuntimeExceptionFromServiceIsCaughtNotUncaught()


    /**
     * A REAL PrivilegedSessionResolver whose establish() always fails
     * (unknown uid) -- `IUserManager::get()` returns null.
     *
     * @return PrivilegedSessionResolver
     */
    private function failingSessionResolver(): PrivilegedSessionResolver
    {
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn(null);

        return new PrivilegedSessionResolver($userManager, $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class));

    }//end failingSessionResolver()


    /**
     * A REAL PrivilegedSessionResolver whose establish() always succeeds --
     * resolves a real admin and calls `IUserSession::setUser()`.
     *
     * @return PrivilegedSessionResolver
     */
    private function succeedingSessionResolver(): PrivilegedSessionResolver
    {
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('admin');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($admin);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        return new PrivilegedSessionResolver($userManager, $groupManager, $this->createMock(IUserSession::class));

    }//end succeedingSessionResolver()


    /**
     * Run a command with the given options via a plain ArrayInput/BufferedOutput
     * pair (no CommandTester dependency).
     *
     * @param AvgDsrEraseCommand    $command The command under test.
     * @param array<string, mixed> $options The `--option` => value map.
     *
     * @return int The exit code.
     */
    private function runCommand(AvgDsrEraseCommand $command, array $options): int
    {
        return $command->run(new ArrayInput($options, $command->getDefinition()), new BufferedOutput());

    }//end runCommand()


}//end class
