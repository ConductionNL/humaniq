<?php

/**
 * Op Registry
 *
 * The DSL's CLOSED step vocabulary (jurisdiction-packs design.md D3): the ops
 * registered here are the complete list a pack may declare, and
 * `PackValidator` rejects anything else by name (REQ-JP-002).
 *
 * Nine declarative ops plus the named escape hatch:
 * `rate`, `cappedRate`, `bracket`, `taper`, `piecewiseAccrue`, `quantize`,
 * `clamp`, `match`, `expr`, `phpStep`.
 *
 * Named ops are kept even where `expr` could subsume them, for intent and
 * auditability: `taper` says "this is a phase-out", the equivalent `expr` says
 * "some arithmetic". Named ops are individually validatable and diffable
 * across countries (design.md D3).
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll\Dsl\Ops
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl\Ops;

use OCA\Hrmq\Payroll\Dsl\DslException;

/**
 * The closed set of step ops, by declared name.
 */
final class OpRegistry {

	/**
	 * The registered ops, by declared name.
	 *
	 * @var array<string, StepOpInterface>
	 */
	private array $ops = [];

	/**
	 * @param array<int, StepOpInterface> $ops The ops forming the vocabulary.
	 */
	public function __construct(array $ops) {
		foreach ($ops as $op) {
			$this->ops[$op->name()] = $op;
		}

	}//end __construct()

	/**
	 * Whether an op name is in the vocabulary.
	 *
	 * @param string $name The declared op name.
	 *
	 * @return bool
	 */
	public function has(string $name): bool {
		return array_key_exists($name, $this->ops);
	}//end has()

	/**
	 * Resolve an op by name.
	 *
	 * @param string $name The declared op name.
	 *
	 * @return StepOpInterface
	 *
	 * @throws DslException When the op is not in the vocabulary.
	 */
	public function get(string $name): StepOpInterface {
		if ($this->has($name) === false) {
			throw new DslException('Pack: onbekende op "' . $name . '" (toegestaan: ' . implode(', ', $this->names()) . ').');
		}

		return $this->ops[$name];
	}//end get()

	/**
	 * Every op name in the vocabulary.
	 *
	 * @return array<int, string>
	 */
	public function names(): array {
		return array_keys($this->ops);
	}//end names()

}//end class
