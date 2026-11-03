<?php

/**
 * Payroll GL Post Service
 *
 * Posts one balanced loonjournaalpost per approved PayrollRun into shillinq's
 * JournalEntry register (payroll-glpost-shillinq). humaniq holds no bookkeeping
 * machinery of its own (design.md D1): the only artefact this service writes
 * on the humaniq side is a `PayrollGLPost` record logging the handoff; the
 * journal itself is created as a shillinq `JournalEntry` through
 * OpenRegister's ObjectService, same instance, never HTTP.
 *
 * Availability is duck-typed (ADR-046 philosophy, mirroring
 * `OCA\Humaniq\Portal\PortalContributionProvider`): when shillinq is not
 * installed, or its register/schema cannot be resolved, the attempt is
 * recorded `skipped-no-shillinq` and the referenced PayrollRun stays
 * `approved` so a later `occ humaniq:glpost:run` retries (design.md D7). humaniq
 * carries zero composer/info.xml dependency on shillinq.
 *
 * Idempotency is enforced in two layers (design.md D6): at most one
 * PayrollGLPost in `{pending, posted}` per run (service pre-check), and the
 * deterministic `journalNumber` (`HRMQ-LOON-{period}-{administrationId}`) is
 * probed in shillinq before creating, so a crash between create and record
 * update adopts the existing entry instead of double-posting.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds and posts the balanced payroll-to-GL journal for approved runs.
 *
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md
 */
class PayrollGLPostService {

	/**
	 * The app id shillinq registers under (IAppManager::isInstalled probe).
	 *
	 * @var string
	 */
	private const SHILLINQ_APP_ID = 'shillinq';

	/**
	 * The shillinq register/schema the journal entry is written to.
	 *
	 * @var string
	 */
	private const SHILLINQ_REGISTER = 'shillinq';

	/**
	 * @var string
	 */
	private const SHILLINQ_SCHEMA = 'JournalEntry';

	/**
	 * This app's own GL-post schema.
	 *
	 * @var string
	 */
	private const GLPOST_SCHEMA = 'PayrollGLPost';

	/**
	 * PayrollGLPost statuses that count as "active" for the at-most-one-per-run
	 * invariant (design.md D6).
	 *
	 * @var string[]
	 */
	private const ACTIVE_STATUSES = ['pending', 'posted'];

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppManager $appManager To duck-type-probe shillinq's presence.
	 * @param SettingsService $settingsService Register slug + configurable RGS account numbers.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Post every approved PayrollRun (optionally period-filtered) — the MVP
	 * trigger's entry point (design.md D5).
	 *
	 * @param string|null $period Only post runs for this wage period (YYYY-MM), or null for all.
	 *
	 * @return array<int, array<string, mixed>> One outcome array per selected run.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-006
	 */
	public function postApprovedRuns(?string $period = null): array {
		$results = [];
		foreach ($this->approvedRuns($period) as $run) {
			$results[] = $this->postRun($run);
		}

		return $results;
	}//end postApprovedRuns()

