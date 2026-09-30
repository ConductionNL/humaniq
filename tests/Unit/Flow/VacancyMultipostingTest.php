<?php

/**
 * Posting a vacancy to job boards: the boards on the vacancy, one posting
 * record per board, and the two shipped flows that post and withdraw.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Flow
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
 * @spec openspec/changes/hiring-multiposting/specs/vacancy-multiposting/spec.md#REQ-VMP-001
 * @spec openspec/changes/hiring-multiposting/specs/vacancy-multiposting/spec.md#REQ-VMP-002
 * @spec openspec/changes/hiring-multiposting/specs/vacancy-multiposting/spec.md#REQ-VMP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The boards, the posting record and the two flows, read from the shipped
 * register fragment.
 */
class VacancyMultipostingTest extends TestCase {

	/**
	 * The node types the two flows may use: OpenRegister's engine and
	 * integriq's connector steps. Pinned, so a typo is a red test and not a
	 * step the engine cannot resolve after import.
	 *
	 * @var array<int, string>
	 */
	private const NODE_TYPES = [
		'openregister.trigger-object',
		'openregister.filter',
		'openregister.object-write',
		'openregister.object-read',
		'openregister.explode',
		'openregister.route',
		'openregister.set-fields',
		'openregister.end',
		'openconnector.apply-mapping',
		'openconnector.source-call',
	];

	/**
	 * The boards shipped with a branch in each flow.
	 *
	 * @var array<int, string>
	 */
	private const BOARDS = ['werk-nl', 'linkedin', 'indeed'];

	/**
	 * The ats fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(): array {
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/hr-ats.json'), true);
		self::assertIsArray($fragment);
		return $fragment;
	}//end fragment()

	/**
	 * One declared flow on Vacancy, by name.
	 *
	 * @param string $name The flow name.
	 *
	 * @return array<string, mixed>
	 */
	private function flow(string $name): array {
		$flows = ($this->fragment()['components']['schemas']['Vacancy']['configuration']['x-openregister-flows'] ?? []);
		foreach ($flows as $flow) {
			if (($flow['name'] ?? '') === $name) {
				return $flow;
			}
		}

		self::fail('Vacancy declares no flow named ' . $name);
	}//end flow()

	/**
	 * The nodes of a flow keyed by id.
	 *
	 * @param array<string, mixed> $flow The flow.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function nodes(array $flow): array {
		$nodes = [];
		foreach ($flow['nodes'] as $node) {
			$nodes[$node['id']] = $node;
		}

		return $nodes;
	}//end nodes()

	/**
	 * The nodes of one type.
	 *
	 * @param array<string, mixed> $flow The flow.
	 * @param string               $type The node type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ofType(array $flow, string $type): array {
		return array_values(array_filter($flow['nodes'], static fn (array $node): bool => $node['type'] === $type));
	}//end ofType()

	/**
	 * An adviser's board choice is stored on the vacancy, and a board code
	 * is a plain name, not an enum a release would have to extend.
	 *
	 * @return void
	 */
	public function testAVacancyCarriesItsBoards(): void {
		$channels = ($this->fragment()['components']['schemas']['Vacancy']['properties']['channels'] ?? null);
		self::assertIsArray($channels, 'Vacancy has no channels');
		self::assertSame('array', $channels['type']);
		self::assertArrayNotHasKey('enum', $channels['items'], 'which boards exist is integriq configuration (D2)');

		$vacancy = ['title' => 'Payroll adviser', 'status' => 'concept', 'channels' => ['werk-nl', 'linkedin']];
		self::assertSame([], RegisterSchemaValidator::errors('Vacancy', $vacancy));
		$vacancy['channels'] = ['werk-nl', ''];
		self::assertNotSame([], RegisterSchemaValidator::errors('Vacancy', $vacancy), 'an empty board code names no source');
	}//end testAVacancyCarriesItsBoards()

	/**
	 * The posting record takes each outcome the flows write.
	 *
	 * @return void
	 */
	public function testAPostingRecordsEachOutcome(): void {
		$vacancy = '5d1d6c1e-0000-4000-8000-000000000001';
		$posted = ['vacancyId' => $vacancy, 'channel' => 'werk-nl', 'status' => 'geplaatst', 'externalId' => 'WN-4411', 'externalUrl' => 'https://example.org/vacature/1', 'postedAt' => '2026-09-30T09:00:00+00:00'];
		$failed = ['vacancyId' => $vacancy, 'channel' => 'linkedin', 'status' => 'mislukt', 'lastError' => 'no source configured'];
		$withdrawn = ['vacancyId' => $vacancy, 'channel' => 'werk-nl', 'status' => 'ingetrokken', 'withdrawnAt' => '2026-10-31T09:00:00+00:00'];

		foreach ([$posted, $failed, $withdrawn] as $posting) {
			self::assertSame([], RegisterSchemaValidator::errors('VacancyPosting', $posting), $posting['status']);
		}

		$posted['status'] = 'live';
		self::assertNotSame([], RegisterSchemaValidator::errors('VacancyPosting', $posted), 'status is a closed list');

		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/humaniq_register.json'), true);
		self::assertStringContainsString('"VacancyPosting"', json_encode($register), 'the register lists VacancyPosting');
	}//end testAPostingRecordsEachOutcome()

