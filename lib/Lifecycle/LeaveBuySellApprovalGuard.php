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

use OCA\Humaniq\Support\ConvertsRowsToArrays;
use OCA\Humaniq\Support\RegisterSlugLookup;
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

	use ConvertsRowsToArrays;

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

		// An absent register is not "there is no balance". It is this instance
		// being unable to say where balances live, and a sell is denied rather
		// than approved on a guess — fail-CLOSED, like every other branch here,
		// but with a message naming the actual cause. Answering "no balance
		// found" for a register that is not on the instance sends an admin
		// hunting a row that exists.
		$register = $this->registerSlug();
		if ($register === null) {
			return GuardResult::deny(
				'Het humaniq-register is niet gevonden op deze instance, dus het verlofsaldo kan niet worden '
				. 'gecontroleerd; goedkeuring is geweigerd. Voer de humaniq-reparatiestap uit of stel het '
				. 'register in bij de humaniq-instellingen.'
			);
		}

		$balance = $this->resolveBalance($object, $register);
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
	 * @param string $register The slug this instance's humaniq register answers
	 *                         to, already resolved by the caller — so this
	 *                         method cannot be reached with an absent register.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveBalance(array $object, string $register): ?array {
		$employeeId = (string)($object['employeeId'] ?? '');
		$year = (int)($object['year'] ?? 0);
		$leaveType = (string)($object['leaveType'] ?? '');

		if ($employeeId === '' || $year <= 0 || $leaveType === '') {
			return null;
		}

		try {
			$rows = $this->objectService()->setRegister($register)->setSchema('LeaveBalance')->findAll(['limit' => self::LIMIT]);
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
	 * The slug this instance's humaniq register answers to, or null when absent.
	 *
	 * This used to end `return $register === '' ? 'hrmq' : $register;` under a
	 * note saying the `hrmq` fallback was frozen across the rename — while the
	 * `getValueString()` default beside it already said `humaniq`. So the frozen
	 * branch was reachable only for a config value stored as the empty string,
	 * and every ordinary instance took the canonical default instead. On an
	 * instance that has not yet run `MigrateRegisterSlug` the register is still
	 * `hrmq`, the LeaveBalance scan below matched nothing, and a sell was denied
	 * for "no balance found" when the balance was there all along. See
	 * ConductionNL/openregister#3579.
	 *
	 * @return string|null The slug, or null when this instance carries no
	 *                     humaniq register under any of its known slugs.
	 */
	private function registerSlug(): ?string {
		return (new RegisterSlugLookup($this->container, $this->appConfig))->slugOrNull();
	}//end registerSlug()

}//end class
