<?php

/**
 * Pack Validator
 *
 * Every gate an uploaded pack must pass before it can pay anybody
 * (jurisdiction-packs design.md D11, ADR-101 decision 4). All of them BLOCK at
 * upload; none of them is optional, because the payload of that endpoint
 * determines people's wages.
 *
 * | # | gate                                                        | failure |
 * |---|-------------------------------------------------------------|---------|
 * | 1 | Structure — envelope + leaves                                | reject  |
 * | 2 | Vocabulary — every `op` known                                | reject, naming the op |
 * | 3 | References — `@table.*` resolves; `@step.*` names an EARLIER step; DAG, no cycles | reject, naming the ref |
 * | 4 | Handler resolution — every `phpStep` on the allow-list       | reject, naming the handler |
 * | 5 | **Self-test dry-run — REQUIRED, >= 1 vector, run in-process** | reject on any mismatch |
 * | 6 | Provenance — unverified/placeholder leaves stamped, not blocked | activate + stamp |
 * | 8 | Bounds — step count + expression depth caps                  | reject over cap |
 * | 9 | No accidental shadowing of a bundled pack                    | reject unless explicitly overridden |
 *
 * (Gate 7, admin-only upload, is the controller's `AuthorizedAdminSetting`.)
 *
 * **Gate 5 is the keystone, and it pays for itself immediately.** A pack that
 * cannot reproduce its own declared arithmetic never activates — and the NL
 * pack's self-test block IS the 9 existing golden fixtures. So the machinery
 * that gates a third-party Estonian pack is the exact machinery that proves
 * the NL migration was behaviour-identical. One mechanism, two jobs, and the
 * acceptance contract is enforced by production code rather than by a test
 * someone could later "fix".
 *
 * **Forward references cannot exist**, so cycles cannot either: gate 3 walks
 * bindings and steps in declared order and rejects any reference to something
 * not yet declared. A pack is a finite DAG by construction, not by inspection.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll;

use OCA\Hrmq\Payroll\Dsl\DslException;
use OCA\Hrmq\Payroll\Dsl\ExprEvaluator;
use OCA\Hrmq\Payroll\Dsl\PackInterpreter;
use OCA\Hrmq\Payroll\Dsl\PackRunResult;
use OCA\Hrmq\Payroll\Dsl\RefResolver;
use OCA\Hrmq\Payroll\Dsl\Vocabulary;
use RuntimeException;

/**
 * Blocking validation for a jurisdiction pack.
 */
final class PackValidator
{

    /**
     * The maximum number of steps a pack may declare (REQ-JP-008).
     *
     * @var int
     */
    public const MAX_STEPS = 200;

    /**
     * The maximum number of bindings a pack may declare (REQ-JP-008).
     *
     * @var int
     */
    public const MAX_BINDINGS = 200;

    /**
     * The envelope fields every pack must declare.
     *
     * @var array<int, string>
     */
    public const REQUIRED_FIELDS = ['id', 'jurisdiction', 'taxYear', 'packVersion', 'dslVersion', 'tables', 'currency', 'grossRef', 'inputs', 'steps', 'selfTest'];

    /**
     * The interpreter contract this validator implements.
     *
     * @var string
     */
    public const DSL_VERSION = '1.0';

    /**
     * The keys every step/binding may carry regardless of op.
     *
     * @var array<int, string>
     */
    private const COMMON_KEYS = ['id', 'op', 'incidence', 'when', 'round', '_note'];

    /**
     * The per-op allow-list of declared keys. Anything outside it is rejected
     * — which is how a pack carrying a class path, a callable or an inline
     * code string is refused (REQ-JP-005).
     *
     * @var array<string, array<int, string>>
     */
    private const OP_KEYS = [
        'rate'            => ['base', 'rate'],
        'cappedRate'      => ['base', 'rate', 'cap'],
        'bracket'         => ['value', 'table', 'unit', 'mode'],
        'taper'           => ['base', 'value', 'threshold', 'rate', 'floor'],
        'piecewiseAccrue' => ['value', 'segments', 'tail', 'zeroAbove', 'roundTerm'],
        'quantize'        => ['value', 'step', 'mode'],
        'clamp'           => ['value', 'min', 'max'],
        'match'           => ['on', 'cases', 'default'],
        'expr'            => ['expression'],
        'phpStep'         => ['handler', 'params'],
    ];


