<?php

/**
 * OpenRegister register-slug resolution contract — test-only mirror.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * {@see \OCA\Humaniq\Support\RegisterSlugLookup} asks OpenRegister which slug
 * this instance's humaniq register answers to, through the published contract
 * `OCA\OpenRegister\Contract\RegisterSlugResolverInterface`. Like the lifecycle
 * guard contract beside it, that is a SIBLING-APP dependency rather than a
 * composer package, so in the standalone PHPUnit suite (a bare php:8.3-cli
 * container with no Nextcloud and no OpenRegister) the real names are absent.
 *
 * Without them the lookup's `interface_exists()` availability check refuses
 * every call and answers "absent" unconditionally, so the RESOLVED path — the
 * one that decides what every guard and report does — could never be reached
 * by a test. A suite that can only exercise the failure branch reports the same
 * green whether the success branch works or not.
 *
 * UNLIKE the name-only ObjectService marker, this mirrors the REAL API, because
 * the lookup calls it: `resolve()` returns a resolution whose `slug` is read and
 * whose `isResolved()` is branched on. It is a method-for-method copy of
 * openregister@development lib/Contract/RegisterSlugResolverInterface.php and
 * lib/Contract/RegisterSlugResolution.php, INCLUDING the parameter names, which
 * are load-bearing: the lookup calls `resolve(canonical: ..., candidates: ...)`
 * by name, so a renamed parameter here would pass a test the real contract
 * fails.
 *
 * Loaded ONLY from tests/bootstrap.php, behind an interface_exists() check, so
 * the real OpenRegister contract always wins on a live instance. Never in
 * composer.json's autoload map — a stub on an autoloaded path can shadow the
 * real class in production.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

if (class_exists('OCA\OpenRegister\Contract\RegisterSlugResolution') === false) {
	/**
	 * The answer to "which slug does this register answer to here?".
	 */
	class RegisterSlugResolution {

		/**
		 * Exactly one candidate slug exists here.
		 *
		 * @var string
		 */
		public const RESOLVED = 'resolved';

		/**
		 * No candidate slug exists here.
		 *
		 * @var string
		 */
		public const ABSENT = 'absent';

		/**
		 * More than one candidate slug exists here.
		 *
		 * @var string
		 */
		public const AMBIGUOUS = 'ambiguous';

		/**
		 * Constructor.
		 *
		 * @param string $canonical The canonical slug that was asked for.
		 * @param string|null $slug The slug this instance answers to, or null when absent.
		 * @param string $state One of RESOLVED, ABSENT, AMBIGUOUS.
		 * @param list<string> $candidates The candidate slugs that were probed, newest first.
		 * @param list<string> $matched The candidate slugs that exist here, in candidate order.
		 */
		public function __construct(
			public readonly string $canonical,
			public readonly ?string $slug,
			public readonly string $state,
			public readonly array $candidates,
			public readonly array $matched,
		) {

		}//end __construct()

		/**
		 * Whether a slug was found, whether or not it was the only one.
		 *
		 * @return bool True when the slug is usable.
		 */
		public function isResolved(): bool {
			return $this->slug !== null;
		}//end isResolved()

		/**
		 * Whether this register is absent under every known slug.
		 *
		 * @return bool True when nothing matched.
		 */
		public function isAbsent(): bool {
			return $this->state === self::ABSENT;
		}//end isAbsent()

		/**
		 * Whether more than one of the register's slugs exists here.
		 *
		 * @return bool True when the instance carries two rows.
		 */
		public function isAmbiguous(): bool {
			return $this->state === self::AMBIGUOUS;
		}//end isAmbiguous()

	}//end class
}//end if

if (interface_exists('OCA\OpenRegister\Contract\RegisterSlugResolverInterface') === false) {
	/**
	 * Resolves a register by any of the slugs it has answered to.
	 */
	interface RegisterSlugResolverInterface {

		/**
		 * Which slug this instance's copy of a register actually answers to.
		 *
		 * @param string $canonical The canonical (current) register slug.
		 * @param list<string> $candidates Explicit candidates, newest first.
		 *
		 * @return RegisterSlugResolution The slug to use, or an explicit absence.
		 */
		public function resolve(string $canonical, array $candidates=[]): RegisterSlugResolution;

		/**
		 * The slug to read with, or null when the register is not on this instance.
		 *
		 * @param string $canonical The canonical (current) register slug.
		 * @param list<string> $candidates Explicit candidates, newest first.
		 *
		 * @return string|null The slug to use, or null.
		 */
		public function slugOrNull(string $canonical, array $candidates=[]): ?string;

	}//end interface
}//end if
