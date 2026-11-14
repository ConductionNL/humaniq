<?php

/**
 * Golden tests for AvgDsrRectifyCommand's outcomes.
 *
 * Pins every refused and the allowed outcome of `humaniq:avg:rectify` with
 * its exact output: a missing --employee, --dsr-request-id or a non-object
 * --changes is refused before the privileged session is established; a
 * refused session, an employee the session cannot resolve, a refused or
 * failed rectification each exit 1 with their own message; a rectification
 * lists the changed fields.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Command
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\AvgDsrRectifyCommand;
use OCA\Humaniq\Command\PrivilegedSessionResolver;
use OCA\Humaniq\Service\AvgDsrService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * AvgDsrRectifyCommand outcomes.
 */
class AvgDsrRectifyCommandOutcomesTest extends TestCase {

	/**
	 * @var AvgDsrService&MockObject
	 */
	private AvgDsrService $service;

	/**
	 * How often the privileged session was asked for a user.
	 *
	 * @var int
	 */
	private int $sessionLookups = 0;

	/**
	 * The ids the register was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $finds = [];

	/**
	 * Run the command and answer [exit code, output].
	 *
	 * @param array<string, string> $options The command options.
	 * @param mixed $employee What the register answers for the employee: an object, null, or a Throwable to throw.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function rectify(array $options, mixed $employee = null): array {
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid) use ($admin): ?IUser {
				$this->sessionLookups++;
				return ($uid === 'admin' ? $admin : null);
			}
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);
		$sessions = new PrivilegedSessionResolver($userManager, $groupManager, $this->createMock(IUserSession::class));

		$register = new class($employee, $this->finds) {
			/**
			 * @param mixed $employee The answer for every find.
			 * @param array<int, string> $finds The ids asked for (by reference).
			 */
			public function __construct(
				private mixed $employee,
				private array &$finds,
			) {
			}//end __construct()

			/**
			 * Accepts whatever find signature the command uses.
			 *
			 * @param string $id The object id.
			 * @param mixed ...$rest Register/schema arguments (unused).
			 *
			 * @return object|null
			 */
			public function find(string $id, mixed ...$rest): ?object {
				$this->finds[] = $id;
				if ($this->employee instanceof \Throwable) {
					throw $this->employee;
				}

				return $this->employee;
			}//end find()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($register);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$command = new AvgDsrRectifyCommand($this->service, $sessions, $container, $settings);
		$output = new BufferedOutput();
		$exit = $command->run(new ArrayInput($options, $command->getDefinition()), $output);

		return [$exit, $output->fetch()];
	}//end rectify()

	/**
	 * A mock service that must never be called.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(AvgDsrService::class);
	}//end setUp()

	/**
	 * Missing or malformed options are refused before the session is
	 * established and before any lookup.
	 *
	 * @return void
	 */
	public function testInvalidOptionsAreRefusedBeforeTheSession(): void {
		$this->service->expects($this->never())->method('rectifySubjectObject');
		$valid = ['--employee' => 'emp-1', '--as-user' => 'admin', '--changes' => '{"lastName":"X"}', '--dsr-request-id' => 'dsr-1'];

		self::assertSame([1, "--employee is verplicht.\n"], $this->rectify(array_merge($valid, ['--employee' => '  '])));
		self::assertSame([1, "--dsr-request-id is verplicht.\n"], $this->rectify(array_merge($valid, ['--dsr-request-id' => ' '])));
		self::assertSame([1, "--changes moet een niet-lege JSON-object zijn.\n"], $this->rectify(array_merge($valid, ['--changes' => '{}'])));
		self::assertSame([1, "--changes moet een niet-lege JSON-object zijn.\n"], $this->rectify(array_merge($valid, ['--changes' => '"tekst"'])));
		self::assertSame(0, $this->sessionLookups);
		self::assertSame([], $this->finds);
	}//end testInvalidOptionsAreRefusedBeforeTheSession()

	/**
	 * A refused session and an unresolvable employee never reach the service.
	 *
	 * @return void
	 */
	public function testRefusedSessionAndUnknownEmployeeNeverReachTheService(): void {
		$this->service->expects($this->never())->method('rectifySubjectObject');
		$options = ['--employee' => ' emp-1 ', '--as-user' => 'admin', '--changes' => '{"lastName":"X"}', '--dsr-request-id' => 'dsr-1'];

		[$exit] = $this->rectify(array_merge($options, ['--as-user' => 'nobody']), new \stdClass());
		self::assertSame(1, $exit);
		self::assertSame([], $this->finds);

		self::assertSame([1, "Werknemer niet gevonden.\n"], $this->rectify($options, null));
		self::assertSame([1, "Werknemer niet gevonden.\n"], $this->rectify($options, new \RuntimeException('register weg')));
		self::assertSame(['emp-1', 'emp-1'], $this->finds);
	}//end testRefusedSessionAndUnknownEmployeeNeverReachTheService()

	/**
	 * A refused or failed rectification exits 1 with its own message.
	 *
	 * @return void
	 */
	public function testRefusedAndFailedRectificationsExitOne(): void {
		$options = ['--employee' => 'emp-1', '--as-user' => 'admin', '--changes' => '{"lastName":"X"}', '--dsr-request-id' => 'dsr-1'];

		$this->service->method('rectifySubjectObject')->willThrowException(new \RuntimeException('Geen bevoegde sessie.'));
		self::assertSame([1, "Geen bevoegde sessie.\n"], $this->rectify($options, new \stdClass()));

		$this->service = $this->createMock(AvgDsrService::class);
		$this->service->method('rectifySubjectObject')->willReturn(null);
		self::assertSame([1, "Rectificatie mislukt -- object kon niet worden geladen of bijgewerkt.\n"], $this->rectify($options, new \stdClass()));
	}//end testRefusedAndFailedRectificationsExitOne()

	/**
	 * A rectification of the resolved employee lists the changed fields.
	 *
	 * @return void
	 */
	public function testRectificationListsTheChangedFields(): void {
		$this->service->expects($this->once())->method('rectifySubjectObject')
			->with('emp-1', ['lastName' => 'Jansen', 'city' => 'Utrecht'], 'dsr-1')
			->willReturn(['id' => 'emp-1']);

		$result = $this->rectify(
			['--employee' => ' emp-1 ', '--as-user' => 'admin', '--changes' => '{"lastName":"Jansen","city":"Utrecht"}', '--dsr-request-id' => ' dsr-1 '],
			new \stdClass()
		);

		self::assertSame([0, "Humaniq AVG-rectificatie toegepast\n  veld(en): lastName, city\n"], $result);
	}//end testRectificationListsTheChangedFields()
}//end class
