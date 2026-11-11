<?php

/**
 * Unit tests for LeaveBuySellApprovalGuard.
 *
 * Pins the composed contract of the LeaveTransaction `approve` lifecycle
 * guard (leave-buy-sell design.md D2): self-approval is denied via delegation
 * to NoSelfApprovalGuard BEFORE any transaction-specific check runs; a `buy`
 * is never gated by the bovenwettelijk sufficiency check; a `sell` is denied
 * when the matching LeaveBalance is unresolvable or its bovenwettelijkHours
 * is insufficient, and allowed otherwise.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\LeaveBuySellApprovalGuard;
use OCA\Humaniq\Tests\Unit\Support\FakeSlugResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for LeaveBuySellApprovalGuard.
 *
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-001
 */
class LeaveBuySellApprovalGuardTest extends TestCase {

	/**
	 * Build a guard whose lazily-resolved ObjectService::setRegister()->
	 * setSchema()->findAll() returns $balances for any LeaveBalance scan (a
	 * fake collaborator, not a fake of the guard's own decision logic under
	 * test).
	 *
	 * @param array<int, array<string, mixed>> $balances Seeded LeaveBalance rows.
	 *
	 * @return LeaveBuySellApprovalGuard
	 */
	private function guardWithBalances(array $balances): LeaveBuySellApprovalGuard {
		$objectService = new class($balances) {

			/**
			 * @param array<int, array<string, mixed>> $balances Seeded LeaveBalance rows.
			 */
			public function __construct(
				private readonly array $balances,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name (unused by the fake -- only LeaveBalance is ever queried).
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->balances;
			}//end findAll()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('humaniq');

		return new LeaveBuySellApprovalGuard($container, $appConfig);
	}//end guardWithBalances()

	/**
	 * The matching LeaveBalance fixture for the standard transaction
	 * fixture below, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function balance(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'bal-1',
				'employeeId' => 'emp-1',
				'year' => 2026,
				'leaveType' => 'holiday',
				'entitledHours' => 160,
				'bovenwettelijkHours' => 20,
				'usedHours' => 0,
			],
			$overrides
		);

	}//end balance()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function transaction(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'emp-1',
				// The claimant's ACCOUNT. A real LeaveTransaction carries this
				// denormalized uid (it is what /mijn/verlof-kopen-verkopen
				// filters on with @me); the fixture omitted it, which is why
				// this suite passed against a NoSelfApprovalGuard that compared
				// an employee uuid with an account uid and therefore never
				// denied anything.
				'userId' => 'user-emp-1',
				'transactionType' => 'sell',
				'year' => 2026,
				'leaveType' => 'holiday',
				'hours' => 8,
				'status' => 'submitted',
			],
			$overrides
		);

	}//end transaction()

	/**
	 * REQ-BUYSELL-001: self-approval is denied via delegation to
	 * NoSelfApprovalGuard, before any sell-sufficiency check runs.
	 *
	 * @return void
	 */
	public function testSelfApprovalDeniedViaDelegation(): void {
		$guard = $this->guardWithBalances([$this->balance()]);
		// Act as the claimant's ACCOUNT, not as their employee id.
		$result = $guard->check($this->transaction(), 'approve', 'user-emp-1');

		$this->assertFalse($result->isAllowed());

	}//end testSelfApprovalDeniedViaDelegation()

	/**
	 * REQ-BUYSELL-002: a sell within the available bovenwettelijk hours is
	 * approvable by a different (non-self) manager.
	 *
	 * @return void
	 */
	public function testSellWithinSufficientBalanceAllows(): void {
		$guard = $this->guardWithBalances([$this->balance(['bovenwettelijkHours' => 20])]);
		$result = $guard->check($this->transaction(['hours' => 8]), 'approve', 'manager-1');

		$this->assertTrue($result->isAllowed());

	}//end testSellWithinSufficientBalanceAllows()

	/**
	 * REQ-BUYSELL-002: a sell exceeding the available bovenwettelijk hours is
	 * refused.
	 *
	 * @return void
	 */
	public function testSellExceedingBalanceDenies(): void {
		$guard = $this->guardWithBalances([$this->balance(['bovenwettelijkHours' => 20])]);
		$result = $guard->check($this->transaction(['hours' => 30]), 'approve', 'manager-1');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('bovenwettelijke', (string)$result->getMessage());

	}//end testSellExceedingBalanceDenies()

	/**
	 * REQ-BUYSELL-002: a sell against a balance that is exactly sufficient
	 * (bovenwettelijkHours === hours) is approvable.
	 *
	 * @return void
	 */
	public function testSellAtExactlySufficientBalanceAllows(): void {
		$guard = $this->guardWithBalances([$this->balance(['bovenwettelijkHours' => 8])]);
		$result = $guard->check($this->transaction(['hours' => 8]), 'approve', 'manager-1');

		$this->assertTrue($result->isAllowed());

	}//end testSellAtExactlySufficientBalanceAllows()

	/**
	 * REQ-BUYSELL-002: a sell whose (employeeId, year, leaveType) matches no
	 * LeaveBalance is refused -- fail-closed, never approved on a guess.
	 *
	 * @return void
	 */
	public function testSellWithUnresolvableBalanceDenies(): void {
		$guard = $this->guardWithBalances([]);
		$result = $guard->check($this->transaction(), 'approve', 'manager-1');

		$this->assertFalse($result->isAllowed());

	}//end testSellWithUnresolvableBalanceDenies()

	/**
	 * REQ-BUYSELL-002: a `buy` is not gated by bovenwettelijk sufficiency at
	 * all -- it allows even with an unresolvable/insufficient balance (D2:
	 * adding hours cannot go negative; the settlement step still requires
	 * the balance to exist).
	 *
	 * @return void
	 */
	public function testBuyIsNotGatedBySufficiencyCheck(): void {
		$guard = $this->guardWithBalances([]);
		$result = $guard->check($this->transaction(['transactionType' => 'buy', 'hours' => 1000]), 'approve', 'manager-1');

		$this->assertTrue($result->isAllowed());

	}//end testBuyIsNotGatedBySufficiencyCheck()

	/**
	 * A LeaveBalance for a different employee/year/leaveType does not match
	 * -- the guard scans by the full composite key, not just employeeId.
	 *
	 * @return void
	 */
	public function testMismatchedYearOrLeaveTypeIsNotResolved(): void {
		$guard = $this->guardWithBalances([$this->balance(['year' => 2025])]);
		$result = $guard->check($this->transaction(['year' => 2026]), 'approve', 'manager-1');

		$this->assertFalse($result->isAllowed());

	}//end testMismatchedYearOrLeaveTypeIsNotResolved()

	/**
	 * A sell on an instance with no humaniq register DENIES, and names the
	 * register rather than the balance.
	 *
	 * Before the fix this denied too — but with "er is geen verlofsaldo
	 * gevonden", because the guard asked for the canonical `humaniq` register
	 * and an unmigrated instance carries it as `hrmq`, so the LeaveBalance scan
	 * matched nothing. Same verdict, wrong reason: it sends an admin hunting a
	 * row that is there. See ConductionNL/openregister#3579.
	 *
	 * @return void
	 */
	public function testSellDeniesAndNamesTheRegisterWhenItIsAbsent(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakeSlugResolver([]));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$guard = new LeaveBuySellApprovalGuard($container, $appConfig);
		$result = $guard->check($this->transaction(), 'approve', 'manager-1');

		$this->assertFalse($result->isAllowed(), 'An unreadable register must fail CLOSED.');
		$this->assertStringContainsString(
			'humaniq-register is niet gevonden',
			$result->getMessage(),
			'The denial must name the register, not a balance that was never read.'
		);
	}//end testSellDeniesAndNamesTheRegisterWhenItIsAbsent()

