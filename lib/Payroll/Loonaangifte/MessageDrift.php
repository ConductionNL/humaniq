<?php

/**
 * MessageDrift
 *
 * Whether a filing's wage tax return message still matches the payroll run
 * it was made from; read by rule nl-loonaangifte-message-drift
 * (filings-wage-tax-message D4).
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;


/**
 * The message-versus-run comparison.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
final class MessageDrift {

	/**
	 * Whether a filing's message still matches the run it was made from. A
	 * filing without a message, or an audit that built no payroll context,
	 * passes; a run that is gone, recalculated or changed in wage tax fails.
	 *
	 * @param array<string, mixed> $filing  The LoonaangifteFiling.
	 * @param array<string, mixed> $context The audit context (`payroll.runsById`).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	public static function matchesRun(array $filing, array $context): bool {
		$runId = (string)($filing['messageRunId'] ?? '');
		if (trim((string)($filing['messageXml'] ?? '')) === '' || $runId === '' || isset($context['payroll']['runsById']) === false) {
			return true;
		}

		$run = ($context['payroll']['runsById'][$runId] ?? null);
		if (is_array($run) === false) {
			return false;
		}

		return (string)($run['calculatedAt'] ?? '') === (string)($filing['messageRunCalculatedAt'] ?? '')
			&& abs((float)($run['totalLoonheffing'] ?? 0) - (float)($filing['messageRunTotalLoonheffing'] ?? 0)) < 0.005;
	}//end matchesRun()

}//end class
