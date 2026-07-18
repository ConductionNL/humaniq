<?php

/**
 * Unit tests for AvgDsrRectifyCommand.
 *
 * Pins the CLI rectify contract (avg-dsr design.md D3/D6, REQ-DSR-004/-007):
 * an unresolvable `--as-user` is refused BEFORE any resolve or
 * `AvgDsrService` call; a valid rectification succeeds. Drives the command
 * through a REAL `PrivilegedSessionResolver` backed by mocked
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Command;

use OCA\Hrmq\Command\AvgDsrRectifyCommand;
use OCA\Hrmq\Command\PrivilegedSessionResolver;
use OCA\Hrmq\Service\AvgDsrService;
use OCA\Hrmq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for AvgDsrRectifyCommand.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
 */
class AvgDsrRectifyCommandTest extends TestCase
{


    /**
     * REQ-DSR-004: an unresolvable --as-user is refused BEFORE any resolve or
     * AvgDsrService call.
     *
     * @return void
     */
    public function testUnresolvableAsUserRefusedBeforeAnyResolve(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->never())->method('rectifySubjectObject');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');

        $settings = $this->createMock(SettingsService::class);

        $command = new AvgDsrRectifyCommand($service, $this->failingSessionResolver(), $container, $settings);
        $exit    = $this->runCommand(
            $command,
            ['--employee' => 'emp-1', '--as-user' => 'ghost', '--changes' => '{"lastName":"X"}', '--dsr-request-id' => 'dsr-1']
        );

        $this->assertSame(1, $exit);

    }//end testUnresolvableAsUserRefusedBeforeAnyResolve()


    /**
     * Invalid (empty/non-object) --changes JSON is refused before any
     * session establishment (asserted by proving IUserManager::get() -- the
     * first thing establish() would do -- is never even reached).
     *
     * @return void
     */
    public function testInvalidChangesJsonRefusedBeforeSessionEstablishment(): void
    {
        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->never())->method('rectifySubjectObject');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->expects($this->never())->method('get');
        $sessionResolver = new PrivilegedSessionResolver($userManager, $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class));

        $container = $this->createMock(ContainerInterface::class);
        $settings  = $this->createMock(SettingsService::class);

        $command = new AvgDsrRectifyCommand($service, $sessionResolver, $container, $settings);
        $exit    = $this->runCommand(
            $command,
            ['--employee' => 'emp-1', '--as-user' => 'admin', '--changes' => 'not-json', '--dsr-request-id' => 'dsr-1']
        );

        $this->assertSame(1, $exit);

    }//end testInvalidChangesJsonRefusedBeforeSessionEstablishment()


    /**
     * hrmq#99: a valid rectification RBAC-resolves the employee (existence +
     * access) and calls `rectifySubjectObject()` with the employeeId STRING
     * directly -- no internal-int-id resolution workaround (the guarded
     * `Gdpr\DataSubjectRequestService::rectify()` takes a plain id/uuid
     * string).
     *
     * @return void
     */
    public function testValidRectificationResolvesEmployeeAndCallsService(): void
    {
        $employeeEntity = new class {
        };

        $service = $this->createMock(AvgDsrService::class);
        $service->expects($this->once())
            ->method('rectifySubjectObject')
            ->with('emp-1', ['lastName' => 'Corrected'], 'dsr-1')
            ->willReturn(['id' => 'emp-1-uuid', 'lastName' => 'Corrected']);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn(
            new class ($employeeEntity) {

                public function __construct(private object $employeeEntity)
                {
                }

                public function find(string $id, ?string $register=null, ?string $schema=null): ?object
                {
                    return $this->employeeEntity;
                }
            }
        );

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');

        $command = new AvgDsrRectifyCommand($service, $this->succeedingSessionResolver(), $container, $settings);
        $exit    = $this->runCommand(
            $command,
            ['--employee' => 'emp-1', '--as-user' => 'admin', '--changes' => '{"lastName":"Corrected"}', '--dsr-request-id' => 'dsr-1']
        );

        $this->assertSame(0, $exit);

    }//end testValidRectificationResolvesEmployeeAndCallsService()


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
     * pair.
     *
     * @param AvgDsrRectifyCommand  $command The command under test.
     * @param array<string, mixed> $options The `--option` => value map.
     *
     * @return int The exit code.
     */
    private function runCommand(AvgDsrRectifyCommand $command, array $options): int
    {
        return $command->run(new ArrayInput($options, $command->getDefinition()), new BufferedOutput());

    }//end runCommand()


}//end class
