<?php

/**
 * Payroll Calculate Node
 *
 * `humaniq.payroll-calculate`: the flow adapter over
 * `PayrollRunService::runFor()` (payroll-run-as-a-flow design.md D3). The
 * service keeps every guarantee it already had — probe-before-create
 * idempotency, draft-only recalculation, per-employee skip reporting — and
 * this node adds none: it resolves the period (config template, default the
 * current month UTC), calls the service once per item, and puts the outcome
 * on the item under `payroll` so the review task and the downstream steps can
 * read `payroll.runId` and the totals.
 *
 * Outcome routing (REQ-PRF-002): `calculated` and `exists` travel with the
 * item; `failed` and `refused-not-draft` THROW — a flow asked to orchestrate
 * a run that is already booked truth must stop loudly, not carry a
 * success-shaped item into a review task.
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\Humaniq\Service\PayrollRunService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Creates or recalculates the draft payroll run for a wage period.
 *
 * @psalm-suppress MissingDependency IFlowNode is OpenRegister's, loaded at
 *     runtime and suppressed as an undefined class in psalm.xml, so psalm
 *     cannot verify the implements-relationship here. The declared contract
 *     is real: the vendored test stub mirrors it method-for-method and the
 *     unit suite compiles against it.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
 */
class PayrollCalculateNode extends PayrollFlowNodeBase {

	/**
	 * The item key the outcome lands under.
	 *
	 * @var string
	 */
	public const OUTCOME_KEY = 'payroll';

	/**
	 * @param PayrollFlowNodeServices $services Translations, icon URL, container and settings.
	 * @param LoggerInterface $logger Logger.
	 * @param PayrollRunService $payrollRunService The existing draft-run generator, reused as-is.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	public function __construct(
		PayrollFlowNodeServices $services,
		LoggerInterface $logger,
		private readonly PayrollRunService $payrollRunService,
	) {
		parent::__construct($services, $logger);

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	public function getId(): string {
		return 'humaniq.payroll-calculate';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Calculate payroll run');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	public function getDescription(): string {
		return $this->l10n->t('Create or recalculate the draft payroll run and its payslips for a wage period.');
	}//end getDescription()

	/**
	 * Refuse a literal period that is not a wage period. A templated value
	 * can only be checked at run time and an empty one falls back to the
	 * current month, so only a malformed literal is refusable here.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a literal `period` is malformed.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	public function validateConfig(array $config): void {
		$period = trim((string)($config['period'] ?? ''));
		if ($period === '' || str_contains($period, '{{') === true) {
			return;
		}

		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			throw new UnexpectedValueException(
				$this->l10n->t('The wage period must look like 2026-01 (YYYY-MM).')
			);
		}

	}//end validateConfig()

	/**
	 * Run the existing service once per item and let the outcome travel.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run-level metadata.
	 *
	 * @return array The items, each carrying the outcome under `payroll`.
	 *
	 * @throws RuntimeException When the service reports `failed` or `refused-not-draft`.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	public function execute(FlowNodeCall $call): array {
		$config = $call->config;
		if ($call->items === []) {
			return [];
		}

		// ValidateConfig() only runs when a flow is SAVED; an imported flow
		// reaches execute() unvalidated (the DossiqFlowNodeBase guard).
		$this->validateConfig(config: $config);

		return $this->stampEachItem(
			$call,
			fn (array $json): array => $this->calculateFor(json: $json, config: $config)
		);
	}//end execute()

	/**
	 * Calculate the run of the item's period and administration.
	 *
	 * @param array<string, mixed> $json   The item's record, for the placeholders.
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return array<string, mixed> The service outcome.
	 *
	 * @throws RuntimeException When the service reports `failed` or `refused-not-draft`.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	private function calculateFor(array $json, array $config): array {
		$period = $this->render((string)($config['period'] ?? ''), $json);
		if ($period === '') {
			$period = gmdate('Y-m');
		}

		$administrationId = $this->render((string)($config['administrationId'] ?? ''), $json);
		$recalculate = (($config['recalculate'] ?? true) !== false);

		$outcome = $this->payrollRunService->runFor(
			$period,
			($administrationId === '' ? null : $administrationId),
			$recalculate
		);

		return $this->requireStatus(
			$outcome,
			new ExpectedStatus(['calculated', 'exists'], 'Loonrun berekenen voor ' . $period . ' is geweigerd')
		);
	}//end calculateFor()

}//end class
