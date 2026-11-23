<?php

/**
 * Unit tests for SetupTemplates (configuration-store, REQ-FTS-007).
 *
 * The store controller is a small fake answering like OpenRegister's: a
 * search with built-in items and cards, and an install with a report or a
 * refusal. The app config is an in-memory map, so the tests read back the
 * answer the step leaves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service\Setup;

use OCA\Humaniq\Service\Setup\SetupTemplates;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The Sjablonen step, server side.
 */
class SetupTemplatesTest extends TestCase {

	/**
	 * The app config values written.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * The slugs the fake store was asked to install.
	 *
	 * @var array<int,string>
	 */
	private array $installs = [];

	/**
	 * A SetupTemplates over a fake store, or over no store when null.
	 *
	 * @param object|null $store The store controller fake.
	 *
	 * @return SetupTemplates
	 */
	private function templates(?object $store): SetupTemplates {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($store !== null);
		$container->method('get')->willReturnCallback(static fn () => $store ?? throw new RuntimeException('no store'));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->config[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});

		return new SetupTemplates($container, $appConfig, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A fake store answering search and install.
	 *
	 * @param array<string,mixed> $search  The search response data.
	 * @param JSONResponse|null   $install The install response, or null for a report.
	 *
	 * @return object
	 */
	private function store(array $search, ?JSONResponse $install = null): object {
		$test = $this;
		return new class($search, $install, $test) {
			/**
			 * Keep the canned answers.
			 *
			 * @param array<string,mixed> $search  The search response data.
			 * @param JSONResponse|null   $install The install response, or null for a report.
			 * @param SetupTemplatesTest  $test    Records the installs.
			 *
			 * @return void
			 */
			public function __construct(private array $search, private ?JSONResponse $install, private SetupTemplatesTest $test) {
			}

			/**
			 * Search.
			 *
			 * @return JSONResponse
			 */
			public function search(): JSONResponse {
				return new JSONResponse($this->search);
			}

			/**
			 * Install.
			 *
			 * @return JSONResponse
			 */
			public function install(string $slug): JSONResponse {
				$this->test->recordInstall($slug);
				return $this->install ?? new JSONResponse(['components' => ['leave-type' => 'created']]);
			}
		};
	}

	/**
	 * Record an install the fake store received.
	 *
	 * @param string $slug The slug.
	 *
	 * @return void
	 */
	public function recordInstall(string $slug): void {
		$this->installs[] = $slug;
	}

	/**
	 * Choices list none first then built in and published sets.
	 *
	 * @return void
	 */
	public function testChoicesListNoneFirstThenBuiltInAndPublishedSets(): void {
		$store = $this->store([
			'outcome' => 'ok',
			'builtIn' => [['slug' => 'leave-policy', 'title' => 'Leave policy', 'description' => 'Leave types and accrual.']],
			'cards'   => [
				['slug' => 'review-cycle', 'title' => 'Review cycle'],
				['slug' => 'leave-policy', 'title' => 'Duplicate'],
				['slug' => 'Bad Slug', 'title' => 'Refused'],
				'not an item',
			],
		]);

		$choices = $this->templates($store)->choices();

		$this->assertSame(['none', 'leave-policy', 'review-cycle'], array_column($choices, 'id'));
		$this->assertSame('Leave policy', $choices[1]['name']);
		$this->assertSame('Leave types and accrual.', $choices[1]['description']);
		$this->assertSame('', $choices[2]['description']);
	}

	/**
	 * Choices without a store offer only none.
	 *
	 * @return void
	 */
	public function testChoicesWithoutAStoreOfferOnlyNone(): void {
		$this->assertSame(['none'], array_column($this->templates(null)->choices(), 'id'));
	}

	/**
	 * Choices with a failing store offer only none.
	 *
	 * @return void
	 */
	public function testChoicesWithAFailingStoreOfferOnlyNone(): void {
		$store = new class {
			/**
			 * Search.
			 *
			 * @return JSONResponse
			 */
			public function search(): JSONResponse {
				throw new RuntimeException('registry down');
			}
		};

		$this->assertSame(['none'], array_column($this->templates($store)->choices(), 'id'));
	}

	/**
	 * A fresh step is not answered.
	 *
	 * @return void
	 */
	public function testAFreshStepIsNotAnswered(): void {
		$this->assertFalse($this->templates(null)->isAnswered());
	}

	/**
	 * Skipping is recorded as an answer.
	 *
	 * @return void
	 */
	public function testSkippingIsRecordedAsAnAnswer(): void {
		$templates = $this->templates(null);

		$templates->skip();

		$this->assertTrue($templates->isAnswered());
		$this->assertSame('["none"]', $this->config[SetupTemplates::CONFIG_KEY]);
		$this->assertSame([], $templates->installed());
	}

	/**
	 * Picking none is a skip.
	 *
	 * @return void
	 */
	public function testPickingNoneIsASkip(): void {
		$templates = $this->templates(null);

		$outcome = $templates->install('none');

		$this->assertTrue($outcome['success']);
		$this->assertTrue($templates->isAnswered());
		$this->assertSame([], $this->installs);
	}

	/**
	 * Installed sets are recorded in order once.
	 *
	 * @return void
	 */
	public function testInstalledSetsAreRecordedInOrderOnce(): void {
		$templates = $this->templates($this->store([]));

		$this->assertTrue($templates->install('leave-policy')['success']);
		$this->assertTrue($templates->install('review-cycle')['success']);
		$this->assertTrue($templates->install('leave-policy')['success']);

		$this->assertSame(['leave-policy', 'review-cycle', 'leave-policy'], $this->installs);
		$this->assertSame(['leave-policy', 'review-cycle'], $templates->installed());
		$this->assertTrue($templates->isAnswered());
	}

	/**
	 * An install replaces an earlier skip.
	 *
	 * @return void
	 */
	public function testAnInstallReplacesAnEarlierSkip(): void {
		$templates = $this->templates($this->store([]));
		$templates->skip();

		$templates->install('leave-policy');

		$this->assertSame('["leave-policy"]', $this->config[SetupTemplates::CONFIG_KEY]);
	}

	/**
	 * A skip after an install keeps the installed sets.
	 *
	 * @return void
	 */
	public function testASkipAfterAnInstallKeepsTheInstalledSets(): void {
		$templates = $this->templates($this->store([]));
		$templates->install('leave-policy');

		$templates->skip();

		$this->assertSame(['leave-policy'], $templates->installed());
	}

	/**
	 * A refused install records nothing.
	 *
	 * @return void
	 */
	public function testARefusedInstallRecordsNothing(): void {
		$refusal = new JSONResponse(['success' => false, 'message' => 'Installing a store item requires an administrator.'], 403);
		$templates = $this->templates($this->store([], $refusal));

		$outcome = $templates->install('leave-policy');

		$this->assertFalse($outcome['success']);
		$this->assertSame(403, $outcome['status']);
		$this->assertSame('Installing a store item requires an administrator.', $outcome['message']);
		$this->assertFalse($templates->isAnswered());
	}

	/**
	 * An install reporting failure records nothing.
	 *
	 * @return void
	 */
	public function testAnInstallReportingFailureRecordsNothing(): void {
		$templates = $this->templates($this->store([], new JSONResponse(['success' => false])));

		$outcome = $templates->install('leave-policy');

		$this->assertFalse($outcome['success']);
		$this->assertSame(400, $outcome['status']);
		$this->assertFalse($templates->isAnswered());
	}

	/**
	 * A bad slug never reaches the store.
	 *
	 * @return void
	 */
	public function testABadSlugNeverReachesTheStore(): void {
		$templates = $this->templates($this->store([]));

		$this->assertSame(400, $templates->install('../etc')['status']);
		$this->assertSame(400, $templates->install(null)['status']);
		$this->assertSame(400, $templates->install(['leave-policy'])['status']);
		$this->assertSame([], $this->installs);
	}

	/**
	 * Without a store an install is unavailable.
	 *
	 * @return void
	 */
	public function testWithoutAStoreAnInstallIsUnavailable(): void {
		$templates = $this->templates(null);

		$outcome = $templates->install('leave-policy');

		$this->assertFalse($outcome['success']);
		$this->assertSame(503, $outcome['status']);
		$this->assertFalse($templates->isAnswered());
	}
}