	/**
	 * Post a single PayrollRun: idempotency pre-check, duck-typed availability
	 * probe, balanced-journal construction, shillinq JournalEntry creation (or
	 * adoption via the journalNumber probe), and — on success — the run's GL
	 * fields + status advance.
	 *
	 * @param array<string, mixed> $run The PayrollRun object.
	 *
	 * @return array<string, mixed> Outcome: {runId, status, message, glPostId, journalEntryId}.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-003
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-004
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-005
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	public function postRun(array $run): array {
		$runId = (string)($run['id'] ?? $run['@self']['id'] ?? '');
		$period = trim((string)($run['period'] ?? ''));
		$administrationId = trim((string)($run['administrationId'] ?? ''));

		if ($runId === '' || $period === '' || $administrationId === '') {
			return $this->outcome($runId, 'failed', 'PayrollRun is missing id/period/administrationId; cannot post.');
		}

		$settled = $this->settledByActiveGlPost($run);
		if ($settled !== null) {
			return $settled;
		}

		if ($this->shillinqAvailable() === false) {
			$glPost = $this->createGlPost(
				[
					'payrollRunId' => $runId,
					'period' => $period,
					'status' => 'skipped-no-shillinq',
					'errorMessage' => 'Shillinq is niet geïnstalleerd of het JournalEntry-register is niet beschikbaar; de loonrun blijft goedgekeurd voor een latere poging.',
				]
			);

			return $this->outcome($runId, 'skipped-no-shillinq', (string)($glPost['errorMessage'] ?? ''), $glPost);
		}

		$built = $this->buildLines($run, $this->allocationRowsForRun($runId));
		if ($built['error'] !== null) {
			$glPost = $this->createGlPost(
				[
					'payrollRunId' => $runId,
					'period' => $period,
					'status' => 'failed',
					'errorMessage' => $built['error'],
				]
			);

			return $this->outcome($runId, 'failed', (string)$built['error'], $glPost);
		}

		$locked = (new HandoffLock($this->container))->holding('glpost/' . $this->journalNumber($run), fn (): array => $this->postUnderClaim($run, $built));

		return ($locked ?? $this->outcome($runId, 'pending', 'Another process holds the hand-off lock for this journal number.'));
	}//end postRun()

	/**
	 * The outcome an active GL post of the run already settles, or null to
	 * go on: a posted one is an idempotent no-op, a fresh pending one is
	 * another process still at work (nothing is written), and a stale one is
	 * adopted when shillinq already carries its JournalEntry, or superseded.
	 *
	 * @param array<string, mixed> $run The PayrollRun.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	private function settledByActiveGlPost(array $run): ?array {
		$runId = (string)($run['id'] ?? $run['@self']['id'] ?? '');
		$active = $this->activeGlPostForRun($runId);
		$status = (string)($active['status'] ?? '');
		if ($status === 'posted') {
			return $this->outcome($runId, 'posted', 'Already posted (idempotent no-op).', $active, (string)($active['journalEntryId'] ?? null));
		}

		if ($status !== 'pending') {
			return null;
		}

		if ((new PendingClaims(self::ACTIVE_STATUSES))->isFresh($active) === true) {
			return $this->outcome($runId, 'pending', 'Another process is already posting this payroll run.', $active);
		}

		// A stale pending GL post is a crash left behind (design.md D6): adopt
		// the JournalEntry shillinq already carries for this journalNumber, or
		// close the stale record and let the caller start a fresh attempt.
		if ($this->shillinqAvailable() === false) {
			return null;
		}

		$journalNumber = $this->journalNumber($run);
		$adopted = $this->findJournalEntryByNumber($journalNumber);
		if ($adopted === null) {
			$this->saveGlPost($active, ['status' => 'failed', 'errorMessage' => 'Verlopen pending-poging vervangen; geen bijbehorende shillinq-journaalpost gevonden.']);
			return null;
		}

		$built = $this->buildLines($run);
		if ($built['error'] !== null) {
			$glPost = $this->saveGlPost($active, ['status' => 'failed', 'errorMessage' => $built['error'] . ' Shillinq heeft al journaalpost ' . $journalNumber . ' van een eerdere poging; controleer die met de hand.']);
			return $this->outcome($runId, 'failed', (string)$glPost['errorMessage'], $glPost);
		}

		$journalEntryId = (string)($adopted['id'] ?? $adopted['@self']['id'] ?? '');
		$glPost = $this->saveGlPost($active, ['status' => 'posted', 'journalEntryId' => $journalEntryId, 'journalNumber' => $journalNumber, 'postedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'errorMessage' => null]);
		$this->applySuccessToRun($run, (float)$built['glExpensePosted'], (float)$built['glLiabilityPosted']);

		return $this->outcome($runId, 'posted', 'Bestaande shillinq-journaalpost overgenomen (crash-recovery).', $glPost, $journalEntryId);
	}//end settledByActiveGlPost()

	/**
	 * Claim the run with a `pending` GL post, then create or adopt the
	 * shillinq JournalEntry and settle the claim. A claim that finds another
	 * active GL post of the run next to it withdraws, so two processes never
	 * both create a JournalEntry for one run.
	 *
	 * @param array<string, mixed> $run   The PayrollRun.
	 * @param array<string, mixed> $built The buildLines() result.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	private function postUnderClaim(array $run, array $built): array {
		$runId = (string)($run['id'] ?? $run['@self']['id'] ?? '');
		$journalNumber = $this->journalNumber($run);
		$claim = $this->createGlPost(['payrollRunId' => $runId, 'period' => trim((string)($run['period'] ?? '')), 'status' => 'pending', 'journalNumber' => $journalNumber]);
		$claims = new PendingClaims(self::ACTIVE_STATUSES);
		$records = $this->glPostsWhere('journalNumber', $journalNumber);
		foreach ($claims->staleOthers($records, $claim) as $stale) {
			$this->saveGlPost($stale, ['status' => 'failed', 'errorMessage' => 'Verlopen pending-poging met hetzelfde journaalnummer vervangen.']);
		}

		$rival = $claims->rival($records, $claim);
		if ($rival !== null) {
			$message = ($claims->isAnotherRun($rival, $claim) === true ? 'Duplicate run: another payroll run of this period and administration already holds journal number ' . $journalNumber . '.' : 'Another process is already posting this payroll run.');
			$withdrawn = $this->saveGlPost($claim, ['status' => 'failed', 'errorMessage' => 'Withdrawn: ' . $message]);
			return $this->outcome($runId, 'pending', $message, $withdrawn);
		}

		try {
			$journalEntryId = $this->createOrAdoptJournalEntry($run, $built['lines']);
		} catch (\Throwable $e) {
			$glPost = $this->saveGlPost($claim, ['status' => 'failed', 'errorMessage' => 'Aanmaken van de shillinq JournalEntry is mislukt: ' . $e->getMessage()]);
			return $this->outcome($runId, 'failed', (string)$glPost['errorMessage'], $glPost);
		}

		$glPost = $this->saveGlPost($claim, ['status' => 'posted', 'journalEntryId' => $journalEntryId, 'postedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'lines' => $built['lines']]);
		$this->applySuccessToRun($run, (float)$built['glExpensePosted'], (float)$built['glLiabilityPosted']);

		return $this->outcome($runId, 'posted', 'Geboekt als shillinq journaalpost ' . $journalNumber . '.', $glPost, $journalEntryId);
	}//end postUnderClaim()

	/**
	 * Build the balanced 4-line journal from a run's totals (design.md D2), pure
	 * and side-effect free so it is directly unit-testable. All arithmetic is
	 * done in integer cents to guarantee debits equal credits by construction
	 * regardless of float rounding.
	 *
	 * payroll-cost-allocation D4: with the run's WageCostAllocation rows the
	 * gross and employer-charge debits are written per cost centre and
	 * project, each with its codes; whatever the rows do not cover stays one
	 * line without codes, so the debits still equal the run totals.
	 *
	 * @param array<string, mixed>       $run         The PayrollRun object.
	 * @param list<array<string, mixed>> $allocations The run's allocation lines (none: one line per total).
	 *
	 * @return array<string, mixed> {lines, error, glExpensePosted, glLiabilityPosted}.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-004
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-002
	 */
	public function buildLines(array $run, array $allocations = []): array {
		$totals = new PayrollJournalTotals($run);
		$refusal = $totals->refusal();
		if ($refusal !== null) {
			return $this->buildFailure($refusal);
		}

		$grossCents = $totals->cents('totalGross');
		$chargesCents = $totals->cents('totalEmployerCharges');
		$loonheffingCents = $totals->cents('totalLoonheffing');
		$netCents = $totals->cents('totalNet');
		$reimbursementCents = $totals->cents('totalReimbursements');

		// payroll-expenses-and-allowances D6: reimbursements raise net with no
		// wage cost behind them, so they carry their own debit line.
		$debitTotalCents = ($grossCents + $chargesCents + $reimbursementCents);
		$creditKnownCents = ($loonheffingCents + $netCents);
		$remainderCents = ($debitTotalCents - $creditKnownCents);

		if ($remainderCents < 0) {
			return $this->buildFailure(sprintf(
				'Inconsistente runtotalen: loonheffing + netto (%.2f) overschrijdt bruto + werkgeverslasten (%.2f) met %.2f.',
				($creditKnownCents / 100),
				($debitTotalCents / 100),
				(abs($remainderCents) / 100)
			));
		}

		$netLiabilityCents = ($netCents + $remainderCents);

		$runId = (string)($run['id'] ?? $run['@self']['id'] ?? '');
		$candidates = array_merge(
			$this->costLines(allocations: $allocations, cost: ['runId' => $runId, 'field' => 'gross', 'totalCents' => $grossCents, 'account' => $this->settingsService->getGlPostAccountGross(), 'description' => 'Loonkosten bruto']),
			$this->costLines(allocations: $allocations, cost: ['runId' => $runId, 'field' => 'employerCharges', 'totalCents' => $chargesCents, 'account' => $this->settingsService->getGlPostAccountEmployerCharges(), 'description' => 'Werkgeverslasten sociale premies'])
		);
		$liabilities = [
			['side' => 'credit', 'accountNumber' => $this->settingsService->getGlPostAccountWageTaxLiability(), 'amount' => $loonheffingCents, 'description' => 'Loonheffing-schuld'],
			['side' => 'credit', 'accountNumber' => $this->settingsService->getGlPostAccountNetWagesLiability(), 'amount' => $netLiabilityCents, 'description' => 'Netto-loonschuld'],
		];
		if ($reimbursementCents > 0) {
			$candidates[] = ['side' => 'debit', 'accountNumber' => $this->settingsService->getGlPostAccountReimbursements(), 'amount' => $reimbursementCents, 'description' => 'Declaraties en onbelaste vergoedingen'];
		}

		$candidates = array_merge($candidates, $liabilities);

		$lines = [];
		foreach ($candidates as $candidate) {
			// Zero-amount lines are dropped (shillinq requires minItems: 2, which
			// any run with totalGross > 0 satisfies per design.md D2).
			if ($candidate['amount'] <= 0) {
				continue;
			}

			$candidate['amount'] = round(($candidate['amount'] / 100), 2);
			$lines[] = $candidate;
		}

		return [
			'lines' => $lines,
			'error' => null,
			'glExpensePosted' => round(($debitTotalCents / 100), 2),
			'glLiabilityPosted' => round((($debitTotalCents - $netCents) / 100), 2),
		];

	}//end buildLines()

