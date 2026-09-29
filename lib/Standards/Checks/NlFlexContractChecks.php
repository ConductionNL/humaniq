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
final class NlFlexContractChecks implements CheckProvider {

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
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
	 */
	public static function checks(): array {
		return [
			'EmploymentContract' => [
				'nl-signaal-ketenregeling' => static fn (array $contract, array $context): bool => self::ketenregelingSatisfied($contract, $context),
				'nl-signaal-oproep-vaste-uren' => static fn (array $contract): bool => self::oproepAanbodSatisfied($contract),
			],
		];
	}//end checks()

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

		$chain = (new ContractChainService())->chainFor(contracts: $siblings, contractId: $ownId);
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
}//end class
