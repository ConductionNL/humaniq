<?php

/**
 * Wage Tax Remittance Service
 *
 * Hands the amount of a confirmed wage tax return into shillinq as a draft
 * payable to the Belastingdienst, next to the payroll journal entry and the
 * net pay batch (payroll-wage-tax-remittance-shillinq). humaniq writes a
 * draft APTransaction and pays nothing: receiving, issuing, the payment run
 * and the bank file stay shillinq's.
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
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Writes one draft shillinq APTransaction per confirmed wage tax return.
 *
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */
class WageTaxRemittanceService {

	/**
	 * Humaniq's log of each hand-off.
	 *
	 * @var string
	 */
	private const RECORD_SCHEMA = 'WageTaxRemittance';

	/**
	 * Run statuses that are final enough to pay (as for net pay).
	 *
	 * @var string[]
	 */
	private const PAYABLE_RUN_STATUSES = ['approved', 'posted'];

	/**
	 * Return statuses whose amount will not change (design D3).
	 *
	 * @var string[]
	 */
	private const CONFIRMED_RETURN_STATUSES = ['bevestigd', 'verzonden'];

	/**
	 * The shillinq side of the hand-off.
	 *
	 * @var ShillinqPayables
	 */
	private readonly ShillinqPayables $payables;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container       For the lazy ObjectService.
	 * @param IAppManager        $appManager      To probe for shillinq.
	 * @param SettingsService    $settingsService Register slug, payee and account.
	 * @param LoggerInterface    $logger          The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		$this->payables = new ShillinqPayables(container: $container, appManager: $appManager, settingsService: $settingsService, logger: $logger);
	}//end __construct()

	/**
	 * Hand off the confirmed return of every payable run, optionally for one
	 * wage period.
	 *
	 * @param string|null $period Only runs of this wage period (YYYY-MM), or null for all.
	 *
	 * @return list<array<string, mixed>> One outcome per run.
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
	 */
	public function processPayableRuns(?string $period=null): array {
		$outcomes = [];
		foreach ($this->rowsOf(schema: 'PayrollRun') as $run) {
			if (in_array((string)($run['status'] ?? ''), self::PAYABLE_RUN_STATUSES, true) === false) {
				continue;
			}

			if ($period !== null && $period !== '' && (string)($run['period'] ?? '') !== $period) {
				continue;
			}

			$outcomes[] = $this->processRun(run: $run);
		}

		return $outcomes;
	}//end processPayableRuns()

	/**
	 * Hand off the confirmed return of one payable run.
	 *
	 * @param array<string, mixed> $run The PayrollRun.
	 *
	 * @return array<string, mixed> Outcome: runId, status, message, recordId, payableId.
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-002
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-003
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-004
	 */
	public function processRun(array $run): array {
		$runId = $this->payables->idOf(row: $run);
		$filing = $this->confirmedReturnOf(runId: $runId);
		if ($runId === '' || $filing === null) {
			return $this->outcome(runId: $runId, status: 'no-return', message: 'No confirmed or sent wage tax return was made from this run yet.');
		}

		$filingId = $this->payables->idOf(row: $filing);
		$base = [
			'payrollRunId' => $runId,
			'filingId' => $filingId,
			'period' => (string)($filing['period'] ?? ($run['period'] ?? '')),
			'administrationId' => self::nullable(value: ($run['administrationId'] ?? null)),
		];

		$created = $this->createdRecordFor(filingId: $filingId);
		if ($created !== null) {
			return $this->outcome(runId: $runId, status: 'created', message: 'Already in shillinq.', record: $created);
		}

		if ($this->payables->available() === false) {
			return $this->finish(runId: $runId, fields: $base + ['status' => 'skipped-no-shillinq', 'errorMessage' => 'shillinq is not installed or its payables cannot be read. The next run tries again.']);
		}

		$amount = (float)(int)($filing['collectiveTotals']['TotGen'] ?? 0);
		$reference = trim((string)($filing['betalingskenmerk'] ?? ''));
		$base += ['amount' => $amount, 'paymentReference' => self::nullable(value: $reference), 'dueDate' => self::nullable(value: ($filing['deadline'] ?? null))];
		if ($amount <= 0.0) {
			return $this->finish(runId: $runId, fields: $base + ['status' => 'nothing-to-pay']);
		}

		$problem = $this->problemWith(reference: $reference, payeeId: $this->settingsService->getWageTaxPayeeId());
		if ($problem !== null) {
			return $this->finish(runId: $runId, fields: $base + ['status' => 'failed', 'errorMessage' => $problem]);
		}

		$payload = (new WageTaxPayable())->build(run: $run, filing: $filing, amount: $amount, payeeId: $this->settingsService->getWageTaxPayeeId(), account: $this->settingsService->getGlPostAccountWageTaxLiability());
		try {
			$payableId = $this->payables->createOrAdopt(payload: $payload);
		} catch (\Throwable $e) {
			return $this->finish(runId: $runId, fields: $base + ['status' => 'failed', 'errorMessage' => 'Writing the payable into shillinq failed: ' . $e->getMessage()]);
		}

		return $this->finish(
			runId: $runId,
			fields: $base + ['status' => 'created', 'shillinqPayableRef' => $payableId, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z')],
			payableId: $payableId
		);
	}//end processRun()

	/**
	 * Why nothing may be written, or null (design D4, fail closed).
	 *
	 * @param string $reference The return's payment reference.
	 * @param string $payeeId   The configured shillinq payee.
	 *
	 * @return string|null
	 */
	private function problemWith(string $reference, string $payeeId): ?string {
		if ($reference === '') {
			return 'The wage tax return has no payment reference (betalingskenmerk). Fill it in on the return.';
		}

		if (trim($payeeId) === '') {
			return 'No shillinq payee for the Belastingdienst is set. Set the app setting wagetax_payee_id.';
		}

		if ($this->payables->hasPayee(payeeId: $payeeId) === true) {
			return null;
		}

		return 'shillinq has no payee ' . $payeeId . '. Check the app setting wagetax_payee_id.';
	}//end problemWith()

	/**
	 * The confirmed regular NL return made from a run (design D3).
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function confirmedReturnOf(string $runId): ?array {
		foreach ($this->rowsOf(schema: 'LoonaangifteFiling') as $filing) {
			if ((string)($filing['messageRunId'] ?? '') === $runId
				&& (string)($filing['jurisdiction'] ?? '') === 'NL'
				&& (string)($filing['filingType'] ?? '') === 'loonaangifte'
				&& in_array((string)($filing['status'] ?? ''), self::CONFIRMED_RETURN_STATUSES, true) === true
			) {
				return $filing;
			}
		}

		return null;
	}//end confirmedReturnOf()

	/**
	 * The created record of a return, if any (design D5).
	 *
	 * @param string $filingId The return.
	 *
	 * @return array<string, mixed>|null
	 */
	private function createdRecordFor(string $filingId): ?array {
		foreach ($this->rowsOf(schema: self::RECORD_SCHEMA) as $record) {
			if ((string)($record['filingId'] ?? '') === $filingId && (string)($record['status'] ?? '') === 'created') {
				return $record;
			}
		}

		return null;
	}//end createdRecordFor()

	/**
	 * Write the record and return the outcome.
	 *
	 * @param string               $runId     The run.
	 * @param array<string, mixed> $fields    The record.
	 * @param string|null          $payableId The shillinq payable, if written.
	 *
	 * @return array<string, mixed>
	 */
	private function finish(string $runId, array $fields, ?string $payableId=null): array {
		$saved = $this->objectService()->saveObject(
			object: $fields,
			register: $this->settingsService->getRegisterSlug(),
			schema: self::RECORD_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
		$status = (string)$fields['status'];
		$message = (string)($fields['errorMessage'] ?? ($status === 'created' ? 'Draft payable written to shillinq.' : 'Nothing to pay for this return.'));

		return $this->outcome(runId: $runId, status: $status, message: $message, record: $this->payables->toArray(row: $saved), payableId: $payableId);
	}//end finish()

	/**
	 * Rows of a humaniq schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $schema): array {
		try {
			$rows = $this->objectService()->setRegister($this->settingsService->getRegisterSlug())->setSchema($schema)->findAll(['limit' => 10000]);
		} catch (\Throwable $e) {
			$this->logger->warning('WageTaxRemittanceService: could not read ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return array_map(fn (mixed $row): array => $this->payables->toArray(row: $row), array_values((array)$rows));
	}//end rowsOf()

	/**
	 * The ObjectService, after checking OpenRegister is there (ADR-083).
	 *
	 * @return mixed
	 */
	private function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException('humaniq requires the OpenRegister app, which is not installed on this instance.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * An outcome array.
	 *
	 * @param string                    $runId     The run.
	 * @param string                    $status    The status.
	 * @param string                    $message   The message.
	 * @param array<string, mixed>|null $record    The record.
	 * @param string|null               $payableId The payable.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $runId, string $status, string $message, ?array $record=null, ?string $payableId=null): array {
		return [
			'runId' => $runId,
			'status' => $status,
			'message' => $message,
			'recordId' => ($record !== null ? $this->payables->idOf(row: $record) : null),
			'payableId' => ($payableId ?? ($record['shillinqPayableRef'] ?? null)),
		];
	}//end outcome()

	/**
	 * Null for an empty value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function nullable(mixed $value): ?string {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		return $text;
	}//end nullable()

}//end class
