<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two processes handing the same payroll run to shillinq at the same time
 * create one PaymentRun and one JournalEntry, not two. The fake register
 * runs a second process at the moment the first one is about to write
 * shillinq, or right after the first one read the run's batches, and the
 * test counts what reached shillinq.
 *
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use Fiber;
use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\PayrollNetPayService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Service\Support\InMemoryLocks;
use OCP\Lock\ILockingProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Concurrency of the net pay and GL post hand-offs.
 *
 * @covers \OCA\Humaniq\Service\PayrollNetPayService
 * @covers \OCA\Humaniq\Service\PayrollGLPostService
 * @uses   \OCA\Humaniq\Service\PayrollJournalTotals
 * @uses   \OCA\Humaniq\Service\PendingClaims
 * @uses   \OCA\Humaniq\Service\WagePeriod
 * @uses   \OCA\Humaniq\Service\HandoffLock
 */
class ShillinqHandoffConcurrencyTest extends TestCase {

	/**
	 * The two hand-offs: the service, its shillinq schema, its own record
	 * schema and the method that hands a run over.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function handoffs(): array {
		return [
			'net pay' => ['netpay', 'PaymentRun', 'PayrollPaymentBatch', 'processRun'],
			'GL post' => ['glpost', 'JournalEntry', 'PayrollGLPost', 'postRun'],
		];
	}//end handoffs()

	/**
	 * A second process that starts while the first is about to write
	 * shillinq finds the first one's fresh claim and writes nothing.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testASecondProcessDuringTheShillinqWriteCreatesNothing(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$second = null;
		$store->beforeSave = function (string $schema) use ($store, $kind, $target, $handOff, &$second): void {
			if ($schema === $target && $second === null) {
				$second = 'running';
				$second = $this->service($kind, $store)->$handOff($this->payrollRun());
			}
		};

		$first = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertCount(1, $store->savesOf($target), 'One record reaches shillinq.');
		$this->assertSame('pending', $second['status']);
		$this->assertContains($first['status'], ['created', 'posted']);
	}//end testASecondProcessDuringTheShillinqWriteCreatesNothing()

	/**
	 * A second process that read the run's records before the first one
	 * claimed it, and finishes first, makes the first one withdraw its claim.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAClaimNextToAnotherActiveRecordWithdraws(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$ran = false;
		$store->afterFindAll = function (string $schema) use ($store, $kind, $ownSchema, $handOff, &$ran): void {
			if ($schema === $ownSchema && $ran === false) {
				$ran = true;
				$this->service($kind, $store)->$handOff($this->payrollRun());
			}
		};

		$first = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertCount(1, $store->savesOf($target), 'One record reaches shillinq.');
		$this->assertSame('pending', $first['status']);
		$own = $store->savesOf($ownSchema);
		$this->assertSame('failed', end($own)['status'], 'The withdrawn claim is closed as failed.');
	}//end testAClaimNextToAnotherActiveRecordWithdraws()

	/**
	 * Two processes that both write their claim before either reads the
	 * run's records, and both read before either withdraws, see each
	 * other's claim, both withdraw, and nothing reaches shillinq. A process
	 * that read before it claimed would see no rival and write.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testTwoClaimsWrittenBeforeEitherCheckBothWithdraw(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$store->beforeSave = static function (string $schema): void {
			if (Fiber::getCurrent() !== null) {
				Fiber::suspend('save ' . $schema);
			}
		};
		$store->beforeFindAll = static function (string $schema): void {
			if (Fiber::getCurrent() !== null) {
				Fiber::suspend('read ' . $schema);
			}
		};
		$first = new Fiber(fn (): array => $this->service($kind, $store)->$handOff($this->payrollRun()));
		$second = new Fiber(fn (): array => $this->service($kind, $store)->$handOff($this->payrollRun()));

		$this->stepUntil($second, 'save ' . $ownSchema);
		$this->stepUntil($first, 'save ' . $ownSchema);
		$this->assertSame('read ' . $ownSchema, $first->resume(), 'The first claim is written.');
		$this->assertSame('read ' . $ownSchema, $second->resume(), 'The second claim is written.');
		$this->stepUntil($first, 'save ' . $ownSchema);
		$this->stepUntil($second, 'save ' . $ownSchema);
		$outcomes = [$this->finish($first), $this->finish($second)];

		$this->assertSame([], $store->savesOf($target), 'Nothing reaches shillinq.');
		$this->assertSame(['pending', 'pending'], array_column($outcomes, 'status'));
		$claims = $store->savesOf($ownSchema);
		$this->assertSame(['pending', 'pending', 'failed', 'failed'], array_column($claims, 'status'));
	}//end testTwoClaimsWrittenBeforeEitherCheckBothWithdraw()

	/**
	 * Runs a process until it pauses at the given step, before that save
	 * or read happens.
	 *
	 * @param Fiber  $process The process.
	 * @param string $step    The step, such as `save PayrollGLPost`.
	 *
	 * @return void
	 */
	private function stepUntil(Fiber $process, string $step): void {
		$at = ($process->isStarted() === false ? $process->start() : $process->resume());
		while ($at !== $step && $process->isTerminated() === false) {
			$at = $process->resume();
		}

		$this->assertSame($step, $at, 'The process reaches ' . $step . '.');
	}//end stepUntil()

