<?php

/**
 * Unit tests for AvgDsrExportCommand.
 *
 * Pins the CLI export contract (avg-dsr design.md D2/D3, REQ-DSR-003/-004):
 * an unresolvable `--as-user` is refused BEFORE any `AvgDsrService` call
 * (never an uncaught throw); a valid admin `--as-user` establishes the
 * session and the export renders for the requested right. Drives the
 * command through a REAL `PrivilegedSessionResolver` backed by mocked
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
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-003
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Command;

use OCA\Hrmq\Command\AvgDsrExportCommand;
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
 * Tests for AvgDsrExportCommand.
 *
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
 */
class AvgDsrExportCommandTest extends TestCase
{


    /**
     * REQ-DSR-004: an unresolvable --as-user is refused BEFORE any
     * AvgDsrService call, exit 1.
     *
     * @return void
     */
    public function testUnresolvableAsUserRefusedBeforeAnyServiceCall(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->never())->method('exportForSubject');

        $command = new AvgDsrExportCommand($service, $this->failingSessionResolver());
        $exit    = $this->runCommand($command, ['--employee' => 'emp-1', '--as-user' => 'regular-user']);

        $this->assertSame(1, $exit);

    }//end testUnresolvableAsUserRefusedBeforeAnyServiceCall()


    /**
     * An invalid --right is refused before any session establishment
     * (asserted by proving IUserManager::get() -- the first thing
     * establish() would do -- is never even reached).
     *
     * @return void
     */
    public function testInvalidRightRefusedBeforeSessionEstablishment(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->never())->method('exportForSubject');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->expects($this->never())->method('get');
        $sessionResolver = new PrivilegedSessionResolver($userManager, $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class));

        $command = new AvgDsrExportCommand($service, $sessionResolver);
        $exit    = $this->runCommand($command, ['--employee' => 'emp-1', '--as-user' => 'admin', '--right' => 'onzin']);

        $this->assertSame(1, $exit);

    }//end testInvalidRightRefusedBeforeSessionEstablishment()


    /**
     * REQ-DSR-004: a valid admin --as-user establishes the session and the
     * export runs successfully for the requested right.
     *
     * @return void
     */
    public function testValidAdminEstablishesSessionAndExports(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->once())
            ->method('exportForSubject')
            ->with('emp-1', 'portabiliteit', null)
            ->willReturn(['right' => 'portabiliteit', 'generated' => '2026-07-16T00:00:00+00:00', 'count' => 0, 'objects' => []]);

        $command = new AvgDsrExportCommand($service, $this->succeedingSessionResolver());
        $exit    = $this->runCommand($command, ['--employee' => 'emp-1', '--as-user' => 'admin', '--right' => 'portabiliteit']);

        $this->assertSame(0, $exit);

    }//end testValidAdminEstablishesSessionAndExports()


    /**
     * A REAL PrivilegedSessionResolver whose establish() always fails
     * (unknown/non-admin uid) -- `IGroupManager::isAdmin()` returns false.
     *
     * @return PrivilegedSessionResolver
     */
    private function failingSessionResolver(): PrivilegedSessionResolver
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('regular-user');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);

        return new PrivilegedSessionResolver($userManager, $groupManager, $this->createMock(IUserSession::class));

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
     * pair.
     *
     * @param AvgDsrExportCommand   $command The command under test.
     * @param array<string, mixed> $options The `--option` => value map.
     *
     * @return int The exit code.
     */
    private function runCommand(AvgDsrExportCommand $command, array $options): int
    {
        return $command->run(new ArrayInput($options, $command->getDefinition()), new BufferedOutput());

    }//end runCommand()


}//end class