	/**
	 * A BUY on an instance with no register is still allowed.
	 *
	 * Buying hours cannot push a balance negative, so this guard returns before
	 * it ever needs a register — and the register check must not be hoisted
	 * above that early return, which would deny a transition that has nothing
	 * to do with the register. Fail-closed is the right default and the wrong
	 * blanket.
	 *
	 * @return void
	 */
	public function testBuyIsUnaffectedByAnAbsentRegister(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakeSlugResolver([]));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$guard = new LeaveBuySellApprovalGuard($container, $appConfig);
		$result = $guard->check($this->transaction(['transactionType' => 'buy']), 'approve', 'manager-1');

		$this->assertTrue($result->isAllowed());
	}//end testBuyIsUnaffectedByAnAbsentRegister()

	/**
	 * Golden: which sells the balance lookup allows and which it denies, and with what message.
	 *
	 * @return void
	 */
	public function testBalanceLookupAllowsAndDeniesExactly(): void {
		$notFound = 'Er is geen verlofsaldo gevonden voor deze medewerker/jaar/verloftype; goedkeuring is geweigerd.';
		$guard = $this->guardWithBalances(
			[
				$this->balance(['employeeId' => 'emp-2']),
				$this->balance(['leaveType' => 'extra', 'bovenwettelijkHours' => 4]),
				$this->balance(['year' => '2026', 'bovenwettelijkHours' => 10]),
				$this->balance(['bovenwettelijkHours' => 99]),
			]
		);
		$cases = [
			'first matching balance wins' => [[], null],
			'string year in the request' => [['year' => '2026', 'hours' => 10], null],
			'over the first match' => [['hours' => 11], 'Onvoldoende bovenwettelijke verlofuren (10 beschikbaar, 11 aangevraagd om te verkopen); goedkeuring is geweigerd.'],
			'other leave type' => [['leaveType' => 'extra', 'hours' => 4], null],
			'no employee: the claimant check refuses first' => [['employeeId' => ''], 'De indiener van deze declaratie/urenstaat is onbekend; goedkeuring is geweigerd.'],
			'no year' => [['year' => 0], $notFound],
			'a negative year' => [['year' => -2026], $notFound],
			'no leave type' => [['leaveType' => null], $notFound],
			'unknown employee' => [['employeeId' => 'emp-9'], $notFound],
		];

		foreach ($cases as $label => [$overrides, $message]) {
			$result = $guard->check($this->transaction($overrides), 'approve', 'manager-1');
			$this->assertSame([$message === null, $message], [$result->isAllowed(), $result->isAllowed() === true ? null : $result->getMessage()], $label);
		}

	}//end testBalanceLookupAllowsAndDeniesExactly()

	/**
	 * Golden: a register read that throws, or returns no list, denies a sell as not found.
	 *
	 * @return void
	 */
	public function testUnreadableBalancesDeny(): void {
		foreach ([new \RuntimeException('down'), 'not-a-list'] as $answer) {
			$objectService = new class($answer) {

				/**
				 * @param mixed $answer What findAll() throws or returns.
				 */
				public function __construct(
					private readonly mixed $answer,
				) {

				}//end __construct()

				/**
				 * @param string $register The register.
				 *
				 * @return self
				 */
				public function setRegister(string $register): self {
					return $this;
				}//end setRegister()

				/**
				 * @param string $schema The schema.
				 *
				 * @return self
				 */
				public function setSchema(string $schema): self {
					return $this;
				}//end setSchema()

				/**
				 * @param array<string, mixed> $options Ignored.
				 *
				 * @return mixed
				 */
				public function findAll(array $options = []): mixed {
					if ($this->answer instanceof \Throwable) {
						throw $this->answer;
					}

					return $this->answer;
				}//end findAll()
			};

			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($objectService);
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn('humaniq');

			$result = (new LeaveBuySellApprovalGuard($container, $appConfig))->check($this->transaction(), 'approve', 'manager-1');

			$this->assertFalse($result->isAllowed());
			$this->assertSame('Er is geen verlofsaldo gevonden voor deze medewerker/jaar/verloftype; goedkeuring is geweigerd.', $result->getMessage());
		}

	}//end testUnreadableBalancesDeny()
}//end class
