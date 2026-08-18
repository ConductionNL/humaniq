<?php

/**
 * Payroll Mutation Service
 *
 * The per-run payroll diff an accountant reviews before flipping a run from
 * `draft` to `approved` (design.md D1-D3): resolves two PayrollRuns
 * (`fromRunId`/`toRunId`, or auto-resolves the prior period when only
 * `toRunId` is given — D4), loads each run's `payrollRunId`-scoped Payslips
 * (the `RuleAuditService::auditPayrollRunScope` idiom), keys both sets by
 * `employeeId`, and classifies every employee entered/left/changed/unchanged
 * by set membership + headline-component equality. For shared employees it
 * computes exact per-component deltas on the four headline figures
 * (grossPay, nettoPay, loonheffing, employer cost =
 * werknemersverzekeringen + zvw) and rolls them into run-level totals
 * (grossDelta/netDelta/loonheffingDelta/employerCostDelta/
 * totalWageCostDelta + the four counts).
 *
 * Verified against HEAD 2026-07-14: Payslip's money fields
 * (grossPay/nettoPay/loonheffing/werknemersverzekeringen/zvw) are
 * euro-denominated `number`s with 2-decimal precision — `PayrollRunService`
 * writes them via its `euros()` helper (cents -> round(cents/100, 2)), NOT
 * literal integer-cents integers. To honour design.md D1's "integer cents,
 * no float accumulation, no rounding" contract against the REAL storage
 * format, every component is converted to integer cents at read time
 * (`round($euros * 100)`) before any classification or arithmetic, and
 * converted back to euros only at the boundary (persisted report fields /
 * occ table output) — the same cents<->euros boundary PayrollRunService
 * already draws, applied in reverse.
 *
 * This service is PURE and read-only over the register: it never
 * constructs `PayrollCalculator`, never reads a tax table, and never writes
 * a PayrollRun or Payslip — it only reads persisted payslips and subtracts,
 * so it cannot drift from the engine (design.md D3). The only write this
 * service ever performs is the idempotent upsert of ONE
 * `PayrollMutationReport`, keyed (fromRunId, toRunId) (design.md D7).
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-002
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-003
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-005
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-006
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pure run-to-run payroll diff service — reads persisted Payslips only.
 */
class PayrollMutationService {

	/**
	 * Max objects loaded per type.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * The four headline components a mutation line/roll-up carries, and the
	 * component key used for `lines[*].{component}Before/After/Delta`.
	 *
	 * @var array<int, string>
	 */
	private const COMPONENTS = ['grossPay', 'nettoPay', 'loonheffing', 'employerCost'];

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Compute the run-to-run diff (design.md D1-D5). Resolves `toRunId`
	 * (required), auto-resolves `fromRunId` when null (D4), refuses a
	 * cross-administration pair (D4), and takes the first-run path when no
	 * prior run exists (D5). Never persists — call `persist()` with the
	 * result to upsert the `PayrollMutationReport` (D7).
	 *
	 * @param string $toRunId The PayrollRun being reviewed.
	 * @param string|null $fromRunId The baseline PayrollRun, or null to auto-resolve the prior period.
	 *
	 * @return array<string, mixed> Outcome: {status, message, report} — `report` is the full diff payload (see buildReport()), null on failure/refusal.
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-006
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-007
	 */
	public function diff(string $toRunId, ?string $fromRunId = null): array {
		$toRunId = trim($toRunId);
		if ($toRunId === '') {
			return $this->outcome('failed', 'toRunId is verplicht.');
		}

		$runsById = $this->runsById();
		$toRun = ($runsById[$toRunId] ?? null);
		if ($toRun === null) {
			return $this->outcome('failed', 'Loonrun "' . $toRunId . '" niet gevonden.');
		}

		$fromRunId = ($fromRunId === null ? null : trim($fromRunId));

		if ($fromRunId === null || $fromRunId === '') {
			// D4 — auto-resolve the closest earlier period of the SAME
			// administration; none found => first-run path (D5).
			$fromRun = $this->resolvePriorRun($toRun, $runsById);
			$fromRunId = ($fromRun === null ? null : $this->idOf($fromRun));

			return $this->diffResolvedRuns($fromRun, $fromRunId, $toRun, $toRunId);
		}

		$fromRun = ($runsById[$fromRunId] ?? null);
		if ($fromRun === null) {
			return $this->outcome('failed', 'Loonrun "' . $fromRunId . '" niet gevonden.');
		}

		return $this->diffResolvedRuns($fromRun, $fromRunId, $toRun, $toRunId);
	}//end diff()

