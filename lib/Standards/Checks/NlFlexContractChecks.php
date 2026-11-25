<?php

/**
 * NL flexible-contract check provider
 *
 * The two hr-signals rules of people-flex-contract-rules, keyed on
 * `EmploymentContract` (lib/Standards/rules/labour.json, framework
 * `hr-signals`): a fixed-term chain about to turn permanent
 * (nl-signaal-ketenregeling, BW 7:668a) and an on-call contract past twelve
 * months without a fixed-hours offer (nl-signaal-oproep-vaste-uren, BW 7:628a
 * lid 5). The chain predicate reads the same full-list
 * `signals.contractsByEmployeeId` index NlSignalChecks' successor predicate
 * reads, and composes the chain with ContractChainService, the model the
 * contract page reads too. A sibling provider of NlSignalChecks, so neither
 * class grows past the complexity limit.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): both rules are declared as OpenRegister decision
 * tables via the ProvidesTables capability. The chain composition and the
 * date arithmetic stay here as derivations; the shared
 * Dmn\DecisionTableEvaluator decides. The pre-table closures stay as
 * legacyChecks(), the oracle NlFlexContractTableParityTest compares with,
 * never registered in the engine, until staged retirement (REQ-RULE-009).
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;
use OCA\Humaniq\Service\ContractChainService;

/**
 * Fixed-term chain and on-call offer signals.
 *
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
 */
