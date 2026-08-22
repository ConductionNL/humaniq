<?php

/**
 * Leave Buy/Sell Settlement Service
 *
 * The imperative balance-mutating write for leave-buy-sell (design.md D4):
 * lifecycle guards are read-only per OpenRegister's contract, so
 * `LeaveSettlementPeriodGuard` alone cannot adjust the employee's leave
 * balance — this service owns that write, the `CompAdjustmentService`
 * imperative-write-beside-a-guarded-transition idiom.
 *
 * For an approved LeaveTransaction with a valid settlementPeriod it:
 *
 * 1. Short-circuits an already-`settled` transaction (idempotent no-op — a
 *    retry, a duplicate API call, or a redelivered webhook can never
 *    double-apply the balance delta).
 * 2. Refuses non-approved, missing/malformed-settlementPeriod, and
 *    balance-unresolvable transactions (belt-and-braces: the same
 *    preconditions `LeaveSettlementPeriodGuard` and
 *    `LeaveBuySellApprovalGuard` already enforce, re-checked here because a
 *    service write should never rely solely on a guard it does not control
 *    the invocation of — the `CompAdjustmentService` principle verbatim).
 * 3. For `sell`, re-checks `bovenwettelijkHours >= hours` immediately before
 *    writing (D2.2) — a concurrent sell that drained the balance between
 *    approval and settlement is refused rather than writing a negative
 *    balance.
 * 4. Computes `settledAmount = round(hours * hourlyRate, 2)`.
 * 5. Writes `LeaveBalance.bovenwettelijkHours` — `+hours` for buy, `-hours`
 *    for sell — the ONLY field touched on the balance.
 *    `entitledHours`/`usedHours` are NEVER written by this service (grep-
 *    verifiable): because `nl-verlof-wettelijk-minimum` is evaluated solely
 *    against `entitledHours`/`contractHoursPerWeek`, a sell settled through
 *    this service structurally cannot breach the statutory floor.
 * 6. Writes the LeaveTransaction's `settledAmount`/`settledAt`/`status:
 *    settled` on the ordinary object write that carries the `settle`
 *    transition (the `NoSelfApprovalGuard`/`CompAdjustmentService` idiom — no
 *    separate "transition" API exists in this codebase).
 *
 * Never auto-provisions a missing LeaveBalance (named follow-up
 * `leave-balance-auto-provision`) and never touches a PayrollRun directly —
 * the euro effect is read (never recomputed) by `PayrollRunService::generate()`
 * once folded into the current run's Payslip.
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
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-002
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Idempotently settles an approved LeaveTransaction: re-checked sufficiency
 * for a sell, the cents-exact settledAmount computation, the
 * bovenwettelijkHours-only balance write, and the transition-carrying stamp.
 */
class LeaveBuySellSettlementService {

