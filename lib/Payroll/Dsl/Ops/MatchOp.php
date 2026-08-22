<?php

/**
 * Match Op
 *
 * `match(on, cases{}, default?)` — select a value by an input or binding
 * (jurisdiction-packs design.md D3).
 *
 * This op is what makes NL's table-set switches DATA instead of interpreter
 * branches (REQ-JP-004): `schijvenSet()`'s AOW/cohort selection, the
 * AHK/ARK korting column, and the Awf/Aof laag/hoog tariff pick are all a
 * `match` over a binding. The matched case's value may itself be a reference,
 * so a case can select another binding or a table leaf.
 *
 * The `on` value is compared by its canonical string form, so a boolean
 * binding matches the `"true"` / `"false"` case keys JSON can express.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Dsl\Ops
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\StepContext;

/**
 * Select a value by matching a subject against declared cases.
 */
final class MatchOp extends AbstractOp {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'match';
	}//end name()

	/**
	 * Select the case matching `on`, falling back to `default`.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 * @param StepContext $ctx The run context.
	 *
	 * @return mixed
	 *
	 * @throws DslException When no case matches and no default is declared.
	 */
	public function evaluate(array $spec, StepContext $ctx): mixed {
		if (array_key_exists('on', $spec) === false) {
			throw new DslException('Pack: op "match" mist de verplichte parameter "on".');
		}

		$cases = ($spec['cases'] ?? null);
		if (is_array($cases) === false || $cases === []) {
			throw new DslException('Pack: op "match" verwacht een niet-lege "cases"-map.');
		}

		$key = $this->key($this->refs->value($spec['on'], $ctx));

		if (array_key_exists($key, $cases) === true) {
			return $this->refs->value($cases[$key], $ctx);
		}

		if (array_key_exists('default', $spec) === true) {
			return $this->refs->value($spec['default'], $ctx);
		}

		throw new DslException('Pack: op "match" heeft geen case voor "' . $key . '" en geen "default".');
	}//end evaluate()

	/**
	 * The canonical string form of a match subject.
	 *
	 * @param mixed $value The resolved subject.
	 *
	 * @return string
	 *
	 * @throws DslException When the subject cannot be matched.
	 */
	private function key(mixed $value): string {
		if (is_bool($value) === true) {
			return $value === true ? 'true' : 'false';
		}

		if (is_string($value) === true || is_int($value) === true) {
			return (string)$value;
		}

		throw new DslException('Pack: op "match" verwacht een string, int of bool als "on".');
	}//end key()

}//end class
