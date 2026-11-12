<?php

/**
 * Golden test for FlowGraphKeypaths: the condition rewrite of every shape of JsonLogic.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\FlowGraphKeypaths;
use PHPUnit\Framework\TestCase;

/**
 * The condition rewrite of every shape of JsonLogic, both ways.
 */
class FlowGraphKeypathsGoldenTest extends TestCase {

	/**
	 * Every shape, rewritten to the JSON paths and back.
	 *
	 * @return void
	 */
	public function testTheRewriteOfEveryShapeOfCondition(): void {
		$nodes = [
			['exits' => [
				['condition' => ['==' => [['var' => 'check.status'], 'ok']]],
				['condition' => ['and' => [['var' => ['check', 'fallback']], ['!' => ['var' => 'other.flag']], ['in' => [['var' => 'checks'], ['a', 'b']]]]]],
				['condition' => ['var' => 'check']],
				['condition' => ['var' => []]],
				['condition' => true],
				['condition' => ['missing' => 'check.status']],
				['condition' => ['or' => [['var' => 'json.check.status'], ['var' => 'check.status.deep']]]],
				['condition' => ['==' => [['var' => 'checker'], 1]]],
				['label' => 'no condition'],
				'not-an-exit',
			]],
			['exits' => 'none'],
			'not-a-node',
		];
		$keypaths = new FlowGraphKeypaths();
		$json = $keypaths->withJsonOutcomePaths($nodes, ['check']);
		$got = ['json' => $json, 'back' => $keypaths->withV1OutcomePaths($json, ['check'])];

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/flow-graph-keypaths.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheRewriteOfEveryShapeOfCondition()
}//end class