    /**
     * @param Vocabulary               $vocab       The closed DSL vocabulary.
     * @param PackInterpreter          $interpreter The interpreter used for the self-test dry-run.
     * @param CalculationInputMapper   $mapper      The boundary mapper, shared with the façade.
     * @param StepHandlerRegistry|null $handlers    The escape-hatch allow-list (defaults to the shipped, empty one).
     */
    public function __construct(
        private readonly Vocabulary $vocab=new Vocabulary(),
        private readonly PackInterpreter $interpreter=new PackInterpreter(),
        private readonly CalculationInputMapper $mapper=new CalculationInputMapper(),
        private readonly ?StepHandlerRegistry $handlers=null,
    ) {

    }//end __construct()


    /**
     * Run every blocking gate. Returns the provenance of any unverified or
     * placeholder leaves the pack resolved (gate 6 — stamped, never blocking).
     *
     * @param JurisdictionPack    $pack     The pack to validate.
     * @param TaxTables           $tables   The tables the pack's `@table.*` refs resolve against.
     * @param PackRepository|null $bundled  The bundled packs, for the shadowing gate.
     * @param bool                $override Whether an admin explicitly activated this pack as a recorded override.
     *
     * @return array<int, array<string, mixed>> The unverified/placeholder provenance to stamp on runs.
     *
     * @throws DslException When any gate fails, naming the offending op, ref, handler or bound.
     *
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
     */
    public function validate(JurisdictionPack $pack, TaxTables $tables, ?PackRepository $bundled=null, bool $override=false): array
    {
        $this->structure($pack);
        $this->bounds($pack);
        $this->shadowing($pack, $bundled, $override);
        $this->declarations($pack, $tables);

        return $this->selfTest($pack, $tables);

    }//end validate()


    /**
     * Gate 1 — the envelope and its leaves.
     *
     * @param JurisdictionPack $pack The pack.
     *
     * @return void
     *
     * @throws DslException When a required field is absent or ill-typed.
     */
    private function structure(JurisdictionPack $pack): void
    {
        $raw = $pack->raw();

        foreach (self::REQUIRED_FIELDS as $field) {
            if (array_key_exists($field, $raw) === false) {
                throw new DslException('Pack: verplicht veld "'.$field.'" ontbreekt.');
            }
        }

        $this->identity($pack);

        if ($pack->steps() === []) {
            throw new DslException('Pack: "steps" mag niet leeg zijn.');
        }

        if ($this->vocab->refs()->isRef($pack->grossRef()) === false) {
            throw new DslException('Pack: "grossRef" moet een verwijzing zijn naar de brutobasis waarvan de incidence-fold aftrekt, kreeg "'.$pack->grossRef().'".');
        }

    }//end structure()


    /**
     * The identity leaves: jurisdiction, tax year and the two versions.
     *
     * @param JurisdictionPack $pack The pack.
     *
     * @return void
     *
     * @throws DslException When an identity leaf is ill-formed or unsupported.
     */
    private function identity(JurisdictionPack $pack): void
    {
        if (preg_match('/^[A-Z]{2}$/', $pack->jurisdiction()) !== 1) {
            throw new DslException('Pack: "jurisdiction" moet een ISO 3166-1 alpha-2 code zijn, kreeg "'.$pack->jurisdiction().'".');
        }

        if ($pack->taxYear() < 1900 || $pack->taxYear() > 2999) {
            throw new DslException('Pack: "taxYear" moet een jaartal zijn, kreeg "'.$pack->taxYear().'".');
        }

        if (preg_match('/^\d+\.\d+\.\d+$/', $pack->packVersion()) !== 1) {
            throw new DslException('Pack: "packVersion" moet semver zijn, kreeg "'.$pack->packVersion().'".');
        }

        if ($pack->dslVersion() !== self::DSL_VERSION) {
            throw new DslException('Pack: "dslVersion" '.$pack->dslVersion().' wordt niet ondersteund door deze interpreter ('.self::DSL_VERSION.').');
        }

    }//end identity()