	/**
	 * Runs a process to its end and returns its outcome.
	 *
	 * @param Fiber $process The process.
	 *
	 * @return array<string, mixed>
	 */
	private function finish(Fiber $process): array {
		while ($process->isTerminated() === false) {
			$process->resume();
		}

		return $process->getReturn();
	}//end finish()

	/**
	 * A run handed off by cron or occ, then again by a user whose active
	 * organisation is another one, reaches shillinq once: the hand-off
	 * reads its records outside the caller's organisation, as it writes
	 * them.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testACronRunAndAUserRunInAnotherOrganisationHandOffOnce(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();

		$first = $this->service($kind, $store)->$handOff($this->payrollRun());
		$store->organisation = 'other-org';
		$second = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($first['status'], ['created', 'posted']);
		$this->assertContains($second['status'], ['created', 'posted']);
		$this->assertCount(1, $store->savesOf($target), 'One record reaches shillinq.');
	}//end testACronRunAndAUserRunInAnotherOrganisationHandOffOnce()

	/**
	 * A user in another organisation sees the shillinq record a cron run
	 * wrote, and adopts it; and sees a cron run's fresh claim, and writes
	 * nothing.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAUserInAnotherOrganisationSeesWhatACronRunWrote(string $kind, string $target, string $ownSchema, string $handOff): void {
		$number = ($kind === 'netpay' ? 'runNumber' : 'journalNumber');
		$value = ($kind === 'netpay' ? 'HRMQ-NETPAY-2026-05-ADM-001' : 'HRMQ-LOON-2026-05-ADM-001');
		$store = $this->store();
		$store->rows[$target] = [['id' => 'by-cron', $number => $value, '_org' => 'default']];
		$store->organisation = 'other-org';

		$adopted = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($adopted['status'], ['created', 'posted']);
		$this->assertSame([], $store->savesOf($target), 'The cron run\'s shillinq record is adopted.');

		$store = $this->store();
		$store->rows[$ownSchema] = [['id' => 'cron-claim', 'payrollRunId' => 'run-1', 'status' => 'pending', $number => $value, '_org' => 'default', '@self' => ['id' => 'cron-claim', 'updated' => gmdate('c', time() - 60)]]];
		$store->organisation = 'other-org';

		$blocked = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertSame('pending', $blocked['status'], 'The cron run\'s fresh claim blocks the user run.');
		$this->assertSame([], $store->savesOf($target));
	}//end testAUserInAnotherOrganisationSeesWhatACronRunWrote()

	/**
	 * Many older records of other runs do not push a fresh claim, or an
	 * existing shillinq record, out of the read: the reads filter on the
	 * run's number in the query, not after a limited page.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testOlderRecordsBeyondOnePageDoNotHideTheRunsRecords(string $kind, string $target, string $ownSchema, string $handOff): void {
		$number = ($kind === 'netpay' ? 'runNumber' : 'journalNumber');
		$value = ($kind === 'netpay' ? 'HRMQ-NETPAY-2026-05-ADM-001' : 'HRMQ-LOON-2026-05-ADM-001');
		$store = $this->store();
		$store->honourQuery = true;
		for ($i = 0; $i < 10000; $i++) {
			$store->rows[$ownSchema][] = ['id' => 'old-' . $i, 'payrollRunId' => 'run-old-' . $i, 'status' => 'failed', $number => 'OLD-' . $i];
			$store->rows[$target][] = ['id' => 'old-' . $i, $number => 'OLD-' . $i];
		}

		$store->rows[$target][] = ['id' => 'mine', $number => $value];
		$adopted = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($adopted['status'], ['created', 'posted']);
		$this->assertSame([], $store->savesOf($target), 'The existing shillinq record is adopted.');

		$store->rows[$ownSchema] = array_values(array_filter($store->rows[$ownSchema], static fn (array $row): bool => ($row['payrollRunId'] ?? '') !== 'run-1'));
		$store->rows[$target] = array_values(array_filter($store->rows[$target], static fn (array $row): bool => $row['id'] !== 'mine'));
		$store->rows[$ownSchema][] = ['id' => 'busy', 'payrollRunId' => 'run-2', 'status' => 'pending', $number => $value, '@self' => ['id' => 'busy', 'updated' => gmdate('c', time() - 60)]];
		$blocked = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertSame('pending', $blocked['status'], 'A fresh claim on the same number, past the first page, withdraws this one.');
		$this->assertSame([], $store->savesOf($target));
	}//end testOlderRecordsBeyondOnePageDoNotHideTheRunsRecords()

	/**
	 * A pending record older than the claim window is a crash left behind:
	 * it is superseded and the run is handed over.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAStalePendingRecordDoesNotBlockTheRun(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$store->rows[$ownSchema] = [['id' => 'old', 'payrollRunId' => 'run-1', 'status' => 'pending', '@self' => ['id' => 'old', 'updated' => gmdate('c', time() - 3600)]]];

		$outcome = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($outcome['status'], ['created', 'posted']);
		$this->assertCount(1, $store->savesOf($target));
		$this->assertSame('failed', $store->savesOf($ownSchema)[0]['status']);
	}//end testAStalePendingRecordDoesNotBlockTheRun()

	/**
	 * A fresh pending record of another process blocks the run without a
	 * write.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAFreshPendingRecordBlocksTheRun(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$store->rows[$ownSchema] = [['id' => 'busy', 'payrollRunId' => 'run-1', 'status' => 'pending', '@self' => ['id' => 'busy', 'updated' => gmdate('c', time() - 60)]]];

		$outcome = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertSame('pending', $outcome['status']);
		$this->assertSame([], $store->saves);
	}//end testAFreshPendingRecordBlocksTheRun()

	/**
	 * A stale pending record of another payroll run with the same number is
	 * a crash left behind: it is superseded, and the run is handed over,
	 * adopting the shillinq record that crash already wrote.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAStaleClaimOfAnotherRunWithTheSameNumberIsSuperseded(string $kind, string $target, string $ownSchema, string $handOff): void {
		[$number, $value] = $this->numberOf($kind);
		$stale = ['id' => 'crashed', 'payrollRunId' => 'run-2', 'status' => 'pending', $number => $value, '@self' => ['id' => 'crashed', 'updated' => gmdate('c', time() - 3600)]];
		$store = $this->store();
		$store->rows[$ownSchema] = [$stale];

		$outcome = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($outcome['status'], ['created', 'posted']);
		$this->assertCount(1, $store->savesOf($target), 'The run is handed over once.');
		$this->assertSame(['failed'], array_column($this->savesWithId($store, $ownSchema, 'crashed'), 'status'), 'The stale claim is superseded.');

		$store = $this->store();
		$store->rows[$ownSchema] = [$stale];
		$store->rows[$target] = [['id' => 'by-crash', $number => $value]];

		$adopted = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($adopted['status'], ['created', 'posted']);
		$this->assertSame([], $store->savesOf($target), 'The crashed run\'s shillinq record is adopted.');
	}//end testAStaleClaimOfAnotherRunWithTheSameNumberIsSuperseded()

	/**
	 * A done record of another payroll run with the same number blocks the
	 * run and names it a duplicate run; a fresh claim of the same run still
	 * reads as another process at work.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testARecordOfAnotherRunWithTheSameNumberIsADuplicateRun(string $kind, string $target, string $ownSchema, string $handOff): void {
		[$number, $value] = $this->numberOf($kind);
		$done = ($kind === 'netpay' ? 'created' : 'posted');
		$store = $this->store();
		$store->rows[$ownSchema] = [['id' => 'done', 'payrollRunId' => 'run-2', 'status' => $done, $number => $value, '@self' => ['id' => 'done', 'updated' => gmdate('c', time() - 3600)]]];

		$blocked = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertSame('pending', $blocked['status']);
		$this->assertStringStartsWith(($kind === 'netpay' ? 'Dubbele loonrun: ' : 'Duplicate run: '), $blocked['message']);
		$this->assertStringContainsString($value, $blocked['message']);
		$this->assertSame([], $store->savesOf($target));
		$this->assertSame([], $this->savesWithId($store, $ownSchema, 'done'), 'The other run\'s record is left alone.');

		$store = $this->store();
		$store->rows[$ownSchema] = [['id' => 'busy', 'payrollRunId' => 'run-1', 'status' => 'pending', $number => $value, '@self' => ['id' => 'busy', 'updated' => gmdate('c', time() - 60)]]];

		$withdrawn = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertSame('pending', $withdrawn['status']);
		$this->assertStringNotContainsString('uplicate', $withdrawn['message']);
		$this->assertStringNotContainsString('Dubbele', $withdrawn['message']);
	}//end testARecordOfAnotherRunWithTheSameNumberIsADuplicateRun()

	/**
	 * A period written as 2026-5 and one written as 2026-05 are one month,
	 * so two runs for them share one number and reach shillinq once.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAOneDigitMonthSharesTheNumberOfItsTwoDigitForm(string $kind, string $target, string $ownSchema, string $handOff): void {
		[$number, $value] = $this->numberOf($kind);
		$store = $this->store();
		$store->rows['Payslip'][] = ['id' => 'ps-short', 'period' => '2026-5', 'employeeId' => 'emp-1', 'nettoPay' => 2698.0];

		$first = $this->service($kind, $store)->$handOff($this->payrollRun(['period' => '2026-5']));
		$second = $this->service($kind, $store)->$handOff($this->payrollRun(['id' => 'run-2']));

		$this->assertContains($first['status'], ['created', 'posted']);
		$this->assertSame('pending', $second['status']);
		$this->assertSame([$value], array_column($store->savesOf($target), $number), 'One record, under the two-digit number.');
	}//end testAOneDigitMonthSharesTheNumberOfItsTwoDigitForm()

	/**
	 * A user who may not read the hand-off's records or shillinq's still
	 * sees what a cron run wrote: the hand-off reads them without RBAC, as
	 * it writes them.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAUserWithoutReadRightsSeesWhatACronRunWrote(string $kind, string $target, string $ownSchema, string $handOff): void {
		[$number, $value] = $this->numberOf($kind);
		$store = $this->store();
		$first = $this->service($kind, $store)->$handOff($this->payrollRun());
		$store->organisation = 'default';
		$store->unreadable = [$target, $ownSchema];
		$second = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($first['status'], ['created', 'posted']);
		$this->assertContains($second['status'], ['created', 'posted']);
		$this->assertCount(1, $store->savesOf($target), 'One record reaches shillinq.');

		$store = $this->store();
		$store->rows[$target] = [['id' => 'by-cron', $number => $value, '_org' => 'default']];
		$store->organisation = 'default';
		$store->unreadable = [$target, $ownSchema];

		$adopted = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($adopted['status'], ['created', 'posted']);
		$this->assertSame([], $store->savesOf($target), 'The cron run\'s shillinq record is adopted.');

		$store = $this->store();
		$store->rows[$ownSchema] = [['id' => 'cron-claim', 'payrollRunId' => 'run-1', 'status' => 'pending', $number => $value, '_org' => 'default', '@self' => ['id' => 'cron-claim', 'updated' => gmdate('c', time() - 60)]]];
		$store->organisation = 'default';
		$store->unreadable = [$target, $ownSchema];

		$blocked = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertSame('pending', $blocked['status'], 'The cron run\'s fresh claim blocks the user run.');
		$this->assertSame([], $store->savesOf($target));
	}//end testAUserWithoutReadRightsSeesWhatACronRunWrote()

	/**
	 * A stale GL claim whose JournalEntry shillinq already holds is not
	 * adopted as posted when the run's totals are refused: the claim fails
	 * with the refusal, the run is not stamped, and a later pass does not
	 * read it as already posted.
	 *
	 * @return void
	 */
	public function testAStaleGlClaimIsNotAdoptedWhenTheTotalsAreRefused(): void {
		[$number, $value] = $this->numberOf('glpost');
		$store = $this->store();
		$store->rows['PayrollGLPost'] = [['id' => 'old', 'payrollRunId' => 'run-1', 'status' => 'pending', $number => $value, '@self' => ['id' => 'old', 'updated' => gmdate('c', time() - 3600)]]];
		$store->rows['JournalEntry'] = [['id' => 'je-old', $number => $value]];
		$run = $this->payrollRun(['totalGross' => -1.0]);

		$outcome = $this->service('glpost', $store)->postRun($run);
		$again = $this->service('glpost', $store)->postRun($run);

		$this->assertSame('failed', $outcome['status']);
		$this->assertStringStartsWith('Negatief runtotaal', $outcome['message']);
		$this->assertStringContainsString($value, $outcome['message']);
		$this->assertSame(['failed'], array_column($this->savesWithId($store, 'PayrollGLPost', 'old'), 'status'));
		$this->assertSame([], $store->savesOf('PayrollRun'), 'The run is not stamped posted.');
		$this->assertSame([], $store->savesOf('JournalEntry'));
		$this->assertSame('failed', $again['status'], 'A later pass refuses again.');
	}//end testAStaleGlClaimIsNotAdoptedWhenTheTotalsAreRefused()

