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
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds and posts the balanced payroll-to-GL journal for approved runs.
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
	 * Required numeric PayrollRun totals the balanced journal is built from.
	 *
	 * @var string[]
	 */
	private const REQUIRED_TOTALS = ['totalGross', 'totalEmployerCharges', 'totalLoonheffing', 'totalNet'];

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

		$journalNumber = sprintf('HRMQ-LOON-%s-%s', $period, $administrationId);

		$active = $this->activeGlPostForRun($runId);
		if ($active !== null && (string)($active['status'] ?? '') === 'posted') {
			return $this->outcome($runId, 'posted', 'Already posted (idempotent no-op).', $active, (string)($active['journalEntryId'] ?? null));
		}

		if ($active !== null && (string)($active['status'] ?? '') === 'pending') {
			$recovered = $this->recoverStalePending($active, $run, $journalNumber);
			if ($recovered !== null) {
				return $recovered;
			}
			// Stale pending record marked failed (superseded); fall through to a fresh attempt.
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

		$built = $this->buildLines($run);
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

		try {
			$journalEntryId = $this->createOrAdoptJournalEntry($journalNumber, $period, $administrationId, $runId, $built['lines']);
		} catch (\Throwable $e) {
			$glPost = $this->createGlPost(
				[
					'payrollRunId' => $runId,
					'period' => $period,
					'status' => 'failed',
					'errorMessage' => 'Aanmaken van de shillinq JournalEntry is mislukt: ' . $e->getMessage(),
				]
			);

			return $this->outcome($runId, 'failed', (string)$glPost['errorMessage'], $glPost);
		}

		$glPost = $this->createGlPost(
			[
				'payrollRunId' => $runId,
				'period' => $period,
				'status' => 'posted',
				'journalEntryId' => $journalEntryId,
				'journalNumber' => $journalNumber,
				'postedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				'lines' => $built['lines'],
			]
		);

		$this->applySuccessToRun($run, (float)$built['glExpensePosted'], (float)$built['glLiabilityPosted']);

		return $this->outcome($runId, 'posted', 'Geboekt als shillinq journaalpost ' . $journalNumber . '.', $glPost, $journalEntryId);
	}//end postRun()

	/**
	 * Build the balanced 4-line journal from a run's totals (design.md D2), pure
	 * and side-effect free so it is directly unit-testable. All arithmetic is
	 * done in integer cents to guarantee debits equal credits by construction
	 * regardless of float rounding.
	 *
	 * @param array<string, mixed> $run The PayrollRun object.
	 *
	 * @return array<string, mixed> {lines, error, glExpensePosted, glLiabilityPosted}.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 */
	public function buildLines(array $run): array {
		foreach (self::REQUIRED_TOTALS as $field) {
			if (isset($run[$field]) === false || is_numeric($run[$field]) === false) {
				return $this->buildFailure(sprintf('PayrollRun-veld "%s" ontbreekt of is niet numeriek.', $field));
			}
		}

		$grossCents = (int)round(((float)$run['totalGross']) * 100);
		$chargesCents = (int)round(((float)$run['totalEmployerCharges']) * 100);
		$loonheffingCents = (int)round(((float)$run['totalLoonheffing']) * 100);
		$netCents = (int)round(((float)$run['totalNet']) * 100);

		$debitTotalCents = ($grossCents + $chargesCents);
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

		$candidates = [
			['side' => 'debit', 'accountNumber' => $this->settingsService->getGlPostAccountGross(), 'amount' => $grossCents, 'description' => 'Loonkosten bruto'],
			['side' => 'debit', 'accountNumber' => $this->settingsService->getGlPostAccountEmployerCharges(), 'amount' => $chargesCents, 'description' => 'Werkgeverslasten sociale premies'],
			['side' => 'credit', 'accountNumber' => $this->settingsService->getGlPostAccountWageTaxLiability(), 'amount' => $loonheffingCents, 'description' => 'Loonheffing-schuld'],
			['side' => 'credit', 'accountNumber' => $this->settingsService->getGlPostAccountNetWagesLiability(), 'amount' => $netLiabilityCents, 'description' => 'Netto-loonschuld'],
		];

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
	 * Resolve a stale `pending` PayrollGLPost via the deterministic journalNumber
	 * probe (design.md D6, crash-recovery): a prior invocation crashed after
	 * marking `pending` but before recording the outcome. If shillinq already
	 * carries a JournalEntry for this journalNumber, adopt it as `posted`
	 * (including the run's success effects); otherwise mark the stale record
	 * `failed` (superseded) and return null so the caller starts a fresh attempt.
	 *
	 * @param array<string, mixed> $stalePending The stale `pending` PayrollGLPost.
	 * @param array<string, mixed> $run The PayrollRun being posted.
	 * @param string $journalNumber The deterministic idempotency key.
	 *
	 * @return array<string, mixed>|null The outcome when adopted, or null to retry fresh.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	private function recoverStalePending(array $stalePending, array $run, string $journalNumber): ?array {
		$runId = (string)($run['id'] ?? $run['@self']['id'] ?? '');

		if ($this->shillinqAvailable() === false) {
			return null;
		}

		$adopted = $this->findJournalEntryByNumber($journalNumber);
		if ($adopted === null) {
			$this->saveGlPost($stalePending, ['status' => 'failed', 'errorMessage' => 'Verlopen pending-poging vervangen; geen bijbehorende shillinq-journaalpost gevonden.']);
			return null;
		}

		$journalEntryId = (string)($adopted['id'] ?? $adopted['@self']['id'] ?? '');
		$glPost = $this->saveGlPost(
			$stalePending,
			[
				'status' => 'posted',
				'journalEntryId' => $journalEntryId,
				'journalNumber' => $journalNumber,
				'postedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				'errorMessage' => null,
			]
		);

		$built = $this->buildLines($run);
		if ($built['error'] === null) {
			$this->applySuccessToRun($run, (float)$built['glExpensePosted'], (float)$built['glLiabilityPosted']);
		}

		return $this->outcome($runId, 'posted', 'Bestaande shillinq-journaalpost overgenomen (crash-recovery).', $glPost, $journalEntryId);
	}//end recoverStalePending()

	/**
	 * Find an existing shillinq JournalEntry by journalNumber (idempotency
	 * probe, design.md D6), or create it when none exists yet.
	 *
	 * @param string $journalNumber Deterministic idempotency key.
	 * @param string $period Wage period (YYYY-MM).
	 * @param string $administrationId Administration/employer id, passed through verbatim.
	 * @param string $payrollRunId The humaniq PayrollRun id (for the description).
	 * @param array<int, mixed> $lines The balanced journal lines.
	 *
	 * @return string The shillinq JournalEntry id (adopted or newly created).
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-003
	 */
	private function createOrAdoptJournalEntry(string $journalNumber, string $period, string $administrationId, string $payrollRunId, array $lines): string {
		$adopted = $this->findJournalEntryByNumber($journalNumber);
		if ($adopted !== null) {
			return (string)($adopted['id'] ?? $adopted['@self']['id'] ?? '');
		}

		$payload = [
			'journalNumber' => $journalNumber,
			'entryDate' => $this->periodEndDate($period),
			'description' => sprintf('Loonjournaalpost %s — humaniq loonrun %s', $period, $payrollRunId),
			'lines' => $lines,
			'journalType' => 'manual',
			'approvalState' => 'not-required',
			'administrationId' => $administrationId,
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
	 * The last day of a YYYY-MM wage period, per design.md D3 (`entryDate` =
	 * last day of the wage period).
	 *
	 * @param string $period Wage period in YYYY-MM.
	 *
	 * @return string The period-end date (Y-m-d).
	 */
	private function periodEndDate(string $period): string {
		try {
			$date = new DateTimeImmutable($period . '-01');
		} catch (\Throwable $e) {
			// Defensive fallback; upstream schema validation guards the YYYY-MM shape.
			return $period . '-28';
		}

		$lastDay = $date->modify('last day of this month');
		return $lastDay === false ? ($period . '-28') : $lastDay->format('Y-m-d');
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
			$rows = $this->objectService()->setRegister(self::SHILLINQ_REGISTER)->setSchema(self::SHILLINQ_SCHEMA)->findAll(['limit' => 10000]);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollGLPostService: kon shillinq JournalEntry niet doorzoeken: ' . $e->getMessage());
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
	 * The PayrollGLPost rows referencing a given PayrollRun.
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function glPostsForRun(string $runId): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema(self::GLPOST_SCHEMA)->findAll(['limit' => 10000]);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollGLPostService: kon PayrollGLPost niet laden: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ($this->normaliseRows($rows) as $row) {
			if ((string)($row['payrollRunId'] ?? '') === $runId) {
				$out[] = $row;
			}
		}

		return $out;
	}//end glPostsForRun()

	/**
	 * The active (pending/posted) PayrollGLPost for a run, if any — the
	 * at-most-one-per-run invariant (design.md D6).
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function activeGlPostForRun(string $runId): ?array {
		foreach ($this->glPostsForRun($runId) as $row) {
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
