<?php

/**
 * Unit tests for RegisterSlugLookup.
 *
 * The pin guard beside this file stops a superseded slug being TYPED. This one
 * stops the RESOLVED slug being ignored — the other half of the same defect,
 * and the half no static scan can see.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

use OCA\Humaniq\Support\RegisterSlugLookup;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Tests for RegisterSlugLookup.
 *
 * @spec exclude Infrastructure utility with no feature requirement of its own;
 *  the features that call it carry their own anchors.
 */
class RegisterSlugLookupTest extends TestCase {

	/**
	 * Build a lookup over a pretend instance.
	 *
	 * @param string $configured What app config holds for `register` ('' = unset).
	 * @param list<string>|null $present Slugs the instance carries, or null to
	 *                                   make the container unable to produce a
	 *                                   resolver at all.
	 *
	 * @return RegisterSlugLookup
	 */
	private function lookup(string $configured, ?array $present): RegisterSlugLookup {
		$container = $this->createMock(ContainerInterface::class);
		if ($present === null) {
			$container->method('get')->willThrowException(new RuntimeException('no such service'));
		} else {
			$container->method('get')->willReturn(new FakeSlugResolver($present));
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($configured);

		return new RegisterSlugLookup($container, $appConfig);
	}//end lookup()

	/**
	 * The canonical slug is returned when the instance carries it.
	 *
	 * @return void
	 */
	public function testResolvesTheCanonicalSlugWhenPresent(): void {
		$this->assertSame('humaniq', $this->lookup('', ['humaniq'])->slugOrNull());
	}//end testResolvesTheCanonicalSlugWhenPresent()

	/**
	 * THE CASE THE WHOLE CHANGE EXISTS FOR.
	 *
	 * An instance that has not yet run `MigrateRegisterSlug` still carries the
	 * register under `hrmq`. The old code returned `humaniq` here — the
	 * canonical default — and every read matched nothing. Nothing raised.
	 *
	 * @return void
	 */
	public function testResolvesTheSupersededSlugOnAnUnmigratedInstance(): void {
		$this->assertSame('hrmq', $this->lookup('', ['hrmq'])->slugOrNull());
	}//end testResolvesTheSupersededSlugOnAnUnmigratedInstance()

	/**
	 * An absent register answers null, NOT the canonical slug.
	 *
	 * Returning `humaniq` here is the defect: it hands the caller a slug that
	 * reads zero rows, and zero rows is what a healthy empty register returns.
	 *
	 * @return void
	 */
	public function testAnswersNullWhenTheRegisterIsAbsent(): void {
		$this->assertNull($this->lookup('', [])->slugOrNull());
	}//end testAnswersNullWhenTheRegisterIsAbsent()

	/**
	 * An explicitly configured slug wins and is used as given.
	 *
	 * An admin who has named a register has answered the question, and
	 * `MigrateRegisterSlug` re-points that value when it still says `hrmq`.
	 * Resolving it a second time would only add a way to be wrong.
	 *
	 * @return void
	 */
	public function testAConfiguredSlugWinsWithoutAskingTheResolver(): void {
		// The container throws for every get(), so reaching the resolver at all
		// would surface here rather than passing quietly.
		$this->assertSame('hr-legacy', $this->lookup('hr-legacy', null)->slugOrNull());
	}//end testAConfiguredSlugWinsWithoutAskingTheResolver()

	/**
	 * A configured value is trimmed, and whitespace-only counts as unset.
	 *
	 * @return void
	 */
	public function testAWhitespaceOnlyConfigValueIsTreatedAsUnset(): void {
		$this->assertSame('humaniq', $this->lookup('   ', ['humaniq'])->slugOrNull());
	}//end testAWhitespaceOnlyConfigValueIsTreatedAsUnset()

	/**
	 * An instance that can answer NEITHER way answers null, not a guess.
	 *
	 * The container here produces no resolver and no register mapper, so there
	 * is genuinely nothing left to ask. Inventing a slug is exactly the failure
	 * being removed.
	 *
	 * @return void
	 */
	public function testAnswersNullWhenNoResolverCanBeObtained(): void {
		$this->assertNull($this->lookup('', null)->slugOrNull());
	}//end testAnswersNullWhenNoResolverCanBeObtained()

	/**
	 * Build a lookup over an instance whose OpenRegister predates the contract.
	 *
	 * The container holds ONLY the register mapper, so `resolver()` fails to
	 * produce anything and the table fallback is the path under test.
	 *
	 * @param list<string> $present Slugs that have a register row.
	 *
	 * @return array{0: RegisterSlugLookup, 1: FakeRegisterMapper}
	 */
	private function preContractLookup(array $present): array {
		$mapper = new FakeRegisterMapper($present);
		$container = new FakeContainer(['OCA\OpenRegister\Db\RegisterMapper' => $mapper]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return [new RegisterSlugLookup($container, $appConfig), $mapper];
	}//end preContractLookup()

	/**
	 * A register mapper that throws answers nothing: the lookup reports the
	 * register as unknown rather than letting a database error surface through
	 * a slug lookup that every caller treats as a yes/no question.
	 *
	 * @return void
	 */
	public function testAThrowingRegisterMapperAnswersNull(): void {
		$mapper = new class {

			/**
			 * @param list<string> $slugs The candidate slugs.
			 *
			 * @return array<string, list<string>>
			 *
			 * @throws RuntimeException Always.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the signature mirrors the real mapper.
			 */
			public function findIdsBySlugs(array $slugs): array {
				throw new RuntimeException('register table unavailable');
			}//end findIdsBySlugs()

		};

		$this->assertNull($this->lookupOverMapper($mapper)->slugOrNull());

	}//end testAThrowingRegisterMapperAnswersNull()

	/**
	 * A mapper answering with something that is not a map of slugs is not a
	 * register row either — an OpenRegister whose mapper changed shape reads
	 * as "cannot answer", never as a canonical guess.
	 *
	 * @return void
	 */
	public function testAMapperAnsweringWithANonArrayIsNoRow(): void {
		$mapper = new class {

			/**
			 * @param list<string> $slugs The candidate slugs.
			 *
			 * @return mixed
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the signature mirrors the real mapper.
			 */
			public function findIdsBySlugs(array $slugs): mixed {
				return 'humaniq';
			}//end findIdsBySlugs()

		};

		$this->assertNull($this->lookupOverMapper($mapper)->slugOrNull());

	}//end testAMapperAnsweringWithANonArrayIsNoRow()

	/**
	 * A lookup whose container holds only the given register mapper, so the
	 * table fallback is the path under test.
	 *
	 * @param object $mapper The register mapper double.
	 *
	 * @return RegisterSlugLookup
	 */
	private function lookupOverMapper(object $mapper): RegisterSlugLookup {
		$container = new FakeContainer(['OCA\OpenRegister\Db\RegisterMapper' => $mapper]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new RegisterSlugLookup($container, $appConfig);
	}//end lookupOverMapper()

	/**
	 * THE CASE THIS FALLBACK EXISTS FOR.
	 *
	 * An OpenRegister older than the published contract (anything before
	 * ConductionNL/openregister#3571) cannot be asked through the resolver, and
	 * no fleet app declares an `<app>` dependency that would stop that pairing.
	 * Answering null there reported a register that is demonstrably present as
	 * missing: measured on the dev instance 2026-09-11, where
	 * `MigrateAssetDialect` warned on every upgrade while the register sat in
	 * OpenRegister under `humaniq`.
	 *
	 * @return void
	 */
	public function testReadsTheRegisterTableWhenTheContractIsAbsent(): void {
		[$lookup, $mapper] = $this->preContractLookup(['humaniq']);

		$this->assertSame('humaniq', $lookup->slugOrNull());
		$this->assertSame(['humaniq', 'hrmq'], $mapper->asked);
	}//end testReadsTheRegisterTableWhenTheContractIsAbsent()

	/**
	 * The fallback finds the superseded slug on an unmigrated instance too.
	 *
	 * Falling back must not quietly re-pin the canonical slug; it has to make
	 * the same candidate-ordered choice the contract makes.
	 *
	 * @return void
	 */
	public function testTheRegisterTableFallbackFindsTheSupersededSlug(): void {
		[$lookup] = $this->preContractLookup(['hrmq']);

		$this->assertSame('hrmq', $lookup->slugOrNull());
	}//end testTheRegisterTableFallbackFindsTheSupersededSlug()

	/**
	 * The fallback still answers null when no register row exists.
	 *
	 * This is the property that makes the fallback safe to add: it returns a
	 * slug for a ROW, never a canonical guess. A register that is not there is
	 * still an absence.
	 *
	 * @return void
	 */
	public function testTheRegisterTableFallbackAnswersNullForAnAbsentRegister(): void {
		[$lookup] = $this->preContractLookup([]);

		$this->assertNull($lookup->slugOrNull());
	}//end testTheRegisterTableFallbackAnswersNullForAnAbsentRegister()

	/**
	 * The contract's own ABSENT answer is final; the table is not asked after it.
	 *
	 * Asking twice would turn the resolver's considered "not here" into a
	 * second opinion, and the fallback exists only for an instance that cannot
	 * give the first one.
	 *
	 * @return void
	 */
	public function testTheRegisterTableIsNotConsultedWhenTheResolverAnswers(): void {
		$mapper = new FakeRegisterMapper(['humaniq']);
		$container = new FakeContainer([
			'OCA\OpenRegister\Contract\RegisterSlugResolverInterface' => new FakeSlugResolver([]),
			'OCA\OpenRegister\Db\RegisterMapper' => $mapper,
		]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$this->assertNull((new RegisterSlugLookup($container, $appConfig))->slugOrNull());
		$this->assertSame([], $mapper->asked);
	}//end testTheRegisterTableIsNotConsultedWhenTheResolverAnswers()

	/**
	 * Both slugs present is AMBIGUOUS, and ambiguous still resolves.
	 *
	 * `MigrateRegisterSlug` refuses rather than merges when both rows exist, so
	 * this state is reachable. The contract defines `isResolved()` as
	 * `slug !== null`, so an ambiguous resolution yields the newest candidate
	 * rather than an absence — reading the newer of two rows beats refusing to
	 * read at all, and the repair step is what fixes the underlying duplicate.
	 *
	 * @return void
	 */
	public function testAmbiguityResolvesToTheNewestCandidate(): void {
		$this->assertSame('humaniq', $this->lookup('', ['hrmq', 'humaniq'])->slugOrNull());
	}//end testAmbiguityResolvesToTheNewestCandidate()

	/**
	 * The lookup asks for the candidates it means to ask for.
	 *
	 * A resolver called with the wrong canonical, or with an empty candidate
	 * list, would still answer — with the wrong answer, silently. This asserts
	 * the ARGUMENTS rather than only the result.
	 *
	 * @return void
	 */
	public function testAsksTheResolverForBothKnownSlugs(): void {
		$seen = [];
		$resolver = new class($seen) implements \OCA\OpenRegister\Contract\RegisterSlugResolverInterface {

			/**
			 * @param array<string, mixed> $seen Recorded call, by reference.
			 */
			public function __construct(
				public array &$seen,
			) {

			}//end __construct()

			/**
			 * @param string $canonical The canonical slug.
			 * @param list<string> $candidates The candidates.
			 *
			 * @return \OCA\OpenRegister\Contract\RegisterSlugResolution
			 */
			public function resolve(string $canonical, array $candidates=[]): \OCA\OpenRegister\Contract\RegisterSlugResolution {
				$this->seen = ['canonical' => $canonical, 'candidates' => $candidates];

				return new \OCA\OpenRegister\Contract\RegisterSlugResolution(
					$canonical,
					'humaniq',
					\OCA\OpenRegister\Contract\RegisterSlugResolution::RESOLVED,
					$candidates,
					['humaniq']
				);
			}//end resolve()

			/**
			 * @param string $canonical The canonical slug.
			 * @param list<string> $candidates The candidates.
			 *
			 * @return string|null
			 */
			public function slugOrNull(string $canonical, array $candidates=[]): ?string {
				return $this->resolve($canonical, $candidates)->slug;
			}//end slugOrNull()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($resolver);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		(new RegisterSlugLookup($container, $appConfig))->slugOrNull();

		$this->assertSame('humaniq', $resolver->seen['canonical']);
		$this->assertSame(['humaniq', 'hrmq'], $resolver->seen['candidates']);
	}//end testAsksTheResolverForBothKnownSlugs()

}//end class