	/**
	 * The debit lines of one cost total, per cost centre and project of the
	 * run's allocation lines in the order they first appear, plus one line
	 * without codes for whatever they do not cover (payroll-cost-allocation
	 * D4). Without allocation lines: the one total line as before.
	 *
	 * @param list<array<string, mixed>>                                                                $allocations The allocation lines.
	 * @param array{runId: string, field: string, totalCents: int, account: string, description: string} $cost        The run (so another run's lines are never booked), the allocation field (gross or employerCharges), the run total in cents, the debit account and the line description.
	 *
	 * @return list<array<string, mixed>> Lines with the amount in cents.
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-002
	 */
	private function costLines(array $allocations, array $cost): array {
		$groups = $this->costGroups(allocations: $allocations, cost: $cost);
		$uncoded = ($cost['totalCents'] - array_sum(array_column($groups, 'cents')));
		$lines = [];
		foreach ($groups as $group) {
			$line = ['side' => 'debit', 'accountNumber' => $cost['account'], 'amount' => $group['cents'], 'description' => $cost['description']];
			if ($group['costCenterCode'] === '') {
				$uncoded += $group['cents'];
				continue;
			}

			$line['costCenterCode'] = $group['costCenterCode'];
			if ($group['projectCode'] !== '') {
				$line['projectCode'] = $group['projectCode'];
			}

			$lines[] = $line;
		}

		$lines[] = ['side' => 'debit', 'accountNumber' => $cost['account'], 'amount' => $uncoded, 'description' => $cost['description']];

		return $lines;
	}//end costLines()

