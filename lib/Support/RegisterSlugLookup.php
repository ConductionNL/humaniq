<?php

/**
 * RegisterSlugLookup — ask this instance which slug the humaniq register answers to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The register was renamed from `hrmq` to `humaniq` by
 * {@see \OCA\Humaniq\Repair\MigrateRegisterSlug}. That step runs PER INSTANCE,
 * so both slugs are live across the estate at once, and neither name is
 * correct on its own.
 *
 * Naming either one as a default is the defect this class exists to remove.
 * OpenRegister does not raise for a register that is not there: the row is
 * absent, so the read matches nothing and returns zero rows — byte for byte
 * what a healthy empty register returns. No exception, no 404, no log line.
 * Three call sites used to end in
 *
 *     $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'humaniq');
 *     return $register === '' ? 'hrmq' : $register;
 *
 * whose comment said the `hrmq` fallback was frozen, while the DEFAULT
 * argument beside it was already the canonical `humaniq`. On an instance that
 * has not run the repair step the register is still `hrmq`, so those readers
 * asked for a register nothing answers to, and reported it as no data.
 * See ConductionNL/openregister#3579.
 *
 * The answer comes from OpenRegister, because OpenRegister owns
 * `openregister_registers` and no consuming app can answer the question
 * without reaching into its storage. `RegisterSlugResolverInterface` is the
 * published probe for exactly that; the candidate list is not derivable, which
 * is why the contract takes one.
 *
 * AN OPENREGISTER TOO OLD FOR THAT CONTRACT IS NOT AN ABSENT REGISTER. The
 * first revision of this class returned null in that case, which put every
 * caller into its absent branch on an instance whose register was sitting
 * right there: the asset dialect repair step warned "register niet gevonden"
 * on every upgrade, and the leave-sell and payroll-run guards denied. When the
 * contract is missing the register table is read directly instead, through
 * `RegisterMapper::findIdsBySlugs()`. Only a register row still produces a
 * slug, so the guarantee this class exists for is intact.
 *
 * A STORED CONFIG VALUE WINS AND IS USED AS GIVEN. An admin who has named a
 * register has answered the question, and `MigrateRegisterSlug` re-points that
 * value when it still says `hrmq`, so a stored value is already this
 * instance's own truth. Resolving it a second time would only add a way to be
 * wrong. The `getValueString()` default is now the EMPTY STRING rather than a
 * slug: a default that is a slug is indistinguishable from an admin who chose
 * that slug, and it is what turned "nothing is configured" into "read the
 * canonical register" without anyone deciding so.
 *
 * NULL IS AN ANSWER, NOT AN ERROR. Callers must branch on it. An authorization
 * check denies; a report says it checked nothing and why. What no caller may
 * do is read with the canonical slug anyway and present the empty result as
 * real emptiness.
 *
 * @category Support
 * @package  OCA\Humaniq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

use OCA\Humaniq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Resolves the humaniq register's slug on this instance, or says it is absent.
 *
 * @spec exclude Infrastructure utility with no feature requirement of its own;
 *  it is exercised through the guards and services that call it.
 */
final class RegisterSlugLookup {

	/**
	 * The canonical (current) register slug.
	 *
	 * @var string
	 */
	public const CANONICAL = 'humaniq';

	/**
	 * Every slug this register has answered to, NEWEST FIRST.
	 *
	 * Passed explicitly rather than left to OpenRegister's own alias table, so
	 * this app keeps working against an OpenRegister whose table predates the
	 * humaniq entry. The two agree; the explicit list is the one that cannot
	 * go missing.
	 *
	 * @var list<string>
	 */
	public const CANDIDATES = ['humaniq', 'hrmq'];

	/**
	 * OpenRegister's published resolver contract.
	 *
	 * Named as a STRING, never imported. OpenRegister is an optional
	 * dependency: a `use` of a class that is not installed is harmless, but a
	 * parameter or return TYPE naming one is not, and this app's guards are
	 * built by the container on instances that have no OpenRegister at all.
	 *
	 * @var string
	 */
	private const RESOLVER = 'OCA\OpenRegister\Contract\RegisterSlugResolverInterface';

