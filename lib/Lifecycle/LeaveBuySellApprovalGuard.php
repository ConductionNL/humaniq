<?php

/**
 * Humaniq LeaveBuySellApprovalGuard
 *
 * OpenRegister lifecycle guard for the LeaveTransaction `approve` transition
 * (leave-buy-sell). It composes two checks:
 *
 * 1. Separation of duties — delegates to the existing `NoSelfApprovalGuard`
 *    FIRST (reused, not reimplemented — the identical rule already proven on
 *    LeaveRequest/Timesheet/Expense/PerformanceReview). If that denies, its
 *    `GuardResult` is returned unchanged.
 * 2. The statutory-floor sufficiency check (design.md D2), only for
 *    `transactionType: sell`: resolves the `LeaveBalance` matching the
 *    transaction's `(employeeId, year, leaveType)` and denies when no such
 *    balance resolves, or `bovenwettelijkHours < hours`. `buy` needs no
 *    balance check to approve — adding hours cannot go negative.
 *
 * This guard NEVER writes `entitledHours`/`usedHours`/`bovenwettelijkHours` —
 * guards are read-only per OpenRegister's contract, and the ONLY code path
 * that ever writes `bovenwettelijkHours` is `LeaveBuySellSettlementService`.
 * Because `nl-verlof-wettelijk-minimum` is evaluated solely against
 * `entitledHours`/`contractHoursPerWeek`, and neither this guard nor the
 * settlement service ever touches `entitledHours`, a sell can never breach
 * the statutory floor by construction — this guard's sufficiency check is
 * belt-and-braces on top of that structural guarantee, not a substitute
 * for it.
 *
 * Unlike the stateless `NoSelfApprovalGuard`, this guard needs to load the
 * referenced `LeaveBalance` from the register, so it is constructed by the DI
 * container with `ContainerInterface` (lazy `ObjectService` resolution) and
 * `IAppConfig` (the configured register slug) — the `PayrollRunApprovedGuard`
 * cross-object-load shape. No composite-key `find()` exists on
 * `ObjectService`, so the balance is resolved via `loadAll('LeaveBalance')`
 * filtered in-guard, matching `(employeeId, year, leaveType)`.
 *
 * Referenced from the LeaveTransaction schema
 * `x-openregister-lifecycle.transitions.approve.requires`.
 *
 * @category Lifecycle
 * @package  OCA\Humaniq\Lifecycle
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
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-001
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\AppInfo\Application;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Denies self-approval (delegated to NoSelfApprovalGuard) and, for a sell,
 * denies approval when the referenced LeaveBalance's bovenwettelijkHours is
 * insufficient or the balance cannot be resolved.
 *
 * Fails closed: an unresolvable balance on a sell denies rather than allows
 * on a guess.
 */
final class LeaveBuySellApprovalGuard implements LifecycleGuardInterface {

	/**
	 * Max LeaveBalance rows loaded when scanning for a match.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Authorise the `approve` transition: separation of duties first, then
	 * (for a sell) the bovenwettelijk-hours sufficiency check.
	 *
	 * @param array<string, mixed> $object The LeaveTransaction payload at its current state.
	 * @param string $action The transition action ('approve').
	 * @param string $userId The uid of the manager performing the transition.
	 *
	 * @return GuardResult Allow when separation of duties holds AND (buy, or a
	 *                     sell with a sufficient resolvable balance); deny
	 *                     otherwise (fail-closed).
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-001
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-002
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$selfApproval = (new NoSelfApprovalGuard())->check($object, $action, $userId);
		if ($selfApproval->isAllowed() === false) {
			return $selfApproval;
		}

		$transactionType = (string)($object['transactionType'] ?? '');
		if ($transactionType !== 'sell') {
			// Buying hours cannot push a balance negative -- no sufficiency
			// check needed to approve (the settlement step still requires the
			// balance to exist to know what to add to).
			return GuardResult::allow();
		}

		$balance = $this->resolveBalance($object);
		if ($balance === null) {
			return GuardResult::deny(
				'Er is geen verlofsaldo gevonden voor deze medewerker/jaar/verloftype; goedkeuring is geweigerd.'
			);
		}

		$hours = (float)($object['hours'] ?? 0);
		$bovenwettelijkHours = (float)($balance['bovenwettelijkHours'] ?? 0);
		if ($bovenwettelijkHours < $hours) {
			return GuardResult::deny(sprintf(
				'Onvoldoende bovenwettelijke verlofuren (%s beschikbaar, %s aangevraagd om te verkopen); goedkeuring is geweigerd.',
				$bovenwettelijkHours,
				$hours
			));
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Resolve the LeaveBalance matching this transaction's
	 * (employeeId, year, leaveType), or null when none resolves.
	 *
	 * @param array<string, mixed> $object The LeaveTransaction payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveBalance(array $object): ?array {
		$employeeId = (string)($object['employeeId'] ?? '');
		$year = (int)($object['year'] ?? 0);
		$leaveType = (string)($object['leaveType'] ?? '');

		if ($employeeId === '' || $year <= 0 || $leaveType === '') {
			return null;
		}

		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema('LeaveBalance')->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$balance = $this->toArray($row);
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
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. class_exists() rather
		// than SettingsService::isOpenRegisterAvailable(), because this guard
		// does not inject SettingsService and adding a constructor dependency
		// to a lifecycle guard purely to ask a yes/no question is the wrong
		// trade. It answers the same question the container would otherwise
		// have answered fatally.
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured register slug.
	 *
	 * The 'hrmq' fallback is FROZEN across the Humaniq rename: OpenRegister's
	 * ImportHandler resolves the register BY SLUG. Renaming it would create a
	 * second, empty register and orphan every employee, contract, payslip and
	 * payroll run already stored under the 'hrmq' slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'hrmq');
		return $register === '' ? 'hrmq' : $register;
	}//end register()

}//end class