	/**
	 * Max objects loaded per type when scanning for a balance/run match.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

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
	 * Settle one LeaveTransaction by id — the guarded endpoint's/occ
	 * command's entry point (design.md D4). The controller/command has
	 * already RBAC-resolved (or otherwise obtained) the transaction; this
	 * re-fetches it and applies the full approved+settlementPeriod+
	 * sufficiency predicate before writing.
	 *
	 * @param string $transactionId The LeaveTransaction id.
	 *
	 * @return array<string, mixed> Outcome: {transactionId, status, message, settledAmount?}.
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-002
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
	 */
	public function settle(string $transactionId): array {
		$transactionId = trim($transactionId);
		if ($transactionId === '') {
			return $this->outcome('', 'failed', 'Geen transactionId opgegeven.');
		}

		$transaction = $this->findById('LeaveTransaction', $transactionId);
		if ($transaction === null) {
			return $this->outcome($transactionId, 'failed', 'Transactie niet gevonden.');
		}

		$status = (string)($transaction['status'] ?? '');
		if ($status === 'settled') {
			return $this->outcome($transactionId, 'already-settled', 'Transactie is al verrekend; niets te doen (idempotent).');
		}

		if ($status !== 'approved') {
			return $this->outcome(
				$transactionId,
				'refused-not-approved',
				'Transactie heeft status "' . ($status !== '' ? $status : 'onbekend') . '" — alleen goedgekeurde transacties kunnen worden verrekend.'
			);
		}

		$settlementPeriod = trim((string)($transaction['settlementPeriod'] ?? ''));
		if ($settlementPeriod === '' || preg_match('/^\d{4}-\d{2}$/', $settlementPeriod) !== 1) {
			return $this->outcome($transactionId, 'refused-no-settlement-period', 'Transactie heeft geen (geldige) verrekenperiode; verrekenen is geweigerd.');
		}

		$employeeId = trim((string)($transaction['employeeId'] ?? ''));
		$year = (int)($transaction['year'] ?? 0);
		$leaveType = trim((string)($transaction['leaveType'] ?? ''));

		$balance = $this->resolveBalance($employeeId, $year, $leaveType);
		if ($balance === null) {
			return $this->outcome($transactionId, 'refused-balance-unresolvable', 'Er is geen verlofsaldo gevonden voor deze medewerker/jaar/verloftype; verrekenen is geweigerd.');
		}

		$transactionType = (string)($transaction['transactionType'] ?? '');
		$hours = (float)($transaction['hours'] ?? 0);
		$bovenwettelijkHours = (float)($balance['bovenwettelijkHours'] ?? 0);

		if ($transactionType === 'sell' && $bovenwettelijkHours < $hours) {
			return $this->outcome(
				$transactionId,
				'refused-insufficient-bovenwettelijk',
				sprintf('Onvoldoende bovenwettelijke verlofuren (%s beschikbaar, %s aangevraagd om te verkopen); verrekenen is geweigerd.', $bovenwettelijkHours, $hours)
			);
		}

		$hourlyRate = (float)($transaction['hourlyRate'] ?? 0);
		$settledAmount = round(($hours * $hourlyRate), 2);

		$newBovenwettelijkHours = $transactionType === 'sell'
			? ($bovenwettelijkHours - $hours)
			: ($bovenwettelijkHours + $hours);

		try {
			$balanceUpdate = $balance;
			$balanceUpdate['bovenwettelijkHours'] = $newBovenwettelijkHours;
			unset($balanceUpdate['@self']);

			$this->objectService()->saveObject(
				object: $balanceUpdate,
				register: $this->register(),
				schema: 'LeaveBalance',
				uuid: $this->idOf($balance),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('LeaveBuySellSettlementService: kon bovenwettelijkHours niet bijwerken voor transactie ' . $transactionId . ': ' . $e->getMessage());
			return $this->outcome($transactionId, 'failed', 'Bijwerken van het verlofsaldo is mislukt: ' . $e->getMessage());
		}

		try {
			$transactionUpdate = $transaction;
			$transactionUpdate['settledAmount'] = $settledAmount;
			$transactionUpdate['settledAt'] = gmdate('Y-m-d\TH:i:s\Z');
			$transactionUpdate['status'] = 'settled';
			$transactionUpdate['settlementPayrollRunId'] = $this->resolveRunForPeriod($settlementPeriod);
			unset($transactionUpdate['@self']);

			$this->objectService()->saveObject(
				object: $transactionUpdate,
				register: $this->register(),
				schema: 'LeaveTransaction',
				uuid: $transactionId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('LeaveBuySellSettlementService: saldo bijgewerkt, maar transactie ' . $transactionId . ' kon niet verrekend worden gemarkeerd: ' . $e->getMessage());
			return $this->outcome($transactionId, 'failed', 'Verlofsaldo is bijgewerkt, maar de transactie kon niet als verrekend worden gemarkeerd: ' . $e->getMessage());
		}

		$outcome = $this->outcome($transactionId, 'settled', 'Verlofsaldo bijgewerkt (' . $transactionType . ' ' . $hours . ' uur); transactie is verrekend voor ' . $settledAmount . '.');
		$outcome['settledAmount'] = $settledAmount;
		$outcome['employeeId'] = $employeeId;

		return $outcome;
	}//end settle()

	/**
	 * Resolve the LeaveBalance matching (employeeId, year, leaveType), or
	 * null when none resolves — no composite-key `find()` exists on
	 * ObjectService, so this scans `loadAll('LeaveBalance')`.
	 *
	 * @param string $employeeId The Employee id.
	 * @param int $year The calendar year.
	 * @param string $leaveType The leave type.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveBalance(string $employeeId, int $year, string $leaveType): ?array {
		if ($employeeId === '' || $year <= 0 || $leaveType === '') {
			return null;
		}

		foreach ($this->loadAll('LeaveBalance') as $balance) {
			if ((string)($balance['employeeId'] ?? '') === $employeeId
				&& (int)($balance['year'] ?? 0) === $year
				&& (string)($balance['leaveType'] ?? '') === $leaveType
			) {
				return $balance;
			}
		}

		return null;
	}//end resolveBalance()

	/**
	 * The PayrollRun of a given period, any status — used only to stamp
	 * `settlementPayrollRunId` when settling (the retro-adjustments
	 * `RetroAdjustmentService::resolveRunForPeriod()` precedent). Not
	 * required to exist; never blocks settlement.
	 *
	 * @param string $period Wage period, `YYYY-MM`.
	 *
	 * @return string|null
	 */
	private function resolveRunForPeriod(string $period): ?string {
		foreach ($this->loadAll('PayrollRun') as $run) {
			if ((string)($run['period'] ?? '') === $period) {
				$id = $this->idOf($run);
				return $id === '' ? null : $id;
			}
		}

		return null;
	}//end resolveRunForPeriod()

	/**
	 * Find one object by id, or null when it cannot be loaded/does not exist.
	 *
	 * @param string $schema The schema name.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findById(string $schema, string $id): ?array {
		try {
			$row = $this->objectService()->find(id: $id, register: $this->register(), schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->info('LeaveBuySellSettlementService: kon ' . $schema . ' ' . $id . ' niet laden: ' . $e->getMessage());
			return null;
		}

		if ($row === null) {
			return null;
		}

		return $this->toArray($row);
	}//end findById()

	/**
	 * Load all objects of a schema (capped), as plain arrays. Degrades to an
	 * empty list when the schema does not exist yet in the register.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('LeaveBuySellSettlementService: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end loadAll()

	/**
	 * Build the base outcome array.
	 *
	 * @param string $transactionId The LeaveTransaction id ('' when unknown).
	 * @param string $status Outcome status.
	 * @param string $message Human-readable outcome message.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $transactionId, string $status, string $message): array {
		return [
			'transactionId' => ($transactionId === '' ? null : $transactionId),
			'status' => $status,
			'message' => $message,
		];

	}//end outcome()

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
