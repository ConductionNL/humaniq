<?php

/**
 * Payroll GL Post Node
 *
 * `humaniq.payroll-glpost`: the flow adapter over
 * `PayrollGLPostService::postRun()` (payroll-run-as-a-flow design.md D3).
 * The service keeps everything it already owned — the two-layer idempotency,
 * the deterministic journal number, the duck-typed shillinq availability —
 * and this node only routes its outcome: `posted` and `skipped-no-shillinq`
 * travel with the item under `glpost` (absent shillinq is the service's
 * DOCUMENTED degradation, the run stays approved and a later pass retries),
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

use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\Humaniq\Service\PayrollGLPostService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Posts the approved payroll run's journal to shillinq.
 *
 * @psalm-suppress MissingDependency IFlowNode is OpenRegister's, loaded at
 *     runtime and suppressed as an undefined class in psalm.xml, so psalm
 *     cannot verify the implements-relationship here. The declared contract
 *     is real: the vendored test stub mirrors it method-for-method and the
 *     unit suite compiles against it.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
 */
class PayrollGlPostNode extends PayrollFlowNodeBase {

	/**
	 * The item key the outcome lands under.
	 *
	 * @var string
	 */
	public const OUTCOME_KEY = 'glpost';

	/**
	 * @param PayrollFlowNodeServices $services Translations, icon URL, container and settings.
	 * @param LoggerInterface $logger Logger.
	 * @param PayrollGLPostService $glPostService The existing GL handoff service, reused as-is.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function __construct(
		PayrollFlowNodeServices $services,
		LoggerInterface $logger,
		private readonly PayrollGLPostService $glPostService,
	) {
		parent::__construct($services, $logger);

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function getId(): string {
		return 'humaniq.payroll-glpost';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Post payroll run to ledger');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function getDescription(): string {
		return $this->l10n->t('Post the approved payroll run as a balanced journal entry in the bookkeeping app.');
	}//end getDescription()

	/**
	 * Post the run each item names.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run-level metadata.
	 *
	 * @return array The items, each carrying the outcome under `glpost`.
	 *
	 * @throws RuntimeException When the service reports `failed`.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
	 */
	public function execute(FlowNodeCall $call): array {
		$config = $call->config;
		if ($call->items === []) {
			return [];
		}

		return $this->stampEachItem(
			$call,
			function (array $json) use ($config): array {
				$run = $this->requireRun(config: $config, json: $json);

				return $this->requireStatus(
					$this->glPostService->postRun($run),
					new ExpectedStatus(['posted', 'skipped-no-shillinq'], 'Loonjournaalpost voor loonrun ' . $this->idOf($run) . ' is mislukt')
				);
			}
		);
	}//end execute()

}//end class