    /**
     * Gate 8 — declared bounds, so a pack cannot exhaust the host.
     *
     * @param JurisdictionPack $pack The pack.
     *
     * @return void
     *
     * @throws DslException When a bound is exceeded.
     */
    private function bounds(JurisdictionPack $pack): void
    {
        if (count($pack->steps()) > self::MAX_STEPS) {
            throw new DslException('Pack: '.count($pack->steps()).' stappen overschrijdt de bovengrens van '.self::MAX_STEPS.'.');
        }

        if (count($pack->bindings()) > self::MAX_BINDINGS) {
            throw new DslException('Pack: '.count($pack->bindings()).' bindings overschrijdt de bovengrens van '.self::MAX_BINDINGS.'.');
        }

    }//end bounds()


    /**
     * Gate 9 — an uploaded pack may not silently claim a `(jurisdiction, taxYear)`
     * a bundled pack already owns. NL is the regression contract; it does not
     * get overwritten by a stray upload.
     *
     * @param JurisdictionPack    $pack     The pack.
     * @param PackRepository|null $bundled  The bundled packs.
     * @param bool                $override Whether an admin explicitly activated this as a recorded override.
     *
     * @return void
     *
     * @throws DslException When the pack would shadow a bundled pack without an explicit override.
     */
    private function shadowing(JurisdictionPack $pack, ?PackRepository $bundled, bool $override): void
    {
        if ($pack->isBundled() === true || $bundled === null || $override === true) {
            return;
        }

        if ($bundled->bundledFor($pack->jurisdiction(), $pack->taxYear()) !== null) {
            throw new DslException(
                'Pack: er is al een meegeleverd pack voor '.$pack->jurisdiction().' '.$pack->taxYear().'. Een upload mag dat niet stilzwijgend overschrijven — een beheerder moet deze override expliciet activeren.'
            );
        }

    }//end shadowing()


    /**
     * Gates 2, 3 and 4 over every binding and step, in declared order.
     *
     * @param JurisdictionPack $pack   The pack.
     * @param TaxTables        $tables The tables.
     *
     * @return void
     *
     * @throws DslException When an op, reference or handler does not resolve.
     */
    private function declarations(JurisdictionPack $pack, TaxTables $tables): void
    {
        $scope = [
            'input'   => array_keys($pack->inputs()),
            'binding' => [],
            'step'    => [],
        ];

        foreach ($pack->bindings() as $binding) {
            $id = $this->declaredId($binding, 'binding');
            if (array_key_exists('incidence', $binding) === true) {
                throw new DslException('Pack: binding "'.$id.'" declareert incidence; alleen stappen doen dat (een binding is geen geld).');
            }

            $using = ($binding['using'] ?? null);
            if (is_array($using) === false) {
                throw new DslException('Pack: binding "'.$id.'" mist het veld "using".');
            }

            $this->spec($using, $scope, $tables, 'binding "'.$id.'"');
            $scope['binding'][] = $id;
        }

        foreach ($pack->steps() as $step) {
            $id = $this->declaredId($step, 'step');
            $this->incidence($step, $id);
            $this->spec($step, $scope, $tables, 'stap "'.$id.'"');
            $scope['step'][] = $id;
        }

        $this->refs([$pack->grossRef()], $scope, $tables, '"grossRef"');

    }//end declarations()


