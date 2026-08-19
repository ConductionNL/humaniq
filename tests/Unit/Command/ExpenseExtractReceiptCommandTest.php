<?php

/**
 * Unit tests for ExpenseExtractReceiptCommand.
 *
 * Pins the `--as-user` privileged-session fix (live-verified 2026-07-16
 * against docudesk 0.0.37, receipt-ocr design.md D7): before this fix the
 * command established NO Nextcloud user session, so docudesk's
 * `DocumentService::resolveFile()` saw `IUserSession::getUser() === null`
 * and OpenRegister's `saveObject()` RBAC rejected the resulting `Anonymous`
 * actor -- the command could never work. An unresolvable `--as-user` is
 * refused BEFORE any `ReceiptExtractionService::backlog()` call (never an
 * uncaught throw); a valid admin `--as-user` establishes the session and the
 * resolved uid is threaded through to `backlog()` as the acting user.
 * Mirrors the `AvgDsrExportCommandTest` pattern -- a REAL
 * `PrivilegedSessionResolver` (it is `final`, so cannot be doubled) backed
 * by mocked `IUserManager`/`IGroupManager`/`IUserSession`, plus a mocked
 * `ReceiptExtractionService`.
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
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Command;

use OCA\Hrmq\Command\ExpenseExtractReceiptCommand;
use OCA\Hrmq\Command\PrivilegedSessionResolver;
use OCA\Hrmq\Service\ReceiptExtractionService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for ExpenseExtractReceiptCommand.
 *
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-006
 */
class ExpenseExtractReceiptCommandTest extends TestCase {

	/**
	 * An unresolvable --as-user (unknown/non-admin uid) is refused BEFORE
	 * any ReceiptExtractionService::backlog() call, exit 1 -- the same
	 * "no Anonymous actor ever reaches docudesk/OR" contract as the
	 * hrmq:avg:* commands.
	 *
	 * @return void
	 */
	public function testUnresolvableAsUserRefusedBeforeAnyServiceCall(): void {
		$service = $this->createMock(ReceiptExtractionService::class);
		$service->expects($this->never())->method('backlog');

		$command = new ExpenseExtractReceiptCommand($service, $this->failingSessionResolver());
		$exit = $this->runCommand($command, ['--as-user' => 'regular-user']);

		$this->assertSame(1, $exit);

	}//end testUnresolvableAsUserRefusedBeforeAnyServiceCall()

	/**
	 * An empty --as-user is refused before any resolve is even attempted
	 * (IUserManager::get() -- the first thing establish() would do -- is
	 * never reached), exit 1.
	 *
	 * @return void
	 */
	public function testEmptyAsUserRefusedBeforeAnyServiceCall(): void {
		$service = $this->createMock(ReceiptExtractionService::class);
		$service->expects($this->never())->method('backlog');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->never())->method('get');
		$sessionResolver = new PrivilegedSessionResolver($userManager, $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class));

		$command = new ExpenseExtractReceiptCommand($service, $sessionResolver);
		$exit = $this->runCommand($command, []);

		$this->assertSame(1, $exit);

	}//end testEmptyAsUserRefusedBeforeAnyServiceCall()

	/**
	 * A valid admin --as-user establishes the session, the resolved uid is
	 * threaded through to backlog() as the acting user, and a clean
	 * (non-failed) outcome exits 0.
	 *
	 * @return void
	 */
	public function testValidAdminEstablishesSessionAndRunsBacklog(): void {
		$service = $this->createMock(ReceiptExtractionService::class);
		$service->expects($this->once())
			->method('backlog')
			->with(null, 'admin')
			->willReturn([['expenseId' => 'exp-1', 'status' => 'extracted', 'message' => 'ok', 'receiptExtractionId' => 're-1']]);

		$command = new ExpenseExtractReceiptCommand($service, $this->succeedingSessionResolver());
		$exit = $this->runCommand($command, ['--as-user' => 'admin']);

		$this->assertSame(0, $exit);

	}//end testValidAdminEstablishesSessionAndRunsBacklog()

	/**
	 * --expense narrows the backlog call to one Expense id, still passing
	 * the resolved --as-user uid through.
	 *
	 * @return void
	 */
	public function testExpenseOptionNarrowsBacklogCall(): void {
		$service = $this->createMock(ReceiptExtractionService::class);
		$service->expects($this->once())
			->method('backlog')
			->with('11ec50b9-8468-4932-8c4f-2b480765e3ed', 'admin')
			->willReturn([['expenseId' => '11ec50b9-8468-4932-8c4f-2b480765e3ed', 'status' => 'failed', 'message' => 'geen tekst geëxtraheerd', 'receiptExtractionId' => 're-2']]);

		$command = new ExpenseExtractReceiptCommand($service, $this->succeedingSessionResolver());
		$exit = $this->runCommand($command, ['--as-user' => 'admin', '--expense' => '11ec50b9-8468-4932-8c4f-2b480765e3ed']);

		$this->assertSame(1, $exit, 'A failed outcome still exits 1, even though the session was established fine.');

	}//end testExpenseOptionNarrowsBacklogCall()

	/**
	 * A REAL PrivilegedSessionResolver whose establish() always fails
	 * (unknown/non-admin uid) -- `IGroupManager::isAdmin()` returns false.
	 *
	 * @return PrivilegedSessionResolver
	 */
	private function failingSessionResolver(): PrivilegedSessionResolver {
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
	private function succeedingSessionResolver(): PrivilegedSessionResolver {
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
	 * @param ExpenseExtractReceiptCommand $command The command under test.
	 * @param array<string, mixed> $options The `--option` => value map.
	 *
	 * @return int The exit code.
	 */
	private function runCommand(ExpenseExtractReceiptCommand $command, array $options): int {
		return $command->run(new ArrayInput($options, $command->getDefinition()), new BufferedOutput());
	}//end runCommand()

}//end class
