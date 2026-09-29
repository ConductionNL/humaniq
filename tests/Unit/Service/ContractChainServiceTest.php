<?php

/**
 * Unit tests for the fixed-term contract chain (BW 7:668a).
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\ContractChainService;
use PHPUnit\Framework\TestCase;

/**
 * The chain model the rule and the contract page share.
 */
class ContractChainServiceTest extends TestCase {

	/**
	 * Build a contract row.
	 *
	 * @param string      $id    The id.
	 * @param string      $start The start date.
	 * @param string|null $end   The end date.
	 * @param string      $type  The contract type.
	 *
	 * @return array<string, string>
	 */
	private function contract(string $id, string $start, ?string $end, string $type='temporary'): array {
		return ['id' => $id, 'type' => $type, 'startDate' => $start, 'endDate' => (string)$end];
	}//end contract()

	/**
	 * Three contracts, each within two months of the last: the third is 3 of 3,
	 * and a renewal makes the chain permanent the day after it ends.
	 *
	 * @return void
	 */
	public function testTheThirdContractOfAChainIsThreeOfThree(): void {
		$contracts = [
			$this->contract('c3', '2026-01-01', '2026-12-31'),
			$this->contract('c1', '2024-03-01', '2024-12-31'),
			$this->contract('c2', '2025-02-01', '2025-12-31'),
		];

		$chain = (new ContractChainService())->chainFor($contracts, 'c3');

		self::assertSame(3, $chain['position']);
		self::assertSame(3, $chain['maxContracts']);
		self::assertSame(['c1', 'c2', 'c3'], $chain['contractIds']);
		self::assertSame('2024-03-01', $chain['startsOn']);
		self::assertSame(34, $chain['monthsCounted']);
		self::assertSame('2027-01-01', $chain['turnsPermanentOn']);
	}//end testTheThirdContractOfAChainIsThreeOfThree()

	/**
	 * A gap of more than six months restarts the chain.
	 *
	 * @return void
	 */
	public function testAGapOfMoreThanSixMonthsRestartsTheChain(): void {
		$contracts = [
			$this->contract('old', '2024-01-01', '2024-12-31'),
			$this->contract('new', '2025-09-01', '2026-08-31'),
		];

		$chain = (new ContractChainService())->chainFor($contracts, 'new');

		self::assertSame(1, $chain['position']);
		self::assertSame(['new'], $chain['contractIds']);
		self::assertSame('2028-09-01', $chain['turnsPermanentOn']);
	}//end testAGapOfMoreThanSixMonthsRestartsTheChain()

	/**
	 * A gap of exactly six months still chains.
	 *
	 * @return void
	 */
	public function testAGapOfExactlySixMonthsStillChains(): void {
		$contracts = [
			$this->contract('a', '2024-01-01', '2024-12-31'),
			$this->contract('b', '2025-07-01', '2025-12-31'),
		];

		self::assertSame(2, (new ContractChainService())->chainFor($contracts, 'b')['position']);

		$contracts[1]['startDate'] = '2025-07-02';
		self::assertSame(1, (new ContractChainService())->chainFor($contracts, 'b')['position']);
	}//end testAGapOfExactlySixMonthsStillChains()

	/**
	 * Two contracts running past 36 months: permanent from the day the chain
	 * passes 36 months, before any fourth contract.
	 *
	 * @return void
	 */
	public function testAChainPassingThirtySixMonthsTurnsPermanentThatDay(): void {
		$contracts = [
			$this->contract('a', '2024-01-01', '2025-12-31'),
			$this->contract('b', '2026-01-01', '2027-06-30'),
		];

		$chain = (new ContractChainService())->chainFor($contracts, 'b');

		self::assertSame(2, $chain['position']);
		self::assertSame(42, $chain['monthsCounted']);
		self::assertSame('2027-01-01', $chain['turnsPermanentOn']);
	}//end testAChainPassingThirtySixMonthsTurnsPermanentThatDay()

	/**
	 * Permanent, agency and BBL contracts and open-ended contracts are no link
	 * in a chain; asking for one answers position 0.
	 *
	 * @return void
	 */
	public function testNonChainContractsAreNoLink(): void {
		$contracts = [
			$this->contract('p', '2023-01-01', null, 'permanent'),
			$this->contract('ag', '2024-01-01', '2024-06-30', 'agency'),
			$this->contract('bbl', '2024-07-01', '2024-12-31', 'bbl'),
			$this->contract('t', '2025-01-01', '2025-12-31'),
			$this->contract('open', '2026-01-01', null, 'oproep'),
		];
		$service = new ContractChainService();

		self::assertSame(0, $service->chainFor($contracts, 'p')['position']);
		self::assertSame(0, $service->chainFor($contracts, 'open')['position']);
		self::assertNull($service->chainFor($contracts, 'p')['turnsPermanentOn']);
		self::assertSame(1, $service->chainFor($contracts, 't')['position']);
		self::assertSame(0, $service->chainFor($contracts, 'missing')['position']);
	}//end testNonChainContractsAreNoLink()

	/**
	 * A fixed-term on-call contract is a link like a temporary one.
	 *
	 * @return void
	 */
	public function testAFixedTermOnCallContractIsALink(): void {
		$contracts = [
			$this->contract('t', '2025-01-01', '2025-06-30'),
			$this->contract('o', '2025-08-01', '2026-07-31', 'oproep'),
		];

		self::assertSame(2, (new ContractChainService())->chainFor($contracts, 'o')['position']);
	}//end testAFixedTermOnCallContractIsALink()
}//end class