    /**
     * A declaration's id.
     *
     * @param array<string, mixed> $node The declaration.
     * @param string               $kind The declaration kind.
     *
     * @return string
     *
     * @throws DslException When the id is absent or malformed.
     */
    private function declaredId(array $node, string $kind): string
    {
        $id = (string) ($node['id'] ?? '');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $id) !== 1) {
            throw new DslException('Pack: elke '.$kind.' heeft een alfanumerieke "id" nodig, kreeg "'.$id.'".');
        }

        return $id;

    }//end declaredId()


    /**
     * Every step declares exactly one incidence from the closed vocabulary
     * (REQ-JP-003), and never a net step — net is the interpreter's fold.
     *
     * @param array<string, mixed> $step The step.
     * @param string               $id   The step id.
     *
     * @return void
     *
     * @throws DslException When the incidence is absent or unknown.
     */
    private function incidence(array $step, string $id): void
    {
        $incidence = (string) ($step['incidence'] ?? '');

        if (in_array($incidence, PackInterpreter::INCIDENCES, true) === false) {
            throw new DslException(
                'Pack: stap "'.$id.'" declareert incidence "'.$incidence.'"; toegestaan: '.implode(', ', PackInterpreter::INCIDENCES).'.'
            );
        }

    }//end incidence()


    /**
     * Gate 2 + 4 for one spec, plus its references (gate 3).
     *
     * @param array<string, mixed>            $spec  The declared spec.
     * @param array<string, array<int, string>> $scope The ids declared so far.
     * @param TaxTables                       $tables The tables.
     * @param string                          $where  A label for error messages.
     *
     * @return void
     *
     * @throws DslException When the op, its keys, its handler or its refs are invalid.
     */
    private function spec(array $spec, array $scope, TaxTables $tables, string $where): void
    {
        $op = (string) ($spec['op'] ?? '');

        if ($op === 'derive') {
            throw new DslException('Pack: '.$where.' gebruikt "derive" als op; "derive" hoort op de binding zelf, de berekening staat in "using".');
        }

        $isPredicate = in_array($op, $this->vocab->predicates()->vocabulary(), true);

        if ($this->vocab->ops()->has($op) === false && $isPredicate === false) {
            throw new DslException(
                'Pack: '.$where.' declareert de onbekende op "'.$op.'" (stap-ops: '.implode(', ', $this->vocab->ops()->names()).'; predicaten: '.implode(', ', $this->vocab->predicates()->vocabulary()).').'
            );
        }

        if ($isPredicate === false) {
            $this->keys($spec, $op, $where);
        }

        if ($op === 'phpStep') {
            $this->handler($spec, $where);
        }

        $this->refs($this->collect($spec), $scope, $tables, $where);

    }//end spec()


    /**
     * Reject any key outside the op's allow-list — this is what refuses a step
     * carrying a class path, a callable or an inline code string (REQ-JP-005).
     *
     * @param array<string, mixed> $spec  The declared spec.
     * @param string               $op    The op name.
     * @param string               $where A label for error messages.
     *
     * @return void
     *
     * @throws DslException When an unknown key is present.
     */
    private function keys(array $spec, string $op, string $where): void
    {
        $allowed = array_merge(self::COMMON_KEYS, (self::OP_KEYS[$op] ?? []));

        foreach (array_keys($spec) as $key) {
            if (in_array((string) $key, $allowed, true) === false) {
                throw new DslException(
                    'Pack: '.$where.' declareert het onbekende veld "'.$key.'" voor op "'.$op.'". Een pack levert uitsluitend data — nooit code, een class-pad of een callable.'
                );
            }
        }

    }//end keys()


    /**
     * Gate 4 — resolve the escape-hatch handler name against the compile-time
     * allow-list, at VALIDATION time. An unknown handler rejects the upload,
     * naming it; it never reaches a run to be silently skipped.
     *
     * @param array<string, mixed> $spec  The declared spec.
     * @param string               $where A label for error messages.
     *
     * @return void
     *
     * @throws DslException When the handler is absent or not on the allow-list.
     */
    private function handler(array $spec, string $where): void
    {
        $name = ($spec['handler'] ?? null);
        if (is_string($name) === false || trim($name) === '') {
            throw new DslException('Pack: '.$where.' gebruikt op "phpStep" maar declareert geen "handler"-naam.');
        }

        $registry = ($this->handlers ?? new StepHandlerRegistry());
        if ($registry->has($name) === false) {
            throw new DslException(
                'Pack: '.$where.' verwijst naar de onbekende phpStep-handler "'.$name.'". Handlers worden op validatietijd tegen een compile-time allow-list geresolved; een pack kan nooit zelf code leveren.'
            );
        }

    }//end handler()


    /**
     * Gate 3 — every reference resolves, and names only things declared
     * EARLIER (so the graph is acyclic by construction).
     *
     * @param array<int, string>              $refs   The references to check.
     * @param array<string, array<int, string>> $scope The ids declared so far.
     * @param TaxTables                       $tables The tables.
     * @param string                          $where  A label for error messages.
     *
     * @return void
     *
     * @throws DslException When a reference does not resolve.
     */
    private function refs(array $refs, array $scope, TaxTables $tables, string $where): void
    {
        foreach ($refs as $ref) {
            foreach ($this->inner($ref) as $nested) {
                $this->ref($nested, $scope, $tables, $where);
            }

            $this->ref($ref, $scope, $tables, $where);
        }

    }//end refs()


    /**
     * Check one reference.
     *
     * @param string                          $ref    The reference.
     * @param array<string, array<int, string>> $scope The ids declared so far.
     * @param TaxTables                       $tables The tables.
     * @param string                          $where  A label for error messages.
     *
     * @return void
     *
     * @throws DslException When the reference does not resolve.
     */
    private function ref(string $ref, array $scope, TaxTables $tables, string $where): void
    {
        $body      = ltrim(substr($ref, 1));
        $namespace = strtok($body, '.[');

        if (in_array((string) $namespace, RefResolver::NAMESPACES, true) === false) {
            throw new DslException('Pack: '.$where.' gebruikt de onbekende verwijzing "'.$ref.'" (toegestaan: @'.implode(', @', RefResolver::NAMESPACES).').');
        }

        if ($namespace === 'table') {
            $this->tableRef($ref, $tables, $where);
            return;
        }

        if (in_array($namespace, ['period', 'pack'], true) === true) {
            return;
        }

        $name = (string) strtok('.[');
        if (in_array($name, ($scope[$namespace] ?? []), true) === false) {
            throw new DslException(
                'Pack: '.$where.' verwijst naar "'.$ref.'", die niet eerder is gedeclareerd. Een stap mag alleen naar eerdere stappen/bindings verwijzen (geen forward refs, dus geen cycles).'
            );
        }

    }//end ref()


    /**
     * Resolve a STATIC `@table.*` reference against the corpus. A reference
     * carrying a dynamic `[...]` segment cannot be resolved without a run —
     * gate 5's self-test dry-run covers those, since it executes every branch
     * the pack's own vectors reach.
     *
     * @param string    $ref    The reference.
     * @param TaxTables $tables The tables.
     * @param string    $where  A label for error messages.
     *
     * @return void
     *
     * @throws DslException When the leaf does not exist.
     */
    private function tableRef(string $ref, TaxTables $tables, string $where): void
    {
        if (str_contains($ref, '[') === true) {
            return;
        }

        $path  = substr($ref, strlen('@table.'));
        $cents = str_ends_with($path, ':cents');
        if ($cents === true) {
            $path = substr($path, 0, (0 - strlen(':cents')));
        }

        try {
            $tables->resolveLeaf(explode('.', $path), $cents);
        } catch (RuntimeException $e) {
            throw new DslException('Pack: '.$where.' verwijst naar de onbekende tabelwaarde "'.$ref.'" ('.$e->getMessage().').', 0, $e);
        }

    }//end tableRef()


    /**
     * The references nested inside a reference's `[...]` index groups.
     *
     * @param string $ref The reference.
     *
     * @return array<int, string>
     */
    private function inner(string $ref): array
    {
        $matches = [];
        preg_match_all('/\[(@[^\]]+)\]/', $ref, $matches);

        return $matches[1];

    }//end inner()


    /**
     * Collect every reference a spec uses, parsing `expr` expressions through
     * the closed grammar on the way (which enforces gate 2's vocabulary and
     * gate 8's depth bound without executing anything).
     *
     * @param mixed $node The spec node.
     *
     * @return array<int, string>
     *
     * @throws DslException When an expression is malformed or too deep.
     */
    private function collect(mixed $node): array
    {
        if (is_string($node) === true) {
            return ($this->vocab->refs()->isRef($node) === true) ? [$node] : [];
        }

        if (is_array($node) === false) {
            return [];
        }

        $refs = [];

        if (($node['op'] ?? null) === 'expr' && is_string(($node['expression'] ?? null)) === true) {
            $expr = $this->vocab->expr();
            $refs = $expr->refsOf($expr->parse($node['expression']));
        }

        foreach ($node as $key => $child) {
            if ($key === '_note' || $key === 'expression') {
                continue;
            }

            $refs = array_merge($refs, $this->collect($child));
        }

        return $refs;

    }//end collect()


    /**
     * Gate 5 — the keystone. Execute every declared golden vector IN-PROCESS
     * through the interpreter and reject on any mismatch, reporting the
     * component, the expected value and the computed value.
     *
     * @param JurisdictionPack $pack   The pack.
     * @param TaxTables        $tables The tables.
     *
     * @return array<int, array<string, mixed>> The unverified/placeholder provenance to stamp.
     *
     * @throws DslException When the block is absent/empty or any vector mismatches.
     *
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
     */
    private function selfTest(JurisdictionPack $pack, TaxTables $tables): array
    {
        $block   = $pack->selfTest();
        $vectors = ($block['vectors'] ?? null);

        if (is_array($vectors) === false || $vectors === []) {
            throw new DslException('Pack: "selfTest" moet ten minste één golden vector bevatten — een pack dat zijn eigen rekenwerk niet kan reproduceren activeert nooit.');
        }

        $provenance = [];
        foreach ($vectors as $index => $vector) {
            $result     = $this->vector($pack, $tables, (array) $vector, (int) $index, (array) ($block['fixtureMap'] ?? []));
            $provenance = array_merge($provenance, $result->unverifiedProvenance());
        }

        return array_values($provenance);

    }//end selfTest()


    /**
     * Run one golden vector.
     *
     * @param JurisdictionPack     $pack   The pack.
     * @param TaxTables            $tables The tables.
     * @param array<string, mixed> $vector The declared vector.
     * @param int                  $index  The vector's position (for errors).
     * @param array<string, string> $map   The fixture component map.
     *
     * @return PackRunResult
     *
     * @throws DslException When the vector is malformed or mismatches.
     */
    private function vector(JurisdictionPack $pack, TaxTables $tables, array $vector, int $index, array $map): PackRunResult
    {
        if (array_key_exists('$fixture', $vector) === true) {
            return $this->fixtureVector($pack, $tables, (string) $vector['$fixture'], $map);
        }

        $period = (string) ($vector['period'] ?? '');
        if ($period === '') {
            throw new DslException('Pack: golden vector #'.$index.' mist "period".');
        }

        $result = $this->interpreter->run((array) ($vector['input'] ?? []), $pack, $tables, $period);

        foreach ((array) ($vector['expected'] ?? []) as $ref => $expected) {
            $this->assert($result, (string) $ref, (int) $expected, 'golden vector #'.$index);
        }

        return $result;

    }//end vector()


    /**
     * Run a `$fixture` golden vector — a vector expressed in hrmq's OWN
     * `CalculationInput`/`CalculationResult` fixture vocabulary (euros, and
     * `awfTariff: low|high`).
     *
     * This form is BUNDLED-ONLY, for two reasons. It reads a file from the
     * repository, which an uploaded pack must never be able to steer; and an
     * uploaded pack must be self-contained (design.md D7) so its recipient can
     * prove it before paying anyone. Uploads carry inline vectors instead.
     *
     * The fixture's input is mapped through the SAME boundary mapper the
     * façade uses, so the vector proves the whole path a real wage takes —
     * `CalculationInput` -> pack inputs -> interpreter -> components — and the
     * two can never drift.
     *
     * @param JurisdictionPack      $pack   The pack.
     * @param TaxTables             $tables The tables.
     * @param string                $name   The fixture path, e.g. `payroll-2026/anchor.json`.
     * @param array<string, string> $map    The fixture component map.
     *
     * @return PackRunResult
     *
     * @throws DslException When the fixture is unusable or the vector mismatches.
     */
    private function fixtureVector(JurisdictionPack $pack, TaxTables $tables, string $name, array $map): PackRunResult
    {
        if ($pack->isBundled() === false) {
            throw new DslException('Pack: alleen een meegeleverd pack mag naar een fixture verwijzen ("'.$name.'"); een geüpload pack moet zijn golden vectors zelf meedragen.');
        }

        if ($map === []) {
            throw new DslException('Pack: een "$fixture"-vector vereist een "selfTest.fixtureMap" die de fixture-componenten op verwijzingen afbeeldt.');
        }

        $fixture = $this->fixture($name);
        $input   = $this->inputFrom((array) $fixture['input'], $pack);
        $result  = $this->interpreter->run($this->mapper->toPackInputs($input), $pack, $tables, $input->period);

        foreach ((array) $fixture['expected'] as $component => $euros) {
            $ref = ($map[$component] ?? null);
            if ($ref === null) {
                throw new DslException('Pack: "selfTest.fixtureMap" beeldt de component "'.$component.'" niet af (fixture '.$name.').');
            }

            $this->assert($result, $ref, (int) round(((float) $euros * 100)), $name);
        }

        return $result;

    }//end fixtureVector()


    /**
     * Load and shape-check a fixture, refusing any path that could escape the
     * fixtures directory.
     *
     * @param string $name The fixture path.
     *
     * @return array<string, mixed>
     *
     * @throws DslException When the path is unsafe or the fixture is unusable.
     */
    private function fixture(string $name): array
    {
        if (preg_match('#^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*\.json$#', $name) !== 1) {
            throw new DslException('Pack: ongeldige fixtureverwijzing "'.$name.'".');
        }

        $base = realpath(__DIR__.'/../../tests/fixtures');
        $path = realpath($base.'/'.$name);

        if ($base === false || $path === false || str_starts_with($path, $base.'/') === false) {
            throw new DslException('Pack: fixture "'.$name.'" niet gevonden.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded) === false || isset($decoded['input'], $decoded['expected']) === false) {
            throw new DslException('Pack: fixture "'.$name.'" mist een "input"- of "expected"-blok.');
        }

        return $decoded;

    }//end fixture()


    /**
     * Build a `CalculationInput` from a fixture's `input` block.
     *
     * @param array<string, mixed> $input The fixture's input block.
     * @param JurisdictionPack     $pack  The pack (its declared jurisdiction).
     *
     * @return CalculationInput
     *
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
     * @spec openspec/changes/30-procent-regeling/specs/30-procent-regeling/spec.md#REQ-30P-003
     */
    private function inputFrom(array $input, JurisdictionPack $pack): CalculationInput
    {
        return new CalculationInput(
            grossMonthlySalaryCents: (int) round(((float) $input['grossMonthly'] * 100)),
            taxTableColor: (string) $input['taxTableColor'],
            loonheffingskortingToegepast: (bool) $input['loonheffingskortingToegepast'],
            dateOfBirth: (string) $input['dateOfBirth'],
            period: (string) $input['period'],
            awfTariff: (string) $input['awfTariff'],
            aofTariff: (string) $input['aofTariff'],
            whkPercentage: (float) $input['whkPercentage'],
            verzekeringsplichtig: (bool) ($input['verzekeringsplichtig'] ?? true),
            jurisdiction: $pack->jurisdiction(),
            thirtyPercentRulingRate: (float) ($input['thirtyPercentRulingRate'] ?? 0.0)
        );

    }//end inputFrom()


    /**
     * Assert one expected component, reporting the expected and computed
     * values on mismatch so a rejected pack is diagnosable by its author.
     *
     * @param PackRunResult $result   The run's output.
     * @param string        $ref      The result reference.
     * @param int           $expected The expected value, in cents.
     * @param string        $label    A label for error messages.
     *
     * @return void
     *
     * @throws DslException When the values differ.
     */
    private function assert(PackRunResult $result, string $ref, int $expected, string $label): void
    {
        $actual = $this->resultRef($result, $ref);

        if ($actual !== $expected) {
            throw new DslException(
                'Pack: selfTest '.$label.' faalt op "'.$ref.'": verwacht '.$expected.' cent, berekend '.$actual.' cent. Een pack dat zijn eigen golden vectors niet reproduceert wordt geweigerd.'
            );
        }

    }//end assert()


    /**
     * Resolve a result-level reference: the DERIVED folds `@net`,
     * `@employerCharges` and `@gross`, or a step/binding by id.
     *
     * @param PackRunResult $result The run's output.
     * @param string        $ref    The result reference.
     *
     * @return int
     *
     * @throws DslException When the reference is unknown.
     */
    private function resultRef(PackRunResult $result, string $ref): int
    {
        if ($ref === '@net') {
            return $result->net();
        }

        if ($ref === '@employerCharges') {
            return $result->employerCharges();
        }

        if ($ref === '@gross') {
            return $result->gross();
        }

        if (str_starts_with($ref, '@step.') === true) {
            return $result->cents(substr($ref, strlen('@step.')));
        }

        if (str_starts_with($ref, '@binding.') === true) {
            return (int) $result->binding(substr($ref, strlen('@binding.')));
        }

        throw new DslException('Pack: onbekende selfTest-verwijzing "'.$ref.'" (toegestaan: @net, @employerCharges, @gross, @step.*, @binding.*).');

    }//end resultRef()


}//end class
