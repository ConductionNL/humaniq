<?php

/**
 * Reference Resolver
 *
 * The DSL's reference grammar (jurisdiction-packs design.md D3):
 * `@input.*`, `@table.*`, `@step.*`, `@binding.*`, `@period.*`, `@pack.*`.
 *
 * A reference may carry a dynamic path segment — `@table.loonheffing.schijven[@binding.schijvenSet]`
 * — whose `[...]` content is itself a reference (or a literal index). That is
 * what turns NL's `schijvenSet()` PHP branch into a data lookup (REQ-JP-004:
 * the table-set selection is selected by data, not by an interpreter branch).
 *
 * **The `:cents` suffix.** `lib/Standards/tables/nl-2026.json` carries NO unit
 * marker on its leaves: `loonheffing.Lv` is `54` (euro) and
 * `zvw.werkgeversheffing` is `6.1` (a percentage) — both bare numbers. A
 * generic `@table.*` reader therefore cannot know which leaves are money.
 * Rather than teach the interpreter which NL leaves are euros (that would put
 * jurisdiction knowledge back into PHP, defeating the whole change), the
 * referencing PACK declares the unit: `@table.loonheffing.Lv:cents` converts
 * via `TaxTables`' own euro-to-cents rule, and a bare ref passes the leaf
 * through unconverted. Unit knowledge lives in config; the conversion itself
 * stays exactly where it is today (design.md D4).
 *
 * Every resolved table leaf's `{source, verified, placeholder}` provenance is
 * recorded onto the context, so a run can stamp it (design.md D11 gate 6).
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Dsl;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Resolves a `@...` reference string against a `StepContext`.
 */
final class RefResolver {

	/**
	 * The closed set of reference namespaces.
	 *
	 * @var array<int, string>
	 */
	public const NAMESPACES = ['input', 'table', 'step', 'binding', 'period', 'pack'];

	/**
	 * The only cast suffix the reference grammar accepts.
	 *
	 * @var string
	 */
	private const CENTS_SUFFIX = ':cents';

	/**
	 * Whether a declared value is a reference rather than a literal.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return bool
	 */
	public function isRef(mixed $value): bool {
		return (is_string($value) === true && str_starts_with($value, '@') === true);
	}//end isRef()

	/**
	 * Resolve a declared parameter: a reference is resolved, anything else is
	 * a literal and passes through.
	 *
	 * @param mixed $value The declared value.
	 * @param StepContext $ctx The run context.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the reference is malformed or unresolvable.
	 */
	public function value(mixed $value, StepContext $ctx): mixed {
		if ($this->isRef($value) === false) {
			return $value;
		}

		return $this->resolve((string)$value, $ctx);
	}//end value()

	/**
	 * Resolve a reference string.
	 *
	 * @param string $ref The reference, e.g. `@table.loonheffing.Lv:cents`.
	 * @param StepContext $ctx The run context.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the reference is malformed or unresolvable.
	 */
	public function resolve(string $ref, StepContext $ctx): mixed {
		$body = substr($ref, 1);
		$cents = false;

		if (str_ends_with($body, self::CENTS_SUFFIX) === true) {
			$cents = true;
			$body = substr($body, 0, (0 - strlen(self::CENTS_SUFFIX)));
		}

		$segments = $this->segments($body, $ref);
		$namespace = array_shift($segments);

		if ($namespace === null || in_array($namespace, self::NAMESPACES, true) === false) {
			throw new DslException('Pack: onbekende verwijzings-namespace in "' . $ref . '" (toegestaan: @' . implode(', @', self::NAMESPACES) . ').');
		}

		if ($cents === true && $namespace !== 'table') {
			throw new DslException('Pack: het ":cents"-achtervoegsel geldt alleen voor @table-verwijzingen, niet voor "' . $ref . '".');
		}

		$path = [];
		foreach ($segments as $segment) {
			$path[] = $this->segmentKey($segment, $ctx);
		}

		return $this->inNamespace($namespace, $path, $cents, $ctx, $ref);
	}//end resolve()

	/**
	 * Dispatch a parsed reference to its namespace.
	 *
	 * @param string $namespace The reference namespace.
	 * @param array<int, string> $path The resolved path segments.
	 * @param bool $cents Whether the `:cents` cast was declared.
	 * @param StepContext $ctx The run context.
	 * @param string $ref The original reference (for errors).
	 *
	 * @return mixed
	 *
	 * @throws DslException When the reference is unresolvable.
	 */
	private function inNamespace(string $namespace, array $path, bool $cents, StepContext $ctx, string $ref): mixed {
		if ($namespace === 'table') {
			return $this->table($path, $cents, $ctx, $ref);
		}

		if (count($path) !== 1) {
			throw new DslException('Pack: verwijzing "' . $ref . '" moet precies één segment na @' . $namespace . ' hebben.');
		}

		return match ($namespace) {
			'input' => $ctx->input($path[0]),
			'step' => $ctx->step($path[0]),
			'binding' => $ctx->binding($path[0]),
			'pack' => $ctx->meta($path[0]),
			default => $this->period($path[0], $ctx, $ref),
		};

	}//end inNamespace()