	/**
	 * An owner that stalls past the claim window right before it writes
	 * shillinq still holds the hand-off lock: a second process supersedes
	 * the stale claim but is refused, so shillinq gets one record, the
	 * owner's, and the owner's claim ends settled.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testASlowOwnerPastTheClaimWindowKeepsTheLock(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$store->locks = new InMemoryLocks();
		$second = null;
		$store->beforeSave = function (string $schema) use ($store, $kind, $target, $ownSchema, $handOff, &$second): void {
			if ($schema === $target && $second === null) {
				$second = 'running';
				foreach (array_keys($store->rows[$ownSchema]) as $i) {
					$store->rows[$ownSchema][$i]['@self']['updated'] = gmdate('c', time() - 3600);
				}

				$second = $this->service($kind, $store)->$handOff($this->payrollRun());
			}
		};

		$first = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertCount(1, $store->savesOf($target), 'One record reaches shillinq.');
		$this->assertSame('pending', $second['status'], 'The second process is refused.');
		$this->assertContains($first['status'], ['created', 'posted']);
		$own = $store->savesOf($ownSchema);
		$this->assertSame($first['status'], end($own)['status'], 'The owner settles its claim last.');
		$this->assertSame([], $store->locks->held, 'The lock is released.');
	}//end testASlowOwnerPastTheClaimWindowKeepsTheLock()

	/**
	 * The number field and the number of run-1 for a hand-off.
	 *
	 * @param string $kind `netpay` or `glpost`.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function numberOf(string $kind): array {
		if ($kind === 'netpay') {
			return ['runNumber', 'HRMQ-NETPAY-2026-05-ADM-001'];
		}

		return ['journalNumber', 'HRMQ-LOON-2026-05-ADM-001'];
	}//end numberOf()

	/**
	 * The saves of one record of a schema, in order.
	 *
	 * @param object $store  The shared register.
	 * @param string $schema The schema.
	 * @param string $id     The record id.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function savesWithId(object $store, string $schema, string $id): array {
		return array_values(array_filter($store->savesOf($schema), static fn (array $save): bool => $save['id'] === $id));
	}//end savesWithId()

	/**
	 * The payroll run, approved, with totals and one payslip's period.
	 *
	 * @param array<string, mixed> $overrides Fields that differ.
	 *
	 * @return array<string, mixed>
	 */
	private function payrollRun(array $overrides = []): array {
		return $overrides + [
			'id' => 'run-1',
			'period' => '2026-05',
			'administrationId' => 'ADM-001',
			'status' => 'approved',
			'totalGross' => 3800.00,
			'totalEmployerCharges' => 649.80,
			'totalLoonheffing' => 1102.00,
			'totalNet' => 2698.00,
		];
	}//end payrollRun()

