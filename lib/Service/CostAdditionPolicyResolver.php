<?php

/**
 * Cost Addition Policy Resolver
 *
 * Turns a set of {@see \OCA\Humaniq\Service\EmployeeCostRateService} cost-addition
 * policies into the additions that apply to ONE contract on ONE date
 * (ADR-081 decision 4).
 *
 * Policies are scoped to a population: the whole organisation, everyone under
 * a given collective labour agreement, or a single contract. The most specific
 * scope carrying a given key wins — `contract` beats `cla` beats
 * `organisation` — so a contract-level figure replaces the CLA-wide one for
 * that key alone and leaves the others standing. That per-key precedence is
 * deliberate and differs from how an employment-TERMS override behaves: terms
 * come from one agreement and are taken whole, while cost additions are
 * independent line items and mixing their sources is the normal case
 * ("everyone under this CLA carries the standard overhead, but this contract
 * has its own equipment figure").
 *
 * Effective dating is applied, not ignored: overheads are re-budgeted, and
 * costing last year's hours at this year's overhead would silently restate
 * history. A policy with no window has always applied.
 *
 * Deliberately NOT part of the CLA corpus. A collective labour agreement does
 * not state what an employer's overhead is, and that corpus holds only figures
 * transcribed from published CLA texts with a source citation. Scoping BY a
 * CLA selects a population; it does not claim the CLA says so.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Selects the cost-addition policies in force for a contract on a date.
 */
class CostAdditionPolicyResolver {

	/**
	 * Scope precedence, least specific first. The index in this list IS the
	 * precedence, so adding a scope means adding it here in the right place
	 * rather than editing a comparison.
	 *
	 * @var string[]
	 */
	private const SCOPE_PRECEDENCE = [
		'organisation',
		'cla',
		'contract',
	];

	/**
	 * Resolve the additions that apply to one contract on one date.
	 *
	 * @param array<int, array<string, mixed>> $policies Every candidate CostAdditionPolicy, as arrays.
	 * @param array<string, mixed> $contract The EmploymentContract as an array.
	 * @param string $contractId The contract's own id, for `contract`-scoped policies.
	 * @param string $onDate ISO date the costing applies to (`YYYY-MM-DD`).
	 *
	 * @return array<int, array{key: string, centsPerHour?: int, percentageOfWage?: float, source: string, basis: string}>
	 *                                                                                                                     Additions in the shape EmployeeCostRateService::resolve() accepts.
	 */
	public function resolveFor(array $policies, array $contract, string $contractId, string $onDate): array {
		$claId = trim((string)($contract['cao'] ?? ''));

		$winners = [];
		foreach ($policies as $policy) {
			if (is_array($policy) === false) {
				continue;
			}

			if ($this->applies(policy: $policy, claId: $claId, contractId: $contractId, onDate: $onDate) === false) {
				continue;
			}

			$key = trim((string)($policy['key'] ?? ''));
			if ($key === '') {
				continue;
			}

			$rank = $this->precedenceOf(scope: (string)($policy['scope'] ?? ''));
			$current = ($winners[$key] ?? null);
			// Strictly greater: an equally-specific later policy does NOT
			// silently displace an earlier one. Two organisation-wide overhead
			// policies in force on the same date is a data problem, and
			// resolving it by array order would hide it behind a plausible
			// number.
			if ($current === null || $rank > $current['rank']) {
				$winners[$key] = ['rank' => $rank, 'policy' => $policy];
			}
		}//end foreach

		$out = [];
		foreach ($winners as $key => $winner) {
			$out[] = $this->toAddition(key: $key, policy: $winner['policy']);
		}

		return $out;
	}//end resolveFor()

	/**
	 * Whether a policy covers this contract on this date.
	 *
	 * @param array<string, mixed> $policy The policy.
	 * @param string $claId The CLA the contract names, or ''.
	 * @param string $contractId The contract id.
	 * @param string $onDate ISO date.
	 *
	 * @return bool
	 */
	private function applies(array $policy, string $claId, string $contractId, string $onDate): bool {
		if ($this->inWindow(policy: $policy, onDate: $onDate) === false) {
			return false;
		}

		switch ((string)($policy['scope'] ?? '')) {
			case 'organisation':
				return true;
			case 'cla':
				// An empty claId on either side must never match: a policy
				// scoped to "no CLA" would otherwise apply to every contract
				// that names none, which is the opposite of scoping.
				$policyCla = trim((string)($policy['claId'] ?? ''));
				return ($policyCla !== '' && $claId !== '' && $policyCla === $claId);
			case 'contract':
				$policyContract = trim((string)($policy['contractId'] ?? ''));
				return ($policyContract !== '' && $contractId !== '' && $policyContract === $contractId);
			default:
				return false;
		}

	}//end applies()

	/**
	 * Whether `onDate` falls inside the policy's effective window. Both bounds
	 * are inclusive; a missing bound is open.
	 *
	 * @param array<string, mixed> $policy The policy.
	 * @param string $onDate ISO date.
	 *
	 * @return bool
	 */
	private function inWindow(array $policy, string $onDate): bool {
		if ($onDate === '') {
			return false;
		}

		$from = substr(trim((string)($policy['effectiveFrom'] ?? '')), 0, 10);
		if ($from !== '' && $onDate < $from) {
			return false;
		}

		$to = substr(trim((string)($policy['effectiveTo'] ?? '')), 0, 10);
		if ($to !== '' && $onDate > $to) {
			return false;
		}

		return true;
	}//end inWindow()

	/**
	 * Precedence rank of a scope; -1 for an unknown scope, which therefore
	 * never wins.
	 *
	 * @param string $scope The scope.
	 *
	 * @return int
	 */
	private function precedenceOf(string $scope): int {
		$rank = array_search($scope, self::SCOPE_PRECEDENCE, true);

		return ($rank === false ? -1 : (int)$rank);
	}//end precedenceOf()

	/**
	 * Project a policy into the addition shape the cost service accepts,
	 * carrying whichever amount form it states.
	 *
	 * @param string $key The cost factor key.
	 * @param array<string, mixed> $policy The winning policy.
	 *
	 * @return array{key: string, centsPerHour?: int, percentageOfWage?: float, source: string, basis: string}
	 */
	private function toAddition(string $key, array $policy): array {
		$addition = [
			'key' => $key,
			'source' => (trim((string)($policy['source'] ?? '')) ?: 'manual'),
			'basis' => (string)($policy['basis'] ?? ''),
		];

		// Pass through exactly ONE form, untouched. The cost service refuses an
		// addition stating both, and forwarding both would turn a policy-level
		// data error into a rate-level exception far from its cause.
		$fixed = ($policy['centsPerHour'] ?? null);
		if ($fixed !== null && $fixed !== '' && is_numeric($fixed) === true) {
			$addition['centsPerHour'] = (int)$fixed;

			return $addition;
		}

		$percentage = ($policy['percentageOfWage'] ?? null);
		if ($percentage !== null && $percentage !== '' && is_numeric($percentage) === true) {
			$addition['percentageOfWage'] = (float)$percentage;
		}

		return $addition;
	}//end toAddition()
}//end class
