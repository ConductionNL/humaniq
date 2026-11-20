<?php

/**
 * MonthEndWindow.
 *
 * Which timesheet months a run period pays: those that have ended by its last day.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use InvalidArgumentException;

/**
 * The months a run pays: every month that has ended by the run period's last day.
 *
 * For a monthly run that is every month on or before the run's own. A
 * four-weekly or weekly run pays a month once its last day falls in or
 * before the period (payroll-period-edge-cases D4): comparing the month id
 * with `2026-P05` as text would rank every month of 2026 before it.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PayPeriod::parse is the pure value-object factory every period reader uses.
 *
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
 */
final class MonthEndWindow {

	/**
	 * The run period's last day as `Y-m-d`, null when the id is no period.
	 *
	 * @var string|null
	 */
	private readonly ?string $periodEnd;

	/**
	 * Constructor.
	 *
	 * @param string $period The run's period: YYYY-MM, YYYY-Pnn or YYYY-Wnn.
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 */
	public function __construct(
		private readonly string $period,
	) {
		try {
			$this->periodEnd = PayPeriod::parse($period)->lastDay->format('Y-m-d');
		} catch (InvalidArgumentException $e) {
			$this->periodEnd = null;
		}
	}//end __construct()

	/**
	 * Whether a timesheet month has ended by the period's last day. A month
	 * or period that does not parse falls back to comparing the ids, as the
	 * monthly run always did.
	 *
	 * @param string $month The timesheet's month, YYYY-MM.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 */
	public function admits(string $month): bool {
		if ($this->periodEnd === null || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
			return $month <= $this->period;
		}

		return PayPeriod::parse($month)->lastDay->format('Y-m-d') <= $this->periodEnd;
	}//end admits()
}//end class
