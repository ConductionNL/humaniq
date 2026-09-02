<?php

/**
 * Payroll Net Pay Node
 *
 * `humaniq.payroll-netpay`: the flow adapter over
 * `PayrollNetPayService::processRun()` (payroll-run-as-a-flow design.md D3).
 * The service keeps its fail-closed line collection, the two-layer
 * idempotency and the duck-typed shillinq availability; this node only
 * routes the outcome: `created` and `skipped-no-shillinq` travel with the
 * item under `netpay` (the run stays payable and a later pass retries),
 * `failed` throws so the step's `onError` policy decides (REQ-PRF-004).
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use OCA\Humaniq\Service\PayrollNetPayService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raises the SEPA net-pay batch for a payable payroll run.
 *
 * @psalm-suppress MissingDependency IFlowNode is OpenRegister's, loaded at
 *     runtime and suppressed as an undefined class in psalm.xml, so psalm
 *     cannot verify the implements-relationship here. The declared contract
 *     is real: the vendored test stub mirrors it method-for-method and the
 *     unit suite compiles against it.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
 */
class PayrollNetPayNode extends PayrollFlowNodeBase {

	/**
	 * The item key the outcome lands under.
	 *
	 * @var string
	 */
	public const OUTCOME_KEY = 'netpay';

	/**
	 * @param IL10N $l10n Localisation.
	 * @param IURLGenerator $urls URL generator.
	 * @param ContainerInterface $container DI container.
	 * @param SettingsService $settingsService Settings bridge.
	 * @param LoggerInterface $logger Logger.
	 * @param PayrollNetPayService $netPayService The existing SEPA handoff service, reused as-is.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function __construct(
		IL10N $l10n,
		IURLGenerator $urls,
		ContainerInterface $container,
		SettingsService $settingsService,
		LoggerInterface $logger,
		private readonly PayrollNetPayService $netPayService,
	) {
		parent::__construct($l10n, $urls, $container, $settingsService, $logger);

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function getId(): string {
		return 'humaniq.payroll-netpay';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Prepare net pay batch');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function getDescription(): string {
		return $this->l10n->t('Prepare the SEPA payment batch that pays each employee the net wage of the payroll run.');
	}//end getDescription()

	/**
	 * Prepare the batch for the run each item names.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration (`runId`, default `{{ payroll.runId }}`).
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying the outcome under `netpay`.
	 *
	 * @throws RuntimeException When the service reports `failed`.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $context is part of the
	 *     IFlowNode::execute() contract; this adapter reads no run-level
	 *     metadata (the acting identity is applied by the dispatcher).
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function execute(array $items, array $config, array $context): array {
		if ($items === []) {
			return [];
		}

		$out = [];
		foreach ($items as $item) {
			$json = (array)(((array)$item)['json'] ?? []);
			$run = $this->requireRun(config: $config, json: $json);

			$outcome = $this->netPayService->processRun($run);

			$status = (string)($outcome['status'] ?? '');
			if (in_array($status, ['created', 'skipped-no-shillinq'], true) === false) {
				throw new RuntimeException(
					'Netto-loonbatch voor loonrun ' . $this->idOf($run) . ' is mislukt (' . $status . '): '
					. (string)($outcome['message'] ?? '')
				);
			}

			$out[] = $this->withOutcome((array)$item, self::OUTCOME_KEY, $outcome);
		}//end foreach

		return $out;
	}//end execute()

}//end class