	/**
	 * Diff two already-resolved runs (design.md D4/D5).
	 *
	 * Extracted from {@see self::diff()} so the two ways of arriving at a
	 * baseline run — explicit id or auto-resolved prior period — share one
	 * tail instead of being joined by an else branch. `$fromRun` is null on
	 * the first-run path (D5), which `buildReport()` handles.
	 *
	 * @param array<string, mixed>|null $fromRun The baseline run, or null on the first-run path.
	 * @param string|null $fromRunId The baseline run id, or null on the first-run path.
	 * @param array<string, mixed> $toRun The run being reviewed.
	 * @param string $toRunId The reviewed run's id.
	 *
	 * @return array<string, mixed> Outcome: {status, message, report}.
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-006
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-007
	 */
	private function diffResolvedRuns(?array $fromRun, ?string $fromRunId, array $toRun, string $toRunId): array {
		if ($fromRun !== null
			&& (string)($fromRun['administrationId'] ?? '') !== (string)($toRun['administrationId'] ?? '')
		) {
			// D4 — a cross-administration comparison is meaningless; refuse
			// rather than silently diff.
			return $this->outcome('failed', 'Loonrun "' . $fromRunId . '" en "' . $toRunId . '" horen bij verschillende administraties — vergelijking geweigerd.');
		}

		$report = $this->buildReport($fromRun, $toRun);

		return $this->outcome('ok', 'Mutatierapport berekend.', $report);
	}//end diffResolvedRuns()

	/**
	 * Persist a diff() report as an idempotent `PayrollMutationReport`,
	 * probed by (fromRunId, toRunId) and upserted in place (design.md D7).
	 *
	 * @param array<string, mixed> $report The `report` payload from `diff()`'s outcome.
	 *
	 * @return array<string, mixed> Outcome: {status, message, reportId}.
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-005
	 */
	public function persist(array $report): array {
		$fromRunId = ($report['fromRunId'] ?? null);
		$toRunId = (string)($report['toRunId'] ?? '');

		$existing = $this->findExistingReport($fromRunId === null ? null : (string)$fromRunId, $toRunId);

		$payload = array_merge(
			$report,
			['generatedAt' => gmdate('Y-m-d\TH:i:s\Z')]
		);

		try {
			$saved = $this->toArray(
				$this->objectService()->saveObject(
					object: $payload,
					register: $this->register(),
					schema: 'PayrollMutationReport',
					uuid: ($existing === null ? null : $this->idOf($existing)),
					_rbac: false,
					_multitenancy: false
				)
			);
		} catch (\Throwable $e) {
			$this->logger->error('PayrollMutationService: kon PayrollMutationReport niet opslaan: ' . $e->getMessage());
			return ['status' => 'failed', 'message' => 'Opslaan van het mutatierapport is mislukt: ' . $e->getMessage(), 'reportId' => null];
		}

		return ['status' => 'ok', 'message' => 'Mutatierapport opgeslagen.', 'reportId' => $this->idOf($saved)];
	}//end persist()

