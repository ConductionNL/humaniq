<?php

/**
 * Unit tests for calculating and storing the transition payment on a case.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\TransitionPaymentCalculator;
use OCA\Humaniq\Service\TransitionPaymentService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The case gets the amount and the breakdown, from the shipped rule's cap.
 */
class TransitionPaymentServiceTest extends TestCase {

	/** @var array<int, array{payload: array<string, mixed>, schema: string, uuid: ?string}> */
	private array $saved = [];

	/**
	 * The service over a gateway holding one case.
	 *
	 * @param string $reason The departure reason.
	 *
	 * @return TransitionPaymentService
	 */
	private function service(string $reason): TransitionPaymentService {
		$store = [
			'case-1' => ['employeeId' => 'emp-1', 'lastWorkingDay' => '2025-12-31', 'reason' => $reason, 'status' => 'afronding_gepland', 'notes' => 'keep me', '@self' => ['id' => 'case-1']],
			'emp-1' => ['grossMonthlySalary' => 4000.0, 'startDate' => '2019-01-01'],
		];
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findObjectData')->willReturnCallback(static fn (string $id): ?array => ($store[$id] ?? null));
		$gateway->method('findFiltered')->willReturnCallback(static function (string $schema, array $filters): array {
			if ($filters !== ['employeeId' => 'emp-1']) {
				return [];
			}

			if ($schema === 'EmploymentContract') {
				return [['id' => 'c-1', 'type' => 'permanent', 'startDate' => '2019-01-01', 'endDate' => null]];
			}

			return ($schema === 'Payslip') ? [['period' => '2025-12', 'grossPay' => 4000.0, 'vakantiegeldRate' => 0.08]] : [];
		});
		$gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null): object {
			$this->saved[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid];
			return new \stdClass();
		});

		return new TransitionPaymentService($gateway, new TransitionPaymentCalculator());
	}//end service()

	/**
	 * Seven years at 4,320: 10,080 lands on the case with its breakdown, the
	 * rest of the case goes along, and the payload validates against the
	 * Offboarding schema.
	 *
	 * @return void
	 */
	public function testTheAmountAndBreakdownLandOnTheCase(): void {
		$result = $this->service('opzegging-werkgever')->calculateFor('case-1');

		self::assertSame(10080.00, $result['amountEur']);
		self::assertSame(102000.00, $result['capEur'], 'The cap comes from the shipped nl-offboarding-transitievergoeding rule (2026).');
		self::assertCount(1, $this->saved);
		$payload = $this->saved[0]['payload'];
		self::assertSame(10080.00, $payload['transitievergoedingBedrag']);
		self::assertSame(7, $payload['transitievergoedingBerekening']['serviceYears']);
		self::assertSame('keep me', $payload['notes']);
		self::assertArrayNotHasKey('@self', $payload);
		self::assertSame([], array_values(array_diff(array_keys($payload), array_keys(RegisterSchemaValidator::schema('Offboarding')['properties']))), 'Every field written is an Offboarding property; OpenRegister drops the others.');
		self::assertSame([], RegisterSchemaValidator::errors('Offboarding', array_merge($payload, ['employeeId' => '0127394a-be27-48b4-a592-b6a41774b221'])));
	}//end testTheAmountAndBreakdownLandOnTheCase()

	/**
	 * A settlement agreement answers zero with the reason and writes nothing.
	 *
	 * @return void
	 */
	public function testAReasonOutsideTheListWritesNothing(): void {
		$result = $this->service('vso')->calculateFor('case-1');

		self::assertSame(0.00, $result['amountEur']);
		self::assertSame('The departure was not initiated by the employer, so no transition payment is due.', $result['reason']);
		self::assertSame([], $this->saved);
	}//end testAReasonOutsideTheListWritesNothing()

	/**
	 * An unknown case is null.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseIsNull(): void {
		self::assertNull($this->service('vso')->calculateFor('nope'));
	}//end testAnUnknownCaseIsNull()

}//end class