	/**
	 * Both flows fire on a vacancy update, arrive disabled, and use only
	 * known step types on a graph whose edges resolve.
	 *
	 * @return void
	 */
	public function testBothFlowsAreSoundAndShipDisabled(): void {
		foreach (['Vacature plaatsen', 'Vacature intrekken'] as $name) {
			$flow = $this->flow($name);
			self::assertSame('humaniq', $flow['app']);
			self::assertSame('object.updated', $flow['trigger']);
			self::assertArrayNotHasKey('enabled', $flow, 'a declared flow arrives disabled (D4)');

			$nodes = $this->nodes($flow);
			foreach ($nodes as $node) {
				self::assertContains($node['type'], self::NODE_TYPES, $name . ': ' . $node['id']);
			}

			$trigger = $this->ofType($flow, 'openregister.trigger-object');
			self::assertCount(1, $trigger);
			self::assertSame(['event' => 'object.updated', 'register' => 'humaniq', 'schema' => 'Vacancy'], $trigger[0]['config']);

			foreach ($flow['edges'] as $edge) {
				self::assertArrayHasKey($edge['from'], $nodes, $name . ' edge ' . $edge['id']);
				self::assertArrayHasKey($edge['to'], $nodes, $name . ' edge ' . $edge['id']);
				if (isset($edge['fromExit']) === true) {
					self::assertContains($edge['fromExit'], array_column($nodes[$edge['from']]['exits'] ?? [], 'id'), $name . ' edge ' . $edge['id']);
				}
			}

			// A router sends each item to one output: every output needs an edge.
			foreach ($this->ofType($flow, 'openregister.route') as $router) {
				$outputs = array_column($router['config']['rules'], 'output');
				$outputs[] = $router['config']['default'];
				self::assertSame($outputs, array_column($router['exits'], 'id'), $name . ' ' . $router['id']);
				$wired = array_column(array_filter($flow['edges'], static fn (array $e): bool => $e['from'] === $router['id']), 'fromExit');
				self::assertEqualsCanonicalizing($outputs, $wired, $name . ' ' . $router['id'] . ' has an output with no edge');
			}
		}//end foreach
	}//end testBothFlowsAreSoundAndShipDisabled()

	/**
	 * Publishing posts once per board: only a published vacancy that was not
	 * posted before, one item per board, a branch per shipped board, and a
	 * failed call carried on rather than stopping the other boards.
	 *
	 * @return void
	 */
	public function testThePostingFlowPostsEachBoardOnce(): void {
		$flow = $this->flow('Vacature plaatsen');
		$filter = $this->ofType($flow, 'openregister.filter');
		self::assertCount(1, $filter);
		$condition = json_encode($filter[0]['config']['condition']);
		self::assertStringContainsString('"gepubliceerd"', $condition);
		self::assertStringContainsString('json.postedToChannels', $condition, 'a later save of a published vacancy does not post again');

		$explode = $this->ofType($flow, 'openregister.explode');
		self::assertSame('channels', $explode[0]['config']['path']);

		$calls = $this->ofType($flow, 'openconnector.source-call');
		self::assertSame(self::BOARDS, array_column(array_column($calls, 'config'), 'source'), 'the board code is the integriq source name (D2)');
		foreach ($calls as $call) {
			self::assertSame('continue', $call['config']['onError'], 'one failing board does not stop the others');
			self::assertSame('POST', $call['config']['method']);
		}

		self::assertCount(count(self::BOARDS), $this->ofType($flow, 'openconnector.apply-mapping'));

		$router = $this->nodes($flow)['per-board'];
		self::assertSame('no-source', $router['config']['default'], 'a board with no branch is recorded as failed');
	}//end testThePostingFlowPostsEachBoardOnce()