	/**
	 * Build the full diff payload for one (from, to) run pair. `$fromRun`
	 * null is the first-run path (design.md D5): every `to` employee is
	 * `entered` with `before = 0`, and the run-level deltas equal the `to`
	 * run's own totals.
	 *
	 * @param array<string, mixed>|null $fromRun The baseline PayrollRun, or null (first run).
	 * @param array<string, mixed> $toRun The reviewed PayrollRun.
	 *
	 * @return array<string, mixed> {fromRunId, toRunId, fromPeriod, toPeriod, administrationId, enteredCount, leftCount, changedCount, unchangedCount, grossDelta, netDelta, loonheffingDelta, employerCostDelta, totalWageCostDelta, lines}.
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-002
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-003
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-007
	 */
	private function buildReport(?array $fromRun, array $toRun): array {
		$toRunId = $this->idOf($toRun);
		$fromRunId = ($fromRun === null ? null : $this->idOf($fromRun));

		$fromByEmployee = ($fromRun === null ? [] : $this->payslipCentsByEmployee($fromRunId));
		$toByEmployee = $this->payslipCentsByEmployee($toRunId);

		$employeeIds = array_unique(array_merge(array_keys($fromByEmployee), array_keys($toByEmployee)));
		sort($employeeIds);

		$lines = [];
		$counts = ['entered' => 0, 'left' => 0, 'changed' => 0, 'unchanged' => 0];
		$rollup = ['grossPay' => 0, 'nettoPay' => 0, 'loonheffing' => 0, 'employerCost' => 0];

		foreach ($employeeIds as $employeeId) {
			$from = ($fromByEmployee[$employeeId] ?? null);
			$to = ($toByEmployee[$employeeId] ?? null);

			$classification = $this->classify($from, $to);
			$counts[$classification]++;

			$line = ['employeeId' => $employeeId, 'classification' => $classification];
			foreach (self::COMPONENTS as $component) {
				$beforeCents = (int)($from[$component] ?? 0);
				$afterCents = (int)($to[$component] ?? 0);
				$deltaCents = ($afterCents - $beforeCents);

				$rollup[$component] += $deltaCents;

				$line[$component . 'Before'] = $this->euros($beforeCents);
				$line[$component . 'After'] = $this->euros($afterCents);
				$line[$component . 'Delta'] = $this->euros($deltaCents);
			}

			$lines[] = $line;
		}//end foreach

		$totalWageCostDeltaCents = ($rollup['grossPay'] + $rollup['employerCost']);

		return [
			'fromRunId' => $fromRunId,
			'toRunId' => $toRunId,
			'fromPeriod' => ($fromRun === null ? null : (string)($fromRun['period'] ?? '')),
			'toPeriod' => (string)($toRun['period'] ?? ''),
			'administrationId' => (string)($toRun['administrationId'] ?? ''),
			'enteredCount' => $counts['entered'],
			'leftCount' => $counts['left'],
			'changedCount' => $counts['changed'],
			'unchangedCount' => $counts['unchanged'],
			'grossDelta' => $this->euros($rollup['grossPay']),
			'netDelta' => $this->euros($rollup['nettoPay']),
			'loonheffingDelta' => $this->euros($rollup['loonheffing']),
			'employerCostDelta' => $this->euros($rollup['employerCost']),
			'totalWageCostDelta' => $this->euros($totalWageCostDeltaCents),
			'lines' => $lines,
		];

	}//end buildReport()

	/**
	 * Classify one employee's membership across the two payslip sets
	 * (design.md D1): entered/left when present only in one side, changed
	 * when any of the four headline components differ, unchanged when all
	 * four are equal. A first-run `$from === null` always yields `entered`.
	 *
	 * @param array<string, int>|null $from The employee's `from`-side cents components, or null.
	 * @param array<string, int>|null $to The employee's `to`-side cents components, or null.
	 *
	 * @return string `entered`|`left`|`changed`|`unchanged`.
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
	 */
	private function classify(?array $from, ?array $to): string {
		if ($from === null) {
			return 'entered';
		}

		if ($to === null) {
			return 'left';
		}

		foreach (self::COMPONENTS as $component) {
			if ((int)($from[$component] ?? 0) !== (int)($to[$component] ?? 0)) {
				return 'changed';
			}
		}

		return 'unchanged';
	}//end classify()

	/**
	 * D4 — the PayrollRun of the SAME administration whose `period` is the
	 * closest one strictly before `$toRun`'s period (string compare on the
	 * seeded YYYY-MM convention), or null when none exists (the first-run
	 * path, D5).
	 *
	 * @param array<string, mixed> $toRun The reviewed PayrollRun.
	 * @param array<string, array<string, mixed>> $runsById All PayrollRuns, by id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-006
	 */
	private function resolvePriorRun(array $toRun, array $runsById): ?array {
		$administrationId = (string)($toRun['administrationId'] ?? '');
		$toPeriod = (string)($toRun['period'] ?? '');
		$toRunId = $this->idOf($toRun);

		$best = null;
		$bestPeriod = null;
		foreach ($runsById as $candidateId => $candidate) {
			if ($candidateId === $toRunId) {
				continue;
			}

			if ((string)($candidate['administrationId'] ?? '') !== $administrationId) {
				continue;
			}

			$period = (string)($candidate['period'] ?? '');
			if ($period === '' || $period >= $toPeriod) {
				continue;
			}

			if ($bestPeriod === null || $period > $bestPeriod) {
				$best = $candidate;
				$bestPeriod = $period;
			}
		}

		return $best;
	}//end resolvePriorRun()