	/**
	 * OpenRegister's register mapper, used only when the contract is absent.
	 *
	 * Named as a STRING for the same reason `RESOLVER` is, and reached the same
	 * duck-typed way: `class_exists()`, then `method_exists()` on the one method
	 * this class calls. `findIdsBySlugs()` is a plain read of
	 * `oc_openregister_registers` with no RBAC and no tenancy filter, which is
	 * what a repair step running as nobody needs.
	 *
	 * @var string
	 */
	private const MAPPER = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container, for the lazy resolver lookup.
	 * @param IAppConfig $appConfig App config, for an explicitly configured slug.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The slug to read the humaniq register with here, or null when it is absent.
	 *
	 * @return string|null The slug, or null when this instance carries no
	 *                     humaniq register under any of its known slugs.
	 */
	public function slugOrNull(): ?string {
		$configured = trim($this->appConfig->getValueString(Application::APP_ID, 'register', ''));
		if ($configured !== '') {
			return $configured;
		}

		$resolver = $this->resolver();
		if ($resolver === null) {
			return $this->slugFromRegisterTable();
		}

		// The resolution is deliberately left untyped here. Declaring
		// `RegisterSlugResolution` would autoload an OpenRegister class on an
		// instance that may not have one, which is the failure this whole class
		// is careful about.
		$resolution = $resolver->resolve(canonical: self::CANONICAL, candidates: self::CANDIDATES);

		// Both halves are deliberate. `isResolved()` is the contract's own way
		// of asking and is what a reader should see; the null check is for the
		// analyser, which cannot see through the method call to know that
		// `isResolved()` is DEFINED as `slug !== null`.
		$slug = $resolution->slug;
		if ($resolution->isResolved() === false || is_string($slug) === false || $slug === '') {
			return null;
		}

		return $slug;
	}//end slugOrNull()

	/**
	 * The slug found by reading OpenRegister's register table directly.
	 *
	 * THE FALLBACK FOR AN OPENREGISTER THAT PREDATES THE CONTRACT, AND THE
	 * REASON IT HAD TO EXIST. `RegisterSlugResolverInterface` landed in
	 * OpenRegister #3571. No app in this fleet declares an `<app>` dependency
	 * in `appinfo/info.xml`, so humaniq is routinely installed beside an older
	 * OpenRegister, and on such an instance `resolver()` answers null. Treating
	 * that as "the register is absent" is what this class was built to stop
	 * doing with a slug, and it did it anyway with a null: measured on the dev
	 * instance 2026-09-11, where the register plainly exists under `humaniq`
	 * (register id 35, objects readable over the API) and every reader still
	 * got null. `MigrateAssetDialect` printed
	 * "Could not migrate Asset/AssetAssignment dialect: Het humaniq-register is
	 * niet gevonden op deze instance" on the upgrade, and the two lifecycle
	 * guards fail CLOSED on the same null, so leave-sell approvals and payroll
	 * run approvals were being denied for a register that is there.
	 *
	 * "OpenRegister cannot answer" and "the register is not here" are different
	 * facts. Only the resolver's own ABSENT answer means the second one; a
	 * missing resolver means ask another way. This is that other way, and it
	 * keeps the property that matters: it returns a slug only for a register
	 * row that actually exists, never a canonical guess.
	 *
	 * `RegisterMapper` is OpenRegister's internal storage, not a published
	 * contract, so it is reached defensively and never on the preferred path:
	 * where the contract exists it wins and this method is not called.
	 *
	 * @return string|null The newest candidate slug that exists on this
	 *                     instance, or null when none of them does and when
	 *                     OpenRegister offers no way to ask.
	 */
	private function slugFromRegisterTable(): ?string {
		$mapper = $this->registerMapper();
		if ($mapper === null) {
			return null;
		}

		try {
			$found = $mapper->findIdsBySlugs(self::CANDIDATES);
		} catch (Throwable) {
			return null;
		}

		if (is_array($found) === false) {
			return null;
		}

		// CANDIDATES is newest first and the mapper keys its answer by
		// lower-cased slug, so the first hit in this order is the same choice
		// the contract makes when both rows exist.
		foreach (self::CANDIDATES as $candidate) {
			$ids = ($found[strtolower($candidate)] ?? []);
			if (is_array($ids) === true && $ids !== []) {
				return $candidate;
			}
		}

		return null;
	}//end slugFromRegisterTable()

	/**
	 * OpenRegister's register mapper, or null when it cannot be reached.
	 *
	 * @return object|null The mapper, guaranteed to carry `findIdsBySlugs()`,
	 *                     or null.
	 */
	private function registerMapper(): ?object {
		// ADR-083: establish availability before reaching.
		if (class_exists(self::MAPPER) === false) {
			return null;
		}

		try {
			$mapper = $this->container->get(self::MAPPER);
		} catch (Throwable) {
			return null;
		}

		if (is_object($mapper) === false || method_exists($mapper, 'findIdsBySlugs') === false) {
			return null;
		}

		return $mapper;
	}//end registerMapper()

	/**
	 * OpenRegister's slug resolver, or null when this instance cannot offer one.
	 *
	 * @return object|null The resolver, or null.
	 */
	private function resolver(): ?object {
		// ADR-083: establish availability before reaching. An OpenRegister that
		// predates the contract is not an error to shout about; it is an
		// instance that cannot answer THIS way, and the caller then asks the
		// register table directly via slugFromRegisterTable().
		if (interface_exists(self::RESOLVER) === false) {
			return null;
		}

		try {
			$resolver = $this->container->get(self::RESOLVER);
		} catch (Throwable) {
			return null;
		}

		if (is_object($resolver) === false || method_exists($resolver, 'resolve') === false) {
			return null;
		}

		return $resolver;
	}//end resolver()

}//end class