final class NlFlexContractChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * `nl-signaal-ketenregeling` window, days (parameters.windowDays in
	 * labour.json). The other chain parameters live on ContractChainService.
	 *
	 * @var int
	 */
	private const CHAIN_WINDOW_DAYS = 60;

	/**
	 * `nl-signaal-oproep-vaste-uren`: months after its start that an on-call
	 * contract is owed a fixed-hours offer (parameters.months in labour.json).
	 *
	 * @var int
	 */
	private const ONCALL_OFFER_MONTHS = 12;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: both rules this provider owns are table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];
	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * `nl-signaal-ketenregeling`: a contract live today that closes the
	 * chain's last allowed contract, or whose chain turns permanent within the
	 * window, fails. `nl-signaal-oproep-vaste-uren`: a running on-call
	 * contract past its offer date without a recorded offer fails; an on-call
	 * contract without a readable start derives null and passes.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-003
	 */
	public static function tables(): array {
		return [
			'EmploymentContract' => [
				'nl-signaal-ketenregeling' => [
					'derive' => static fn (array $contract, array $context): array => self::chainInputs($contract, $context),
					'table' => self::satisfiedTable(
						[['name' => 'live', 'type' => 'boolean'], ['name' => 'position', 'type' => 'number'], ['name' => 'permanentWithinWindow', 'type' => 'boolean']],
						[
							['id' => 'chain-at-maximum', 'inputEntries' => ['true', '>= ' . ContractChainService::MAX_CONTRACTS, '-'], 'outputEntries' => [false]],
							['id' => 'chain-turns-permanent-soon', 'inputEntries' => ['true', '>= 1', 'true'], 'outputEntries' => [false]],
						]
					),
				],
				'nl-signaal-oproep-vaste-uren' => [
					'derive' => static fn (array $contract): ?array => self::onCallOfferInputs($contract),
					'table' => self::satisfiedTable(
						[['name' => 'onCall', 'type' => 'boolean'], ['name' => 'ended', 'type' => 'boolean'], ['name' => 'offerDue', 'type' => 'boolean'], ['name' => 'offerRecorded', 'type' => 'boolean']],
						[['id' => 'on-call-offer-missing', 'inputEntries' => ['true', 'false', 'true', 'false'], 'outputEntries' => [false]]]
					),
				],
			],
		];
	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec exclude no generated seed: the seeded contracts live in hr-seed.json
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * `nl-signaal-ketenregeling`: true unless the contract is live today and
	 * either closes the third contract of its chain or its chain turns
	 * permanent within CHAIN_WINDOW_DAYS. The chain is composed from the
	 * `signals.contractsByEmployeeId` index by ContractChainService, the model
	 * the contract page reads too.
	 *
	 * @param array<string, mixed> $contract       The EmploymentContract.
	 * @param array<string, mixed> $context Evaluation context; reads `signals.contractsByEmployeeId`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
	 */
	private static function ketenregelingSatisfied(array $contract, array $context): bool {
		$today = (new DateTimeImmutable('today'))->getTimestamp();
		if (self::runsOn($contract, $today) === false) {
			return true;
		}

		$chain = self::chainOf($contract, $context);
		if ($chain['position'] === 0) {
			return true;
		}

		if ($chain['position'] >= ContractChainService::MAX_CONTRACTS) {
			return false;
		}

		$permanentOn = strtotime((string)$chain['turnsPermanentOn']);
		$windowEnd = strtotime('+' . self::CHAIN_WINDOW_DAYS . ' days', $today);

		return $permanentOn === false || $permanentOn < $today || $permanentOn > $windowEnd;
	}//end ketenregelingSatisfied()

	/**
	 * Whether a fixed-term contract runs on the given day.
	 *
	 * @param array<string, mixed> $contract     The EmploymentContract.
	 * @param int                  $today The day, as a timestamp.
	 *
	 * @return bool
	 */
	private static function runsOn(array $contract, int $today): bool {
		$start = strtotime((string)($contract['startDate'] ?? ''));
		$end = strtotime((string)($contract['endDate'] ?? ''));

		return $start !== false && $end !== false && $start <= $today && $end >= $today;
	}//end runsOn()

	/**
	 * `nl-signaal-oproep-vaste-uren`: true unless the contract is an on-call
	 * contract still running, started at least ONCALL_OFFER_MONTHS ago, with
	 * no `vasteUrenAanbodOp` recorded.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-003
	 */
	private static function oproepAanbodSatisfied(array $contract): bool {
		if ((string)($contract['type'] ?? '') !== 'oproep') {
			return true;
		}

		$start = strtotime((string)($contract['startDate'] ?? ''));
		if ($start === false) {
			return true;
		}

		$today = (new DateTimeImmutable('today'))->getTimestamp();
		$end = strtotime((string)($contract['endDate'] ?? ''));
		if ($end !== false && $end < $today) {
			return true;
		}

		if (strtotime('+' . self::ONCALL_OFFER_MONTHS . ' months', $start) > $today) {
			return true;
		}

		return trim((string)($contract['vasteUrenAanbodOp'] ?? '')) !== '';
	}//end oproepAanbodSatisfied()

	/**
	 * The chain table's inputs: whether the contract runs today, its position
	 * in its chain (zero when it does not run today or starts no chain), and
	 * whether the chain turns permanent between today and the window's end.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 * @param array<string, mixed> $context  Evaluation context; reads `signals.contractsByEmployeeId`.
	 *
	 * @return array{live: bool, position: int, permanentWithinWindow: bool}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function chainInputs(array $contract, array $context): array {
		$today = (new DateTimeImmutable('today'))->getTimestamp();
		$inputs = ['live' => self::runsOn($contract, $today), 'position' => 0, 'permanentWithinWindow' => false];
		if ($inputs['live'] === false) {
			return $inputs;
		}

		$chain = self::chainOf($contract, $context);
		$inputs['position'] = (int)$chain['position'];
		$permanentOn = strtotime((string)$chain['turnsPermanentOn']);
		if ($permanentOn !== false) {
			$inputs['permanentWithinWindow'] = ($permanentOn >= $today && $permanentOn <= strtotime('+' . self::CHAIN_WINDOW_DAYS . ' days', $today));
		}

		return $inputs;
	}//end chainInputs()

	/**
	 * The contract's chain, composed from its siblings in the context index
	 * with the contract itself in place of its stored row.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 * @param array<string, mixed> $context  Evaluation context.
	 *
	 * @return array<string, mixed> ContractChainService::chainFor()'s answer.
	 */
	private static function chainOf(array $contract, array $context): array {
		$ownId = (string)($contract['id'] ?? $contract['@self']['id'] ?? '');
		if ($ownId === '') {
			$ownId = '__self__';
		}

		$siblings = array_values(
			array_filter(
				self::contractsByEmployeeId($context)[(string)($contract['employeeId'] ?? '')] ?? [],
				static fn (array $row): bool => (string)($row['id'] ?? '') !== $ownId
			)
		);
		$siblings[] = array_merge($contract, ['id' => $ownId]);

		return (new ContractChainService())->chainFor(contracts: $siblings, contractId: $ownId);
	}//end chainOf()

	/**
	 * The on-call offer table's inputs: whether this is an on-call contract,
	 * whether it has ended, whether its offer date has passed, and whether an
	 * offer is recorded. Null for an on-call contract without a readable start.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return array{onCall: bool, ended: bool, offerDue: bool, offerRecorded: bool}|null
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function onCallOfferInputs(array $contract): ?array {
		$inputs = ['onCall' => ((string)($contract['type'] ?? '') === 'oproep'), 'ended' => false, 'offerDue' => false, 'offerRecorded' => false];
		if ($inputs['onCall'] === false) {
			return $inputs;
		}

		$start = strtotime((string)($contract['startDate'] ?? ''));
		if ($start === false) {
			return null;
		}

		$today = (new DateTimeImmutable('today'))->getTimestamp();
		$end = strtotime((string)($contract['endDate'] ?? ''));
		$inputs['ended'] = ($end !== false && $end < $today);
		$inputs['offerDue'] = (strtotime('+' . self::ONCALL_OFFER_MONTHS . ' months', $start) <= $today);
		$inputs['offerRecorded'] = (trim((string)($contract['vasteUrenAanbodOp'] ?? '')) !== '');

		return $inputs;
	}//end onCallOfferInputs()

	/**
	 * The `signals.contractsByEmployeeId` index from the context, or an empty
	 * array when the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function contractsByEmployeeId(array $context): array {
		$byEmployeeId = ($context['signals']['contractsByEmployeeId'] ?? []);
		return is_array($byEmployeeId) === true ? $byEmployeeId : [];
	}//end contractsByEmployeeId()

	/**
	 * Before the tables, the chain and on-call signals were these closures.
	 * NlFlexContractTableParityTest drives tables() against them; nothing
	 * registers this list with the engine.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
	 */
	public static function legacyChecks(): array {
		return [
			'EmploymentContract' => [
				'nl-signaal-ketenregeling' => static fn (array $contract, array $context): bool => self::ketenregelingSatisfied($contract, $context),
				'nl-signaal-oproep-vaste-uren' => static fn (array $contract): bool => self::oproepAanbodSatisfied($contract),
			],
		];
	}//end legacyChecks()
}//end class