	/**
	 * This run's payslips (`payrollRunId === $runId`), keyed by employeeId,
	 * each component pre-converted to integer cents (see the class docblock
	 * on the euros<->cents boundary). Employer cost is
	 * `werknemersverzekeringen + zvw`, already summed in cents.
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, array<string, int>>
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-002
	 */
	private function payslipCentsByEmployee(string $runId): array {
		$out = [];
		if ($runId === '') {
			return $out;
		}

		foreach ($this->loadAll('Payslip') as $payslip) {
			if ((string)($payslip['payrollRunId'] ?? '') !== $runId) {
				continue;
			}

			$employeeId = (string)($payslip['employeeId'] ?? '');
			if ($employeeId === '') {
				continue;
			}

			$werknemersverzekeringenCents = $this->cents($payslip['werknemersverzekeringen'] ?? 0);
			$zvwCents = $this->cents($payslip['zvw'] ?? 0);

			$out[$employeeId] = [
				'grossPay' => $this->cents($payslip['grossPay'] ?? 0),
				'nettoPay' => $this->cents($payslip['nettoPay'] ?? 0),
				'loonheffing' => $this->cents($payslip['loonheffing'] ?? 0),
				'employerCost' => ($werknemersverzekeringenCents + $zvwCents),
			];
		}

		return $out;
	}//end payslipCentsByEmployee()

	/**
	 * The `PayrollMutationReport` with the same (fromRunId, toRunId), or
	 * null — the idempotency probe (design.md D7).
	 *
	 * @param string|null $fromRunId The baseline PayrollRun id, or null (first-run reports key on (null, toRunId)).
	 * @param string $toRunId The reviewed PayrollRun id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-005
	 */
	private function findExistingReport(?string $fromRunId, string $toRunId): ?array {
		foreach ($this->loadAll('PayrollMutationReport') as $candidate) {
			$candidateFrom = ($candidate['fromRunId'] ?? null);
			$candidateFrom = ($candidateFrom === null || $candidateFrom === '' ? null : (string)$candidateFrom);

			if ($candidateFrom === $fromRunId && (string)($candidate['toRunId'] ?? '') === $toRunId) {
				return $candidate;
			}
		}

		return null;
	}//end findExistingReport()

	/**
	 * All PayrollRuns, keyed by id — the `RuleAuditService::buildPayrollContext`
	 * `runsById` idiom (design.md Context).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function runsById(): array {
		$out = [];
		foreach ($this->loadAll('PayrollRun') as $run) {
			$id = $this->idOf($run);
			if ($id !== '') {
				$out[$id] = $run;
			}
		}

		return $out;
	}//end runsById()

	/**
	 * Build the base outcome array.
	 *
	 * @param string $status Outcome status (ok/failed).
	 * @param string $message Human-readable outcome message.
	 * @param array<string, mixed>|null $report The diff payload, when successful.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $status, string $message, ?array $report = null): array {
		return ['status' => $status, 'message' => $message, 'report' => $report];
	}//end outcome()

	/**
	 * Convert a euro-denominated field value to integer cents
	 * (`round($euros * 100)`) — the read-time boundary (class docblock).
	 *
	 * @param mixed $euros The raw field value.
	 *
	 * @return int
	 */
	private function cents(mixed $euros): int {
		return (int)round(((float)$euros) * 100);
	}//end cents()

	/**
	 * Convert integer cents to a euro float rounded to 2 decimals — the
	 * write/output-time boundary (class docblock), mirroring
	 * `PayrollRunService::euros()`.
	 *
	 * @param int $cents The cents amount.
	 *
	 * @return float
	 */
	private function euros(int $cents): float {
		return round(($cents / 100), 2);
	}//end euros()

	/**
	 * Load all objects of a schema (capped), as plain arrays. Degrades
	 * gracefully to an empty list when the schema does not exist yet (e.g.
	 * `PayrollMutationReport` before this change's register.d fragment has
	 * been imported).
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollMutationService: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end loadAll()

	/**
	 * Normalise an ObjectService row (entity or array) to an array.
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
	 * The object id of a row, falling back to `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');
	}//end idOf()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured hrmq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
