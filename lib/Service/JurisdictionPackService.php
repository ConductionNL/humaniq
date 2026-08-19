<?php

/**
 * Jurisdiction Pack Service
 *
 * The OpenRegister-backed home for UPLOADED jurisdiction packs, and the
 * implementation of the pure `PackSourceInterface` seam the resolver depends
 * on (jurisdiction-packs design.md D7).
 *
 * This class is where the cross-app dependency lives, deliberately: everything
 * under `lib/Payroll/` stays free of Nextcloud and OpenRegister imports so the
 * engine remains portable and directly unit-testable. Bundled packs live in
 * code (`lib/Standards/packs/`, the `lib/Standards/tables/` precedent);
 * uploaded packs live as `JurisdictionPack` objects in the hrmq register
 * (ADR-022 — per-tenant config lives in OpenRegister).
 *
 * **Activation is recorded here, never read from the pack.** A pack document
 * is author-supplied, so an author must not be able to promote their own
 * upload over the bundled NL regression contract by setting a field in their
 * own JSON. The `active` and `overridesBundled` flags live on the stored
 * OBJECT, set by this service only after `PackValidator` has passed every gate
 * — including the explicit-override gate.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCA\Hrmq\Payroll\Dsl\DslException;
use OCA\Hrmq\Payroll\JurisdictionPack;
use OCA\Hrmq\Payroll\PackRepository;
use OCA\Hrmq\Payroll\PackSourceInterface;
use OCA\Hrmq\Payroll\PackValidator;
use OCA\Hrmq\Payroll\TaxTables;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Validates, stores and resolves uploaded jurisdiction packs.
 */
class JurisdictionPackService implements PackSourceInterface {

	/**
	 * The `JurisdictionPack` schema slug in the hrmq register.
	 *
	 * @var string
	 */
	public const SCHEMA = 'jurisdiction-pack';

	/**
	 * The BUNDLED-only pack resolver, for the shadowing gate.
	 *
	 * @var PackRepository
	 */
	private readonly PackRepository $bundled;

	/**
	 * The shadowing gate asks one question — "does a BUNDLED pack already own
	 * this key?" — so it needs a bundled-only resolver, constructed here rather
	 * than injected. That is not incidental: the container's `PackRepository`
	 * is wired to THIS service as its uploaded-pack source, so injecting it
	 * would be a dependency cycle, and a resolver that already consults
	 * uploads would answer the wrong question anyway.
	 *
	 * @param ContainerInterface $container The DI container (OpenRegister is resolved lazily at runtime).
	 * @param SettingsService $settingsService The hrmq settings.
	 * @param PackValidator $validator The blocking upload validator.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly PackValidator $validator,
		private readonly LoggerInterface $logger,
	) {
		$this->bundled = new PackRepository();

	}//end __construct()

	/**
	 * Validate and store an uploaded pack. EVERY gate blocks: nothing is
	 * stored until the pack has passed structure, vocabulary, references,
	 * handler resolution, bounds, shadowing AND its own golden vectors
	 * (design.md D11).
	 *
	 * @param array<string, mixed> $document The uploaded pack document.
	 * @param bool $override Whether the admin explicitly activated this as a recorded override of a bundled pack.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @throws DslException When any gate rejects the pack, naming the offending op, ref, handler or bound.
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
	 */
	public function upload(array $document, bool $override = false): array {
		$pack = new JurisdictionPack($document, JurisdictionPack::ORIGIN_UPLOADED);

		$tables = $this->tablesFor($pack);
		$provenance = $this->validator->validate($pack, $tables, $this->bundled, $override);

		// Only now — after every gate has passed — does the pack become an
		// object, and only then is it marked active. Activation is recorded on
		// the OBJECT, never taken from the author-supplied document.
		$object = [
			'packId' => $pack->id(),
			'jurisdiction' => $pack->jurisdiction(),
			'taxYear' => $pack->taxYear(),
			'packVersion' => $pack->packVersion(),
			'dslVersion' => $pack->dslVersion(),
			'tables' => $pack->tablesId(),
			'active' => true,
			'overridesBundled' => $override,
			'provenance' => $this->describeProvenance($provenance),
			'document' => json_encode($document),
		];

		$this->objectService()->saveObject(
			object: $object,
			register: $this->register(),
			schema: self::SCHEMA
		);

		return $object;
	}//end upload()