	/**
	 * Resolve an `@table.*` reference, recording the leaf's provenance.
	 *
	 * @param array<int, string> $path The resolved path segments.
	 * @param bool $cents Whether the `:cents` cast was declared.
	 * @param StepContext $ctx The run context.
	 * @param string $ref The original reference (for errors).
	 *
	 * @return mixed
	 *
	 * @throws DslException When the leaf does not exist.
	 */
	private function table(array $path, bool $cents, StepContext $ctx, string $ref): mixed {
		try {
			$leaf = $ctx->tables()->resolveLeaf($path, $cents);
		} catch (RuntimeException $e) {
			throw new DslException('Pack: onbekende tabelverwijzing "' . $ref . '" (' . $e->getMessage() . ').', 0, $e);
		}

		if ($leaf['provenance'] !== null) {
			$ctx->setProvenance($leaf['provenance']);
		}

		return $leaf['value'];
	}//end table()

	/**
	 * Resolve an `@period.*` reference. The period is a supplied string, never
	 * a clock read (REQ-JP-008).
	 *
	 * @param string $field The period field (`year` or `lastDay`).
	 * @param StepContext $ctx The run context.
	 * @param string $ref The original reference (for errors).
	 *
	 * @return mixed
	 *
	 * @throws DslException When the field is unknown or the period is unparseable.
	 */
	private function period(string $field, StepContext $ctx, string $ref): mixed {
		$period = $ctx->period();

		try {
			$first = new DateTimeImmutable($period . '-01');
		} catch (Throwable $e) {
			throw new DslException('Pack: onleesbare periode "' . $period . '" bij verwijzing "' . $ref . '".', 0, $e);
		}

		return match ($field) {
			'year' => (int)$first->format('Y'),
			'lastDay' => $first->modify('last day of this month')->format('Y-m-d'),
			default => throw new DslException('Pack: onbekend periodeveld "' . $ref . '" (toegestaan: @period.year, @period.lastDay).'),
		};

	}//end period()

	/**
	 * Resolve one path segment to its key: a `[...]` index whose content is a
	 * reference resolves through the context, anything else is a literal key.
	 *
	 * @param string|array<string, string> $segment The parsed segment.
	 * @param StepContext $ctx The run context.
	 *
	 * @return string
	 */
	private function segmentKey(string|array $segment, StepContext $ctx): string {
		if (is_string($segment) === true) {
			return $segment;
		}

		$inner = $segment['index'];
		if ($this->isRef($inner) === false) {
			return $inner;
		}

		return $this->stringify($this->resolve($inner, $ctx));
	}//end segmentKey()

	/**
	 * Canonical string form of a resolved dynamic index.
	 *
	 * @param mixed $value The resolved index value.
	 *
	 * @return string
	 *
	 * @throws DslException When the value cannot address a path segment.
	 */
	private function stringify(mixed $value): string {
		if (is_bool($value) === true) {
			return $value === true ? 'true' : 'false';
		}

		if (is_string($value) === true || is_int($value) === true) {
			return (string)$value;
		}

		throw new DslException('Pack: een dynamische padverwijzing moet een string, int of bool opleveren.');
	}//end stringify()

	/**
	 * Split a reference body into segments, treating `[...]` groups as single
	 * (possibly dynamic) segments.
	 *
	 * @param string $body The reference body (without the leading `@` and any cast).
	 * @param string $ref The original reference (for errors).
	 *
	 * @return array<int, string|array<string, string>>
	 *
	 * @throws DslException When a bracket group is unbalanced.
	 */
	private function segments(string $body, string $ref): array {
		$segments = [];
		$buffer = '';
		$length = strlen($body);
		$index = 0;

		while ($index < $length) {
			$char = $body[$index];

			if ($char === '.') {
				$segments = $this->flush($segments, $buffer);
				$buffer = '';
				$index++;
				continue;
			}

			if ($char === '[') {
				$segments = $this->flush($segments, $buffer);
				$buffer = '';
				$close = strpos($body, ']', $index);
				if ($close === false) {
					throw new DslException('Pack: ongebalanceerde "[" in verwijzing "' . $ref . '".');
				}

				$segments[] = ['index' => substr($body, ($index + 1), ($close - $index - 1))];
				$index = ($close + 1);
				continue;
			}

			$buffer .= $char;
			$index++;
		}//end while

		return $this->flush($segments, $buffer);
	}//end segments()

	/**
	 * Append a non-empty literal buffer as a segment.
	 *
	 * @param array<int, string|array<string, string>> $segments The segments so far.
	 * @param string $buffer The pending literal.
	 *
	 * @return array<int, string|array<string, string>>
	 */
	private function flush(array $segments, string $buffer): array {
		if ($buffer !== '') {
			$segments[] = $buffer;
		}

		return $segments;
	}//end flush()

}//end class
