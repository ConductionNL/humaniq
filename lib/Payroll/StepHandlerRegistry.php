<?php

/**
 * Step Handler Registry
 *
 * The compile-time allow-list backing the named escape hatch
 * (jurisdiction-packs design.md D9, ADR-101 decision 3).
 *
 * Only handlers constructed INTO this registry from humaniq's own code are
 * resolvable. A pack contributes a name; it can never contribute a class,
 * a path, a callable, or a file — there is no code path here that turns
 * pack-supplied data into an executable artefact.
 *
 * **Ships with zero registered handlers.** `names()` returns `[]`, so every
 * `phpStep` in every uploaded pack is currently rejected at validation. That
 * is the intended state: no NL step needs a handler, and the wall is built
 * before the first country hits it.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use OCA\Humaniq\Payroll\Dsl\DslException;

/**
 * The allow-list of escape-hatch handlers that ship inside humaniq.
 */
final class StepHandlerRegistry {

	/**
	 * The registered handlers, by allow-list name.
	 *
	 * @var array<string, JurisdictionStepHandlerInterface>
	 */
	private array $handlers = [];

	/**
	 * @param array<int, JurisdictionStepHandlerInterface> $handlers The handlers humaniq ships (empty by design).
	 */
	public function __construct(array $handlers = []) {
		foreach ($handlers as $handler) {
			$this->handlers[$handler->name()] = $handler;
		}

	}//end __construct()

	/**
	 * Whether a handler name is on the allow-list.
	 *
	 * @param string $name The pack-declared handler name.
	 *
	 * @return bool
	 */
	public function has(string $name): bool {
		return array_key_exists($name, $this->handlers);
	}//end has()

	/**
	 * Resolve a handler by name.
	 *
	 * @param string $name The pack-declared handler name.
	 *
	 * @return JurisdictionStepHandlerInterface
	 *
	 * @throws DslException When the name is not on the allow-list. This is
	 *                      never reached at runtime for a validated pack —
	 *                      `PackValidator` rejects the upload first.
	 */
	public function get(string $name): JurisdictionStepHandlerInterface {
		if ($this->has($name) === false) {
			throw new DslException('Pack: onbekende phpStep-handler "' . $name . '" — niet op de allow-list (' . $this->describe() . ').');
		}

		return $this->handlers[$name];
	}//end get()

	/**
	 * Every allow-listed handler name.
	 *
	 * @return array<int, string>
	 */
	public function names(): array {
		return array_keys($this->handlers);
	}//end names()

	/**
	 * A human-readable rendering of the allow-list, for rejection messages.
	 *
	 * @return string
	 */
	private function describe(): string {
		if ($this->handlers === []) {
			return 'de allow-list is leeg: humaniq levert geen enkele handler mee';
		}

		return 'toegestaan: ' . implode(', ', $this->names());
	}//end describe()

}//end class