	/**
	 * {@inheritDoc}
	 *
	 * An uploaded pack surfaces here ONLY when it is active. A pack claiming a
	 * bundled key is stored active only when an admin explicitly recorded the
	 * override, so "bundled wins by default" holds without the resolver having
	 * to re-litigate it.
	 *
	 * @param string $jurisdiction The ISO 3166-1 alpha-2 jurisdiction.
	 * @param int $taxYear The tax year.
	 *
	 * @return JurisdictionPack|null
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
	 */
	public function activePack(string $jurisdiction, int $taxYear): ?JurisdictionPack {
		try {
			$objects = $this->objectService()->findAll(
				[
					'register' => $this->register(),
					'schema' => self::SCHEMA,
					'filters' => [
						'jurisdiction' => strtoupper($jurisdiction),
						'taxYear' => $taxYear,
						'active' => true,
					],
				]
			);
		} catch (Throwable $e) {
			// A pack store that cannot be read must never silently fall through
			// to "no uploaded pack" when an override IS active — that would
			// resolve the bundled pack and pay everyone from the wrong chain.
			$this->logger->error('hrmq: kon geüploade jurisdictiepacks niet lezen: ' . $e->getMessage(), ['exception' => $e]);
			throw new DslException('Pack: kon de geüploade jurisdictiepacks niet lezen — een run mag niet stilzwijgend terugvallen op een ander pack.', 0, $e);
		}

		foreach ($this->rows($objects) as $row) {
			$document = json_decode((string)($row['document'] ?? ''), true);
			if (is_array($document) === true) {
				return new JurisdictionPack($document, JurisdictionPack::ORIGIN_UPLOADED);
			}
		}

		return null;
	}//end activePack()

	/**
	 * The tables corpus a pack's `@table.*` refs resolve against, as DECLARED
	 * by the pack itself.
	 *
	 * @param JurisdictionPack $pack The pack.
	 *
	 * @return TaxTables
	 *
	 * @throws DslException When the declared corpus does not exist.
	 */
	private function tablesFor(JurisdictionPack $pack): TaxTables {
		try {
			return TaxTables::load($pack->tablesId());
		} catch (Throwable $e) {
			throw new DslException('Pack: het gedeclareerde tabellenbestand "' . $pack->tablesId() . '" bestaat niet.', 0, $e);
		}

	}//end tablesFor()

	/**
	 * A human-readable provenance stamp for any unverified/placeholder leaf
	 * the pack resolves (design.md D11 gate 6 — stamped, never blocking).
	 *
	 * @param array<int, array<string, mixed>> $provenance The flagged leaves.
	 *
	 * @return string
	 */
	private function describeProvenance(array $provenance): string {
		if ($provenance === []) {
			return '';
		}

		$parts = [];
		foreach ($provenance as $leaf) {
			$parts[] = (string)$leaf['path'] . ($leaf['placeholder'] === true ? ' (placeholder)' : ' (onbevestigd)');
		}

		return implode('; ', $parts);
	}//end describeProvenance()

	/**
	 * Normalise an ObjectService result to a list of rows.
	 *
	 * @param mixed $objects The ObjectService result.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(mixed $objects): array {
		if (is_array($objects) === false) {
			return [];
		}

		$rows = [];
		foreach ($objects as $object) {
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			if (is_array($object) === true) {
				$rows[] = $object;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * @return mixed The OpenRegister ObjectService (resolved lazily — it only exists at runtime).
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured hrmq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
