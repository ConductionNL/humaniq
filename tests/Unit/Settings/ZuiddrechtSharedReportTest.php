<?php

/**
 * Example data for My reports: one shared saved view on the contracts list.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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
 * @spec openspec/changes/archive/2026-11-24-reporting-standard-reports-and-builder/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Zuiddrecht fragment seeds a public view that the My reports page lists and the contracts list applies.
 */
class ZuiddrechtSharedReportTest extends TestCase {

	/**
	 * The fields OpenRegister's example-set installer accepts on a view.
	 *
	 * @var array<int, string>
	 */
	private const VIEW_FIELDS = ['name', 'description', 'owner', 'isPublic', 'query', 'presentation', 'alert', 'sharedWith'];

	/**
	 * Read a JSON file of the app.
	 *
	 * @param string $path Path relative to the app root.
	 *
	 * @return array<string, mixed>
	 */
	private function readJson(string $path): array {
		$data = json_decode((string)file_get_contents(__DIR__.'/../../../'.$path), true);
		$this->assertIsArray(actual: $data, message: $path.' is JSON');

		return $data;
	}//end readJson()

	/**
	 * The one seeded view on the contracts list.
	 *
	 * @return array<string, mixed>
	 */
	private function contractView(): array {
		$fragment = $this->readJson(path: 'lib/Settings/example-sets/zuiddrecht.json');
		$this->assertArrayHasKey(key: 'views', array: $fragment);

		$found = [];
		foreach ($fragment['views'] as $view) {
			if (($view['data']['query']['schemas'] ?? []) === ['EmploymentContract']) {
				$found[] = $view;
			}
		}

		$this->assertCount(expectedCount: 1, haystack: $found, message: 'one shared report on the contracts list');

		return $found[0];
	}//end contractView()

	/**
	 * The view has a key, only fields the installer accepts, a cast owner and is public.
	 *
	 * @return void
	 */
	public function testTheViewIsAPublicViewOfACastAccount(): void {
		$view = $this->contractView();

		$this->assertSame(expected: 'contracten-einde-kwartaal', actual: $view['key']);
		$this->assertSame(expected: [], actual: array_values(array_diff(array_keys($view['data']), self::VIEW_FIELDS)));
		$this->assertNotSame(expected: '', actual: trim((string)$view['data']['name']));
		$this->assertMatchesRegularExpression(pattern: '/^\{\{cast:[a-z]+\.uid\}\}$/', string: $view['data']['owner']);
		$this->assertTrue(condition: $view['data']['isPublic'], message: 'a shared report is public');
		$this->assertIsArray(actual: $view['data']['query']);
	}//end testTheViewIsAPublicViewOfACastAccount()

	/**
	 * The My reports page has a saved-views widget for exactly this register and schema, listing shared views.
	 *
	 * @return void
	 */
	public function testMyReportsListsTheView(): void {
		$query   = $this->contractView()['data']['query'];
		$reports = $this->readJson(path: 'src/manifest.d/hr-reports.json');

		$widgets = [];
		foreach ($reports['pages'] as $page) {
			if ($page['id'] !== 'MyReports') {
				continue;
			}

			foreach ($page['config']['widgets'] as $widget) {
				$content = ($widget['content'] ?? []);
				if ($widget['type'] === 'saved-views'
					&& [($content['register'] ?? '')] === $query['registers']
					&& [($content['schema'] ?? '')] === $query['schemas']
				) {
					$widgets[] = $content;
				}
			}
		}

		$this->assertCount(expectedCount: 1, haystack: $widgets);
		$this->assertFalse(condition: $widgets[0]['own'], message: 'the widget shows views shared with the reader');
		$this->assertSame(expected: 'EmploymentContracts', actual: $widgets[0]['route']);
	}//end testMyReportsListsTheView()

	/**
	 * The view filters a date range on the end date, relative to the install day, and the list offers that filter.
	 *
	 * @return void
	 */
	public function testTheViewFiltersAnEndDateRangeTheListOffers(): void {
		$query = $this->contractView()['data']['query'];

		$this->assertSame(
			expected: ['endDate[gte]' => '{{date:0}}', 'endDate[lte]' => '{{date:91}}'],
			actual: $query['filters']
		);
		$this->assertSame(expected: [['key' => 'endDate', 'order' => 'asc']], actual: $query['sort']);

		$effective = $this->readJson(path: 'src/manifest.effective.json');
		$filters   = null;
		foreach ($effective['pages'] as $page) {
			if ($page['id'] === 'EmploymentContracts') {
				$filters = $page['config']['filters'];
			}
		}

		$this->assertIsArray(actual: $filters, message: 'the contracts list exists');
		$this->assertContains(needle: 'endDate', haystack: $filters, message: 'the list offers the end-date range filter the view applies');
	}//end testTheViewFiltersAnEndDateRangeTheListOffers()
}//end class