	/**
	 * The run's allocated cents per cost centre and project, in the order
	 * they first appear; lines of another run or without a numeric amount
	 * are skipped.
	 *
	 * @param list<array<string, mixed>>                                                                $allocations The allocation lines.
	 * @param array{runId: string, field: string, totalCents: int, account: string, description: string} $cost        The cost total; its run and allocation field are read.
	 *
	 * @return array<string, array{costCenterCode: string, projectCode: string, cents: int}>
	 */
	private function costGroups(array $allocations, array $cost): array {
		$field = $cost['field'];
		$groups = [];
		foreach ($allocations as $row) {
			if ((string)($row['payrollRunId'] ?? '') !== $cost['runId'] || is_numeric($row[$field] ?? null) === false) {
				continue;
			}

			$costCenter = trim((string)($row['costCenter'] ?? ''));
			$project = trim((string)($row['projectId'] ?? ''));
			$key = $costCenter . '|' . $project;
			$groups[$key] = ($groups[$key] ?? ['costCenterCode' => $costCenter, 'projectCode' => $project, 'cents' => 0]);
			$groups[$key]['cents'] += (int)round(((float)$row[$field]) * 100);
		}

		return $groups;
	}//end costGroups()

	/**
	 * The WageCostAllocation lines of one run; none when they cannot be read
	 * (the journal then books one line per total, as before).
	 *
	 * @param string $runId The run.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-002
	 */
	private function allocationRowsForRun(string $runId): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema('WageCostAllocation')->findAll(['limit' => 100000, 'filters' => ['payrollRunId' => $runId]]);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollGLPostService: kon de kostenverdeling niet laden: ' . $e->getMessage());
			return [];
		}

		return array_values(array_filter($this->normaliseRows($rows), static fn (array $row): bool => (string)($row['payrollRunId'] ?? '') === $runId));
	}//end allocationRowsForRun()

	/**
	 * Build a `failed` buildLines() result shape.
	 *
	 * @param string $message The diagnostic error message.
	 *
	 * @return array<string, mixed>
	 */
	private function buildFailure(string $message): array {
		return [
			'lines' => [],
			'error' => $message,
			'glExpensePosted' => null,
			'glLiabilityPosted' => null,
		];

	}//end buildFailure()

	/**
	 * Find an existing shillinq JournalEntry by journalNumber (idempotency
	 * probe, design.md D6), or create it when none exists yet.
	 *
	 * @param array<string, mixed> $run The PayrollRun object (id, period and administration).
	 * @param array<int, mixed> $lines The balanced journal lines.
	 *
	 * @return string The shillinq JournalEntry id (adopted or newly created).
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-003
	 */
	private function createOrAdoptJournalEntry(array $run, array $lines): string {
		$journalNumber = $this->journalNumber($run);
		$adopted = $this->findJournalEntryByNumber($journalNumber);
		if ($adopted !== null) {
			return (string)($adopted['id'] ?? $adopted['@self']['id'] ?? '');
		}

		$period = trim((string)($run['period'] ?? ''));
		$payload = [
			'journalNumber' => $journalNumber,
			'entryDate' => $this->periodEndDate($period),
			'description' => sprintf('Loonjournaalpost %s — humaniq loonrun %s', $period, (string)($run['id'] ?? $run['@self']['id'] ?? '')),
			'lines' => $lines,
			'journalType' => 'manual',
			// The sub-ledger this journal comes from: shillinq's payroll
			// control-account role admits only humaniq's postings (humaniq#549).
			'sourceApp' => 'humaniq',
			'approvalState' => 'not-required',
			'administrationId' => trim((string)($run['administrationId'] ?? '')),
			'state' => 'draft',
		];

		$created = $this->objectService()->saveObject(
			object: $payload,
			register: self::SHILLINQ_REGISTER,
			schema: self::SHILLINQ_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return (string)($this->toArray($created)['id'] ?? $this->toArray($created)['@self']['id'] ?? '');
	}//end createOrAdoptJournalEntry()

	/**
	 * The deterministic idempotency key of a run's JournalEntry: its trimmed
	 * period and administration.
	 *
	 * @param array<string, mixed> $run The PayrollRun object.
	 *
	 * @return string The journal number.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-003
	 */
	private function journalNumber(array $run): string {
		$period = trim((string)($run['period'] ?? ''));
		return sprintf('HRMQ-LOON-%s-%s', ((new WagePeriod($period))->month() ?? $period), trim((string)($run['administrationId'] ?? '')));
	}//end journalNumber()

	/**
	 * The last day of a YYYY-MM wage period, per design.md D3 (`entryDate` =
	 * last day of the wage period).
	 *
	 * @param string $period Wage period in YYYY-MM.
	 *
	 * @return string The period-end date (Y-m-d).
	 *
	 * @throws InvalidArgumentException When the period is not a month.
	 */
	private function periodEndDate(string $period): string {
		$firstDay = (new WagePeriod($period))->firstDay();
		if ($firstDay === null) {
			throw new InvalidArgumentException('PayrollRun-periode is geen maand in de vorm JJJJ-MM.');
		}

		return $firstDay->format('Y-m-t');
	}//end periodEndDate()

	/**
	 * Apply the success effects to the PayrollRun (design.md D4): the numeric
	 * `glExpensePosted`/`glLiabilityPosted` amounts `xc-payroll-gl-reconciliation`
	 * compares cents-equal, and the `approved -> posted` status advance.
	 *
	 * @param array<string, mixed> $run The PayrollRun object.
	 * @param float $glExpensePosted Total gross + employer charges.
	 * @param float $glLiabilityPosted Total gross + employer charges − net.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-005
	 */
	private function applySuccessToRun(array $run, float $glExpensePosted, float $glLiabilityPosted): void {
		$runId = (string)($run['id'] ?? $run['@self']['id'] ?? '');
		if ($runId === '') {
			return;
		}

		$payload = $run;
		unset($payload['@self']);
		$payload['glExpensePosted'] = $glExpensePosted;
		$payload['glLiabilityPosted'] = $glLiabilityPosted;
		$payload['status'] = 'posted';

		try {
			$this->objectService()->saveObject(
				object: $payload,
				register: $this->register(),
				schema: 'PayrollRun',
				uuid: $runId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('PayrollGLPostService: kon PayrollRun ' . $runId . ' niet bijwerken na boeken: ' . $e->getMessage());
		}

	}//end applySuccessToRun()

	/**
	 * Duck-typed shillinq availability probe (design.md D7): shillinq must be
	 * installed AND its JournalEntry register/schema must resolve.
	 *
	 * @return bool
	 */
	private function shillinqAvailable(): bool {
		if ($this->appManager->isInstalled(self::SHILLINQ_APP_ID) === false) {
			return false;
		}

		try {
			$this->objectService()->setRegister(self::SHILLINQ_REGISTER)->setSchema(self::SHILLINQ_SCHEMA)->findAll(['limit' => 1]);
		} catch (\Throwable $e) {
			return false;
		}

		return true;
	}//end shillinqAvailable()

	/**
	 * Search shillinq for an existing JournalEntry with the given journalNumber
	 * (the idempotency probe, design.md D6). Never throws — a lookup failure is
	 * treated as "not found" by the caller's fall-through logic.
	 *
	 * @param string $journalNumber The deterministic idempotency key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findJournalEntryByNumber(string $journalNumber): ?array {
		try {
			$rows = $this->objectService()->setRegister(self::SHILLINQ_REGISTER)->setSchema(self::SHILLINQ_SCHEMA)->findAll(['limit' => 10000, 'filters' => ['journalNumber' => $journalNumber]], false, false);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollGLPostService: the shillinq JournalEntry lookup failed.', ['exceptionClass' => $e::class]);
			return null;
		}

		foreach ($this->normaliseRows($rows) as $row) {
			if ((string)($row['journalNumber'] ?? '') === $journalNumber) {
				return $row;
			}
		}

		return null;
	}//end findJournalEntryByNumber()

	/**
	 * The PayrollGLPost rows whose field holds the value: those of one run
	 * (`payrollRunId`), or every claim on one journal number
	 * (`journalNumber`). Read outside the caller's RBAC and organisation, as
	 * they are written, so a cron run and a user run see each other.
	 *
	 * @param string $field The field, `payrollRunId` or `journalNumber`.
	 * @param string $value Its value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function glPostsWhere(string $field, string $value): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema(self::GLPOST_SCHEMA)->findAll(['limit' => 10000, 'filters' => [$field => $value]], false, false);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollGLPostService: the PayrollGLPost lookup failed.', ['exceptionClass' => $e::class]);
			return [];
		}

		$out = [];
		foreach ($this->normaliseRows($rows) as $row) {
			if ((string)($row[$field] ?? '') === $value) {
				$out[] = $row;
			}
		}

		return $out;
	}//end glPostsWhere()

	/**
	 * The active (pending/posted) PayrollGLPost for a run, if any — the
	 * at-most-one-per-run invariant (design.md D6).
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function activeGlPostForRun(string $runId): ?array {
		foreach ($this->glPostsWhere('payrollRunId', $runId) as $row) {
			if (in_array((string)($row['status'] ?? ''), self::ACTIVE_STATUSES, true) === true) {
				return $row;
			}
		}

		return null;
	}//end activeGlPostForRun()

	/**
	 * Create a new PayrollGLPost.
	 *
	 * @param array<string, mixed> $fields The object fields.
	 *
	 * @return array<string, mixed> The created object, normalised to an array.
	 */
	private function createGlPost(array $fields): array {
		$created = $this->objectService()->saveObject(
			object: $fields,
			register: $this->register(),
			schema: self::GLPOST_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return $this->toArray($created);
	}//end createGlPost()

	/**
	 * Update an existing PayrollGLPost by merging $fields onto it.
	 *
	 * @param array<string, mixed> $existing The current PayrollGLPost.
	 * @param array<string, mixed> $fields The fields to overwrite.
	 *
	 * @return array<string, mixed> The saved object, normalised to an array.
	 */
	private function saveGlPost(array $existing, array $fields): array {
		$id = (string)($existing['id'] ?? $existing['@self']['id'] ?? '');

		$payload = array_merge($existing, $fields);
		unset($payload['@self']);

		$saved = $this->objectService()->saveObject(
			object: $payload,
			register: $this->register(),
			schema: self::GLPOST_SCHEMA,
			uuid: ($id === '' ? null : $id),
			_rbac: false,
			_multitenancy: false
		);

		return $this->toArray($saved);
	}//end saveGlPost()

	/**
	 * The approved PayrollRun objects (optionally period-filtered).
	 *
	 * @param string|null $period Only runs for this wage period (YYYY-MM), or null.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function approvedRuns(?string $period): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema('PayrollRun')->findAll(['limit' => 10000]);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollGLPostService: kon PayrollRun niet laden: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ($this->normaliseRows($rows) as $run) {
			if ((string)($run['status'] ?? '') !== 'approved') {
				continue;
			}

			if ($period !== null && $period !== '' && (string)($run['period'] ?? '') !== $period) {
				continue;
			}

			$out[] = $run;
		}

		return $out;
	}//end approvedRuns()

	/**
	 * Build the outcome array returned by postRun()/postApprovedRuns().
	 *
	 * @param string $runId The PayrollRun id.
	 * @param string $status The outcome status.
	 * @param string $message A human-readable outcome message.
	 * @param array<string, mixed>|null $glPost The PayrollGLPost record, if any.
	 * @param string|null $journalEntryId The shillinq JournalEntry id, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $runId, string $status, string $message, ?array $glPost = null, ?string $journalEntryId = null): array {
		$glPostId = null;
		if ($glPost !== null) {
			$glPostId = (string)($glPost['id'] ?? $glPost['@self']['id'] ?? '');
			$glPostId = ($glPostId === '' ? null : $glPostId);
		}

		return [
			'runId' => $runId,
			'status' => $status,
			'message' => $message,
			'glPostId' => $glPostId,
			'journalEntryId' => $journalEntryId,
		];

	}//end outcome()

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to arrays.
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end normaliseRows()

	/**
	 * Normalise a single ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured humaniq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