	/**
	 * A shared in-memory register with hooks before a save and before and
	 * after a read.
	 * Every saved row carries `@self.updated`, as OpenRegister's do.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class {

			/**
			 * @var array<string, list<array<string, mixed>>>
			 */
			public array $rows = [
				'Employee' => [['id' => 'emp-1', 'iban' => 'NL91ABNA0417164300', 'tenaamstelling' => 'J. de Vries']],
				'Payslip' => [['id' => 'ps-1', 'period' => '2026-05', 'employeeId' => 'emp-1', 'nettoPay' => 2698.0]],
			];

			/**
			 * @var list<array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * @var callable|null
			 */
			public $beforeSave = null;

			/**
			 * @var callable|null
			 */
			public $afterFindAll = null;

			/**
			 * @var callable|null
			 */
			public $beforeFindAll = null;

			/**
			 * The acting user's organisation; null is the system context
			 * (cron, occ), which reads every organisation.
			 *
			 * @var string|null
			 */
			public ?string $organisation = null;

			/**
			 * Schemas the acting user may not read: a user context sees none
			 * of their rows unless RBAC is off. The system context reads all.
			 *
			 * @var list<string>
			 */
			public array $unreadable = [];

			/**
			 * Nextcloud's locking provider, or null for a container without one.
			 *
			 * @var InMemoryLocks|null
			 */
			public ?InMemoryLocks $locks = null;

