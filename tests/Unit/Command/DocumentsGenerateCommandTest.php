<?php

/**
 * Unit tests for DocumentsGenerateCommand.
 *
 * Pins the option parsing (an unknown --type refused before the service is
 * called, blank options passed on as null, values trimmed), the backlog report
 * lines and the exit code (1 when any attempt failed or was a usage error).
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
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\DocumentsGenerateCommand;
use OCA\Humaniq\Command\PrivilegedSessionResolver;
use OCA\Humaniq\Service\HrDocumentService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for DocumentsGenerateCommand.
 *
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
 */
class DocumentsGenerateCommandTest extends TestCase {

	/**
	 * An unknown --type is refused before the backlog is generated.
	 *
	 * @return void
	 */
	public function testUnknownTypeRefusedBeforeAnyServiceCall(): void {
		$service = $this->createMock(HrDocumentService::class);
		$service->expects($this->never())->method('generateBacklog');

		[$exit, $text] = $this->runCommand($service, ['--type' => ' memo ']);

		$this->assertSame(1, $exit);
		$this->assertSame("Onbekend documenttype \"memo\".\n", $text);

	}//end testUnknownTypeRefusedBeforeAnyServiceCall()

	/**
	 * Blank options reach the service as null and an empty backlog exits 0.
	 *
	 * @return void
	 */
	public function testBlankOptionsPassNullAndEmptyBacklogExitsZero(): void {
		$service = $this->createMock(HrDocumentService::class);
		$service->expects($this->once())
			->method('generateBacklog')
			->with(null, null, null, null)
			->willReturn([]);

		[$exit, $text] = $this->runCommand($service, ['--type' => '  ', '--employee' => '', '--period' => ' ']);

		$this->assertSame(0, $exit);
		$this->assertSame("Humaniq documentgeneratie\n  geen contracten geselecteerd voor de backlog.\n", $text);

	}//end testBlankOptionsPassNullAndEmptyBacklogExitsZero()

	/**
	 * Trimmed options reach the service; every outcome is reported and a failure exits 1.
	 *
	 * @return void
	 */
	public function testOutcomesReportedAndFailureExitsOne(): void {
		$service = $this->createMock(HrDocumentService::class);
		$service->expects($this->once())
			->method('generateBacklog')
			->with('loonstrook', 'emp-1', '2026-01', '2026')
			->willReturn([
				['employeeId' => 'emp-1', 'contractId' => 'c-1', 'documentType' => 'loonstrook', 'status' => 'generated', 'message' => 'ok'],
				['employeeId' => 'emp-1', 'contractId' => null, 'documentType' => 'loonstrook', 'status' => 'failed', 'message' => 'kapot'],
				['employeeId' => 'emp-2', 'contractId' => null, 'documentType' => 'loonstrook', 'status' => 'usage-error', 'message' => 'fout'],
			]);

		[$exit, $text] = $this->runCommand($service, ['--type' => ' loonstrook ', '--employee' => ' emp-1', '--period' => '2026-01 ', '--year' => '2026']);

		$this->assertSame(1, $exit);
		$this->assertSame(
			"Humaniq documentgeneratie\n"
			."  employee emp-1 / contract c-1 (loonstrook): generated — ok\n"
			."  employee emp-1 (loonstrook): failed — kapot\n"
			."  employee emp-2 (loonstrook): usage-error — fout\n"
			."  3 poging(en) verwerkt, 2 mislukt.\n",
			$text
		);

	}//end testOutcomesReportedAndFailureExitsOne()

	/**
	 * Only non-failing outcomes exit 0.
	 *
	 * @return void
	 */
	public function testCleanOutcomesExitZero(): void {
		$service = $this->createMock(HrDocumentService::class);
		$service->method('generateBacklog')->willReturn([
			['employeeId' => 'emp-1', 'contractId' => 'c-1', 'documentType' => 'arbeidsovereenkomst', 'status' => 'skipped-no-docudesk', 'message' => 'geen docudesk'],
		]);

		[$exit, $text] = $this->runCommand($service, []);

		$this->assertSame(0, $exit);
		$this->assertStringEndsWith("  1 poging(en) verwerkt, 0 mislukt.\n", $text);

	}//end testCleanOutcomesExitZero()

	/**
	 * `--as-user` names an administrator: the session is set before the
	 * service renders, so docudesk reads its templates as that user.
	 *
	 * @return void
	 */
	public function testAsUserSetsTheSessionBeforeRendering(): void {
		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('admin')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('admin')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$order = [];
		$session->expects($this->once())->method('setUser')->with($user)->willReturnCallback(static function () use (&$order): void {
			$order[] = 'session';
		});
		$service = $this->createMock(HrDocumentService::class);
		$service->expects($this->once())->method('generateBacklog')->willReturnCallback(static function () use (&$order): array {
			$order[] = 'render';
			return [];
		});

		[$exit] = $this->runCommand($service, ['--type' => 'werkgeversverklaring', '--employee' => 'e-1', '--as-user' => 'admin'], new PrivilegedSessionResolver($users, $groups, $session));

		$this->assertSame(0, $exit);
		$this->assertSame(['session', 'render'], $order);

	}//end testAsUserSetsTheSessionBeforeRendering()

	/**
	 * An `--as-user` that is not an administrator is refused before any
	 * rendering; without the option the command runs as before.
	 *
	 * @return void
	 */
	public function testANonAdminAsUserIsRefusedBeforeRendering(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$service = $this->createMock(HrDocumentService::class);
		$service->expects($this->never())->method('generateBacklog');

		[$exit, $text] = $this->runCommand($service, ['--as-user' => 'clerk'], new PrivilegedSessionResolver($users, $groups, $this->createMock(IUserSession::class)));

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('geen Nextcloud-beheerder', $text);

	}//end testANonAdminAsUserIsRefusedBeforeRendering()

	/**
	 * Run the command and return its exit code and output.
	 *
	 * @param HrDocumentService     $service The mocked service.
	 * @param array<string, string>          $options  The command options.
	 * @param PrivilegedSessionResolver|null $resolver The --as-user resolver; a fresh one when null.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function runCommand(HrDocumentService $service, array $options, ?PrivilegedSessionResolver $resolver = null): array {
		$resolver ??= new PrivilegedSessionResolver($this->createMock(IUserManager::class), $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class));
		$command  = new DocumentsGenerateCommand($service, $resolver);
		$output  = new BufferedOutput();
		$exit    = $command->run(new ArrayInput($options, $command->getDefinition()), $output);

		return [$exit, $output->fetch()];

	}//end runCommand()
}//end class