	/**
	 * Every posting write upserts on vacancy and board, so a re-run updates
	 * the posting instead of adding one, and writes a valid posting.
	 *
	 * @return void
	 */
	public function testEveryPostingWriteUpsertsOnVacancyAndBoard(): void {
		// The item as it reaches a posting write: the vacancy's id renamed to
		// vacancyId, one board, the board's answer or the error, the moment.
		$item = [
			'vacancyId' => '5d1d6c1e-0000-4000-8000-000000000001',
			'administrationId' => 'ADM-001',
			'channel' => 'linkedin',
			'boardResponse' => ['externalId' => 'LI-1', 'externalUrl' => 'https://example.org/li/1'],
			'error' => ['message' => 'The call to source "linkedin" returned status 500.'],
			'now' => '2026-09-30T09:00:00+00:00',
		];

		$writes = 0;
		foreach (['Vacature plaatsen', 'Vacature intrekken'] as $name) {
			foreach ($this->ofType($this->flow($name), 'openregister.object-write') as $write) {
				if ($write['config']['schema'] !== 'VacancyPosting') {
					continue;
				}

				$writes++;
				$config = $write['config'];
				self::assertSame('humaniq', $config['register']);
				self::assertContains($config['operation'], ['upsert', 'update'], $write['id']);
				if ($config['operation'] === 'upsert') {
					self::assertEqualsCanonicalizing(['vacancyId', 'channel'], array_column($config['match'], 'property'), $write['id']);
				}

				$payload = $this->render($config['fields'], $item);
				if ($config['operation'] === 'upsert') {
					self::assertSame([], RegisterSchemaValidator::errors('VacancyPosting', $payload), $write['id']);
				}
			}
		}

		self::assertGreaterThanOrEqual(3, $writes, 'posted, failed and withdrawn are all written');
	}//end testEveryPostingWriteUpsertsOnVacancyAndBoard()

	/**
	 * Closing withdraws only what is live, from each board, and marks it
	 * withdrawn with the moment.
	 *
	 * @return void
	 */
	public function testTheWithdrawFlowWithdrawsWhatIsLive(): void {
		$flow = $this->flow('Vacature intrekken');
		$condition = json_encode($this->ofType($flow, 'openregister.filter')[0]['config']['condition']);
		self::assertStringContainsString('"gesloten"', $condition);

		$read = $this->ofType($flow, 'openregister.object-read')[0]['config'];
		self::assertSame('VacancyPosting', $read['schema']);
		self::assertSame('geplaatst', $read['filters']['status']);
		self::assertTrue($read['fanOut']);

		$calls = $this->ofType($flow, 'openconnector.source-call');
		self::assertSame(self::BOARDS, array_column(array_column($calls, 'config'), 'source'));
		foreach ($calls as $call) {
			self::assertSame('DELETE', $call['config']['method']);
			self::assertStringContainsString('{{ externalId }}', $call['config']['endpoint']);
		}

		$fields = array_column(array_column($this->ofType($flow, 'openregister.object-write'), 'config'), 'fields');
		self::assertContains('ingetrokken', array_column($fields, 'status'));
		self::assertContains('{{ now }}', array_column($fields, 'withdrawnAt'));
	}//end testTheWithdrawFlowWithdrawsWhatIsLive()

	/**
	 * The vacancy page lets HR pick the boards and shows the postings.
	 *
	 * @return void
	 */
	public function testTheVacancyPageShowsBoardsAndPostings(): void {
		$manifest = (string)file_get_contents(dirname(__DIR__, 3) . '/src/manifest.d/hr-ats.json');
		self::assertStringContainsString('"channels"', $manifest);
		self::assertStringContainsString('"VacancyPosting"', $manifest);

		$description = $this->fragment()['components']['schemas']['Vacancy']['description'];
		self::assertStringNotContainsString('no external multiposting', $description);
	}//end testTheVacancyPageShowsBoardsAndPostings()

	/**
	 * Render the whole-value placeholders of a field map against an item, the
	 * way the object-write step resolves them.
	 *
	 * @param array<string, mixed> $fields The field map.
	 * @param array<string, mixed> $item   The item.
	 *
	 * @return array<string, mixed>
	 */
	private function render(array $fields, array $item): array {
		$out = [];
		foreach ($fields as $key => $value) {
			if (is_string($value) === true && preg_match('/^\{\{\s*([^{}]+?)\s*\}\}$/', $value, $m) === 1) {
				$cursor = $item;
				foreach (explode('.', $m[1]) as $segment) {
					$cursor = ($cursor[$segment] ?? null);
				}

				if ($cursor === null) {
					continue;
				}

				$value = $cursor;
			}

			$out[$key] = $value;
		}

		return $out;
	}//end render()

}//end class