			/**
			 * Whether findAll applies its filters and limit, as OpenRegister does.
			 *
			 * @var bool
			 */
			public bool $honourQuery = false;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Rows of another organisation are hidden from a user context
			 * unless multitenancy is off, and rows of an unreadable schema
			 * unless RBAC is off; rows written by the system belong to the
			 * default organisation.
			 *
			 * @param array<string, mixed> $options       Query options.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$schema = $this->schema;
				if ($this->beforeFindAll !== null) {
					($this->beforeFindAll)($schema);
				}

				$rows = $this->visibleRows(($this->rows[$schema] ?? []), $options, $_multitenancy);
				if ($_rbac === true && $this->organisation !== null && in_array($schema, $this->unreadable, true) === true) {
					$rows = [];
				}
				if ($this->afterFindAll !== null) {
					($this->afterFindAll)($schema);
				}

				return $rows;
			}//end findAll()

			/**
			 * @param list<array<string, mixed>> $rows          The stored rows.
			 * @param array<string, mixed>       $options       Query options.
			 * @param bool                       $_multitenancy Multitenancy flag.
			 *
			 * @return list<array<string, mixed>>
			 */
			private function visibleRows(array $rows, array $options, bool $_multitenancy): array {
				$organisation = $this->organisation;
				if ($_multitenancy === true && $organisation !== null) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => in_array(($row['_org'] ?? null), [null, $organisation], true)));
				}

				if ($this->honourQuery === true) {
					foreach (($options['filters'] ?? []) as $field => $value) {
						$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row[$field] ?? null) === $value));
					}

					$rows = array_slice($rows, 0, (int)($options['limit'] ?? count($rows)));
				}

				return array_map(static function (array $row): array {
					unset($row['_org']);
					return $row;
				}, $rows);
			}//end visibleRows()

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->beforeSave !== null) {
					($this->beforeSave)((string)$schema);
				}

				$id = ($uuid ?? ($schema . '-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id, '@self' => ['id' => $id, 'updated' => gmdate('c')]]);
				$this->saves[] = ['schema' => $schema] + $saved;
				$organisation = ($this->organisation ?? 'default');
				foreach (($this->rows[(string)$schema] ?? []) as $row) {
					if (($row['id'] ?? null) === $id) {
						$organisation = ($row['_org'] ?? $organisation);
					}
				}

				$rows = array_values(array_filter(($this->rows[(string)$schema] ?? []), static fn (array $row): bool => ($row['id'] ?? null) !== $id));
				$rows[] = $saved + ['_org' => $organisation];
				$this->rows[(string)$schema] = $rows;

				return $saved;
			}//end saveObject()

			/**
			 * The saves of one schema, in order.
			 *
			 * @param string $schema The schema.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function savesOf(string $schema): array {
				return array_values(array_filter($this->saves, static fn (array $save): bool => $save['schema'] === $schema));
			}//end savesOf()

		};
	}//end store()

	/**
	 * One process's service over the shared register.
	 *
	 * @param string $kind  `netpay` or `glpost`.
	 * @param object $store The shared register.
	 *
	 * @return object
	 */
	private function service(string $kind, object $store): object {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => ($id === ILockingProvider::class ? $store->locks : $store));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getNetPayExecutionDay')->willReturn(25);
		$settings->method('getNetPayDebtorIban')->willReturn('');
		$settings->method('getGlPostAccountGross')->willReturn('4001');
		$settings->method('getGlPostAccountEmployerCharges')->willReturn('4002');
		$settings->method('getGlPostAccountWageTaxLiability')->willReturn('1701');
		$settings->method('getGlPostAccountNetWagesLiability')->willReturn('1702');

		if ($kind === 'netpay') {
			return new PayrollNetPayService($container, $apps, $settings, new NullLogger());
		}

		return new PayrollGLPostService($container, $apps, $settings, new NullLogger());
	}//end service()
}//end class
