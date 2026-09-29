<?php

/**
 * Humaniq ContractChainService
 *
 * The fixed-term contract chain of BW 7:668a (ketenregeling), shared by the
 * `nl-signaal-ketenregeling` rule and the chain section of a contract
 * (people-flex-contract-rules D1). An employee's fixed-term contracts that
 * follow each other with a gap of at most six months form one chain; a chain
 * turns permanent with its fourth contract, or on the day it passes 36 months.
 *
 * Links are fixed-term contracts (an end date) of type temporary, oproep or
 * minijob. Permanent contracts, open-ended contracts, agency contracts (their
 * own phase system under the uitzendbeding) and BBL contracts (BW 7:668a lid
 * 10) are no link.
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
 *
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use Exception;

/**
 * Composes the chain a contract belongs to.
 *
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
 */
final class ContractChainService {

	/**
	 * Contracts allowed in a chain before the next one is permanent.
	 *
	 * @var int
	 */
	public const MAX_CONTRACTS = 3;

	/**
	 * Months a chain may run before it is permanent.
	 *
	 * @var int
	 */
	public const MAX_MONTHS = 36;

	/**
	 * The longest gap, in months, that still continues a chain.
	 *
	 * @var int
	 */
	public const MAX_GAP_MONTHS = 6;

	/**
	 * The contract types that can be a link.
	 *
	 * @var array<int, string>
	 */
	public const CHAIN_TYPES = ['temporary', 'oproep', 'minijob'];

	/**
	 * The chain the given contract closes, counted up to and including it.
	 *
	 * Position 0 means the contract is no link (not found, not fixed-term, or
	 * a type outside the chain); then the other figures are empty.
	 *
	 * @param array<int, array<string, mixed>> $contracts  The employee's contracts: id, type, startDate, endDate.
	 * @param string                           $contractId The contract to place.
	 *
	 * @return array{position: int, maxContracts: int, contractIds: array<int, string>, startsOn: ?string, monthsCounted: int, turnsPermanentOn: ?string}
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
	 */
	public function chainFor(array $contracts, string $contractId): array {
		$links = $this->links(contracts: $contracts);
		$index = null;
		foreach ($links as $at => $link) {
			if ($link['id'] === $contractId) {
				$index = $at;
			}
		}

		if ($index === null) {
			return ['position' => 0, 'maxContracts' => self::MAX_CONTRACTS, 'contractIds' => [], 'startsOn' => null, 'monthsCounted' => 0, 'turnsPermanentOn' => null];
		}

		$first = $index;
		while ($first > 0 && $this->continues(previous: $links[$first - 1], next: $links[$first]) === true) {
			$first--;
		}

		$chain = array_slice($links, $first, $index - $first + 1);
		$startsOn = $chain[0]['start'];
		$dayAfterEnd = $links[$index]['end']->modify('+1 day');
		$passesMaxMonths = $startsOn->modify('+' . self::MAX_MONTHS . ' months');
		$turnsPermanentOn = $passesMaxMonths;
		if (count($chain) >= self::MAX_CONTRACTS && $dayAfterEnd < $passesMaxMonths) {
			$turnsPermanentOn = $dayAfterEnd;
		}

		$span = $startsOn->diff($dayAfterEnd);

		return [
			'position'         => count($chain),
			'maxContracts'     => self::MAX_CONTRACTS,
			'contractIds'      => array_map(static fn (array $link): string => $link['id'], $chain),
			'startsOn'         => $startsOn->format('Y-m-d'),
			'monthsCounted'    => (($span->y * 12) + $span->m),
			'turnsPermanentOn' => $turnsPermanentOn->format('Y-m-d'),
		];
	}//end chainFor()

	/**
	 * The fixed-term links among the contracts, ordered by start date.
	 *
	 * @param array<int, array<string, mixed>> $contracts The contracts.
	 *
	 * @return array<int, array{id: string, start: DateTimeImmutable, end: DateTimeImmutable}>
	 */
	private function links(array $contracts): array {
		$links = [];
		foreach ($contracts as $contract) {
			if (in_array((string)($contract['type'] ?? ''), self::CHAIN_TYPES, true) === false) {
				continue;
			}

			$start = $this->date(value: (string)($contract['startDate'] ?? ''));
			$end = $this->date(value: (string)($contract['endDate'] ?? ''));
			if ($start === null || $end === null || $end < $start) {
				continue;
			}

			$links[] = ['id' => (string)($contract['id'] ?? ''), 'start' => $start, 'end' => $end];
		}

		usort($links, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

		return $links;
	}//end links()

	/**
	 * Whether the next contract starts within six months after the previous one ended.
	 *
	 * @param array{id: string, start: DateTimeImmutable, end: DateTimeImmutable} $previous The earlier contract.
	 * @param array{id: string, start: DateTimeImmutable, end: DateTimeImmutable} $next     The later contract.
	 *
	 * @return bool
	 */
	private function continues(array $previous, array $next): bool {
		$latestStart = $previous['end']->modify('+1 day')->modify('+' . self::MAX_GAP_MONTHS . ' months');

		return $next['start'] <= $latestStart;
	}//end continues()

	/**
	 * A Y-m-d date, or null.
	 *
	 * @param string $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(string $value): ?DateTimeImmutable {
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable(substr($value, 0, 10));
		} catch (Exception) {
			return null;
		}
	}//end date()
}//end class
