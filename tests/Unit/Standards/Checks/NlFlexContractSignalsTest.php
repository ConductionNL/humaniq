<?php

/**
 * Unit tests for the chain signal and the on-call fixed-hours offer signal.
 *
 * Both predicates read `new \DateTimeImmutable('today')`, so the fixtures use
 * dates relative to today.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlFlexContractChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for nl-signaal-ketenregeling and nl-signaal-oproep-vaste-uren.
 */
class NlFlexContractSignalsTest extends TestCase {

	/**
	 * A date relative to today.
	 *
	 * @param string $offset A strtotime offset.
	 *
	 * @return string
	 */
	private function day(string $offset): string {
		return date('Y-m-d', strtotime($offset));
	}//end day()

	/**
	 * Evaluate the chain rule for a contract among its siblings.
	 *
	 * @param array<string, mixed>             $contract The contract under test.
	 * @param array<int, array<string, mixed>> $siblings All the employee's contracts, itself included.
	 *
	 * @return bool
	 */
	private function chainSatisfied(array $contract, array $siblings): bool {
		$check = NlFlexContractChecks::checks()['EmploymentContract']['nl-signaal-ketenregeling'];

		return $check($contract, ['signals' => ['contractsByEmployeeId' => ['emp-1' => $siblings]]]);
	}//end chainSatisfied()

	/**
	 * A live third contract whose predecessors each followed within two months is flagged.
	 *
	 * @return void
	 */
	public function testALiveThirdContractIsFlagged(): void {
		$third = ['id' => 'c3', 'employeeId' => 'emp-1', 'type' => 'temporary', 'startDate' => $this->day('-2 months'), 'endDate' => $this->day('+8 months')];
		$siblings = [
			['id' => 'c1', 'type' => 'temporary', 'startDate' => $this->day('-26 months'), 'endDate' => $this->day('-16 months')],
			['id' => 'c2', 'type' => 'temporary', 'startDate' => $this->day('-15 months'), 'endDate' => $this->day('-3 months')],
			$third,
		];

		self::assertFalse($this->chainSatisfied($third, $siblings));
	}//end testALiveThirdContractIsFlagged()

	/**
	 * A first contract, and a contract after a gap of eight months, are not flagged.
	 *
	 * @return void
	 */
	public function testAFirstContractAndARestartedChainAreNotFlagged(): void {
		$current = ['id' => 'c2', 'employeeId' => 'emp-1', 'type' => 'temporary', 'startDate' => $this->day('-1 month'), 'endDate' => $this->day('+11 months')];
		$first = ['id' => 'c1', 'type' => 'temporary', 'startDate' => $this->day('-21 months'), 'endDate' => $this->day('-9 months')];

		self::assertTrue($this->chainSatisfied($current, [$current]));
		self::assertTrue($this->chainSatisfied($current, [$first, $current]));
	}//end testAFirstContractAndARestartedChainAreNotFlagged()

	/**
	 * A second contract whose chain passes 36 months within 60 days is flagged.
	 *
	 * @return void
	 */
	public function testAChainPassingThirtySixMonthsWithinTheWindowIsFlagged(): void {
		$second = ['id' => 'c2', 'employeeId' => 'emp-1', 'type' => 'temporary', 'startDate' => $this->day('-11 months'), 'endDate' => $this->day('+6 months')];
		$first = ['id' => 'c1', 'type' => 'temporary', 'startDate' => $this->day('-35 months'), 'endDate' => $this->day('-11 months -1 day')];

		self::assertFalse($this->chainSatisfied($second, [$first, $second]));
	}//end testAChainPassingThirtySixMonthsWithinTheWindowIsFlagged()

	/**
	 * A third contract that has already ended is not flagged again.
	 *
	 * @return void
	 */
	public function testAnEndedContractIsNotFlagged(): void {
		$third = ['id' => 'c3', 'employeeId' => 'emp-1', 'type' => 'temporary', 'startDate' => $this->day('-8 months'), 'endDate' => $this->day('-1 month')];
		$siblings = [
			['id' => 'c1', 'type' => 'temporary', 'startDate' => $this->day('-30 months'), 'endDate' => $this->day('-20 months')],
			['id' => 'c2', 'type' => 'temporary', 'startDate' => $this->day('-19 months'), 'endDate' => $this->day('-9 months')],
			$third,
		];

		self::assertTrue($this->chainSatisfied($third, $siblings));
	}//end testAnEndedContractIsNotFlagged()

	/**
	 * An on-call contract older than twelve months without an offer is flagged,
	 * and clears once the offer date is recorded.
	 *
	 * @return void
	 */
	public function testAThirteenMonthOnCallContractWithoutAnOfferIsFlagged(): void {
		$check = NlFlexContractChecks::checks()['EmploymentContract']['nl-signaal-oproep-vaste-uren'];
		$contract = ['id' => 'o1', 'employeeId' => 'emp-1', 'type' => 'oproep', 'startDate' => $this->day('-13 months')];

		self::assertFalse($check($contract));

		$contract['vasteUrenAanbodOp'] = $this->day('-1 month');
		$contract['vasteUrenAanbodUren'] = 18;
		self::assertTrue($check($contract));
	}//end testAThirteenMonthOnCallContractWithoutAnOfferIsFlagged()

	/**
	 * A young on-call contract, an ended one and a temporary one are not flagged.
	 *
	 * @return void
	 */
	public function testOtherContractsAreNotFlaggedForTheOffer(): void {
		$check = NlFlexContractChecks::checks()['EmploymentContract']['nl-signaal-oproep-vaste-uren'];

		self::assertTrue($check(['type' => 'oproep', 'startDate' => $this->day('-11 months')]));
		self::assertTrue($check(['type' => 'oproep', 'startDate' => $this->day('-20 months'), 'endDate' => $this->day('-2 months')]));
		self::assertTrue($check(['type' => 'temporary', 'startDate' => $this->day('-20 months')]));
	}//end testOtherContractsAreNotFlaggedForTheOffer()

	/**
	 * Both rules are in the corpus with the parameters the predicates use.
	 *
	 * @return void
	 */
	public function testBothRulesAreInTheCorpusWithTheirParameters(): void {
		$corpus = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Standards/rules/labour.json'), true);
		$rules = [];
		foreach ($corpus['rules'] ?? $corpus as $rule) {
			$rules[$rule['id']] = $rule;
		}

		self::assertSame(['maxContracts' => 3, 'maxMonths' => 36, 'maxGapMonths' => 6, 'windowDays' => 60], $rules['nl-signaal-ketenregeling']['parameters']);
		self::assertSame('hr-signals', $rules['nl-signaal-ketenregeling']['framework']);
		self::assertSame(['months' => 12], $rules['nl-signaal-oproep-vaste-uren']['parameters']);
		self::assertSame('recommended', $rules['nl-signaal-oproep-vaste-uren']['severity']);
	}//end testBothRulesAreInTheCorpusWithTheirParameters()
}//end class
