<?php

namespace Unit\Controller;

use OCA\Humaniq\Controller\SetupController;
use OCA\Humaniq\Service\DemoDataService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-042 / ADR-111 setup contract.
 *
 * The assertions here are about what the wizard can OBSERVE. A step the status
 * document never mentions resolves to `done: false` forever, and an optional
 * step that can never be marked done keeps the wizard open over every page —
 * so "the step is reported" and "a decision closes it" are the contract, not
 * incidental detail.
 */
class SetupControllerTest extends TestCase {
	private IAppConfig $appConfig;
	private LoggerInterface $logger;
	private DemoDataService $demoData;
	private SetupController $controller;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->demoData = $this->createMock(DemoDataService::class);

		$this->controller = new SetupController(
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->logger,
			$this->demoData
		);
	}

	public function testStatusReportsTheDemoDataStep(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$data = $this->controller->status()->getData();

		// Absence is the defect this guards: a step the wizard is never told
		// about cannot be offered and cannot be completed.
		$this->assertArrayHasKey('demo-data', $data['steps']);
		$this->assertFalse($data['steps']['demo-data']['done']);
		// This app declares no REQUIRED step, so setup must never gate the app.
		$this->assertTrue($data['completed']);
		$this->assertSame(1, $data['version']);
	}

	public function testStatusReportsTheStepDoneOnceDecided(): void {
		$this->appConfig->method('getValueString')->willReturn('skipped');

		$data = $this->controller->status()->getData();

		$this->assertTrue($data['steps']['demo-data']['done']);
	}

	public function testSkippingIsAnAnswerAndIsRecorded(): void {
		// Declining must be persisted, otherwise the wizard re-offers the import
		// on every visit and "no thanks" is impossible to express.
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('humaniq', 'demo_data_decided', 'skipped');

		$response = $this->controller->runAction('skip-demo-data');

		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * THE DEFECT THIS WHOLE CHANGE EXISTS FOR. The manifest declared no setup
	 * block at all, so `CnAppRoot` had nothing to render and none of the 31
	 * shipped register descriptors could ever be offered. The backend was here
	 * the whole time; the wizard just never opened.
	 *
	 * The choice step declares `optionsSource: datasets` and no options, so a
	 * status document without `datasets` renders cards with nothing on them.
	 */
	public function testStatusPublishesTheCardsTheChoiceStepRendersFrom(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None, I will set this up myself', 'objectCount' => 0],
			['id' => 'humaniq-demo', 'label' => 'Example data', 'objectCount' => 42],
		]);

		$data = $this->controller->status()->getData();

		$this->assertArrayHasKey('datasets', $data);
		$this->assertSame(['none', 'humaniq-demo'], array_column($data['datasets'], 'id'));
		// Declining has to be offered, or the step cannot be answered with a no
		// and the wizard reopens over every page forever.
		$this->assertContains('none', array_column($data['datasets'], 'id'));
	}

	/**
	 * Every step the manifest declares must appear, or it is `done: false`
	 * forever and the wizard never closes.
	 */
	public function testStatusReportsEveryStepTheManifestDeclares(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('listChoices')->willReturn([]);

		$steps = $this->controller->status()->getData()['steps'];

		// Read from the manifest the wizard renders, not listed here by hand.
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$declared = array_column($manifest['setup']['steps'], 'id');
		$reported = array_keys($steps);
		sort($declared);
		sort($reported);
		$this->assertSame($declared, $reported);
	}

	/**
	 * One cards step with `loadAction` replaces the choice step plus the
	 * run-action step that loaded the pick (wizard-dataset-card-load). The
	 * served effective manifest carries the same setup block.
	 */
	public function testTheDatasetStepLoadsFromItsCards(): void {
		foreach (['manifest.json', 'manifest.effective.json'] as $file) {
			$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/' . $file), true);
			$steps = array_column($manifest['setup']['steps'], null, 'id');

			$this->assertSame('load-demo-data', $steps['demo-data']['loadAction'] ?? null, $file);
			$this->assertArrayNotHasKey('load-demo-data', $steps, $file);
		}
	}

	/**
	 * "None" is an ANSWER, so the load step has nothing left to do and must
	 * close. If it stayed outstanding the wizard would reopen forever on an
	 * instance that declined.
	 */
	public function testChoosingNoneClosesTheStepWithoutImporting(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => ($key === 'demo_dataset' ? 'none' : ''));
		$this->demoData->method('listChoices')->willReturn([]);
		$this->demoData->expects($this->never())->method('install');

		$steps = $this->controller->status()->getData()['steps'];

		$this->assertTrue($steps['demo-data']['done']);
		$this->assertArrayNotHasKey('load-demo-data', $steps);
	}

	/**
	 * The load action honours the recorded answer rather than assuming one.
	 */
	public function testLoadingAfterDecliningImportsNothingAndSaysSo(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => ($key === 'demo_dataset' ? 'none' : ''));
		$this->demoData->expects($this->never())->method('install');

		$res = $this->controller->runAction('load-demo-data');

		$this->assertTrue($res->getData()['success']);
	}

	/**
	 * An unanswered choice is refused rather than guessed at. Importing data
	 * nobody asked for is worse than an error that names the missing step.
	 */
	public function testLoadingWithNoChoiceRecordedIsRefused(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->expects($this->never())->method('install');

		$res = $this->controller->runAction('load-demo-data');

		$this->assertFalse($res->getData()['success']);
		$this->assertSame(400, $res->getStatus());
	}

	/**
	 * THE VALIDATION EXISTS SO THE ERROR ARRIVES HERE. An unknown dataset stored
	 * as-is would surface a step later as a failed import with no clue why, so
	 * the write is refused at the point the operator can still act on it.
	 */
	public function testAnUnknownDatasetIsRefusedRatherThanStored(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('no-such-set');
		$this->demoData->method('listChoices')->willReturn([['id' => 'none'], ['id' => 'humaniq-demo']]);

		$controller = new SetupController($request, $this->appConfig, $this->logger, $this->demoData);
		$this->appConfig->expects($this->never())->method('setValueString');

		$res = $controller->saveConfig();

		$this->assertFalse($res->getData()['success']);
		$this->assertSame(400, $res->getStatus());
	}

	/**
	 * A known dataset is written, and `_route` is not: it is Nextcloud's own
	 * routing parameter, not something the wizard asked to store.
	 */
	public function testAKnownDatasetIsStoredAndTheRouteParamIsNot(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('humaniq-demo');
		$request->method('getParams')->willReturn(['demo_dataset' => 'humaniq-demo', '_route' => 'humaniq.setup.saveConfig']);
		$this->demoData->method('listChoices')->willReturn([['id' => 'none'], ['id' => 'humaniq-demo']]);

		$written = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(static function (string $app, string $key, string $value) use (&$written): bool {
                $written[$key] = $value;
                return true;
            });

		$controller = new SetupController($request, $this->appConfig, $this->logger, $this->demoData);
		$res = $controller->saveConfig();

		$this->assertTrue($res->getData()['success']);
		$this->assertSame(['demo_dataset' => 'humaniq-demo'], $written);
	}

	/**
	 * A step that posts no dataset at all is not a dataset decision, so the
	 * validation must not run and must not refuse it.
	 */
	public function testAPostWithoutADatasetIsWrittenUnvalidated(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$request->method('getParams')->willReturn(['some_other_key' => 'value']);
		$this->demoData->expects($this->never())->method('listChoices');

		$controller = new SetupController($request, $this->appConfig, $this->logger, $this->demoData);

		$this->assertTrue($controller->saveConfig()->getData()['success']);
	}

	public function testUnknownActionIs404(): void {
		$response = $this->controller->runAction('not-an-action');

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	/**
	 * THE HAPPY PATH OF THE WHOLE STEP, and it was the one route through
	 * loadDataset() nothing exercised: the operator picked the shipped set in
	 * the choice step, and the load step then runs it. The two neighbouring
	 * routes — declining, and running with nothing recorded — were covered from
	 * the start, which is exactly how a gap like this hides.
	 */
	public function testLoadingAPickedDatasetImportsIt(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => ($key === 'demo_dataset' ? 'humaniq-demo' : ''));
		$this->demoData->expects($this->once())
			->method('install')
			->willReturn(['objects' => 12, 'registers' => 1, 'schemas' => 3]);

		$data = $this->controller->runAction('load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('12', $data['message']);
	}

	public function testInstallReportsHowMuchLanded(): void {
		$this->demoData->method('install')
			->willReturn(['objects' => 30, 'registers' => 1, 'schemas' => 4]);

		$written = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;

				return true;
			});

		$data = $this->controller->runAction('install-demo-data')->getData();

		// The legacy id names the shipped set, so that set is recorded as the pick.
		$this->assertSame(['demo_dataset' => 'humaniq-demo', 'demo_data_decided' => 'installed'], $written);

		$this->assertTrue($data['success']);
		// A success message that names no count cannot be told apart from an
		// import that wrote nothing — the defect this programme already shipped.
		$this->assertStringContainsString('30', $data['message']);
	}

	public function testAFailedInstallIsReportedAndLeavesTheStepUNDECIDED(): void {
		$this->demoData->method('install')
			->willThrowException(new RuntimeException('OpenRegister is not installed.'));

		// 🔴 THE POINT OF THIS TEST. Recording the decision here would close the
		// step for an operator who asked for demo data and received none: the
		// wizard would never offer it again, and nothing would have been
		// imported.
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->runAction('install-demo-data');

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('OpenRegister is not installed.', $response->getData()['message']);
	}

	/**
	 * Build a controller whose request carries the given body params.
	 *
	 * @param array<string, mixed> $params The posted body.
	 *
	 * @return SetupController
	 */
	private function controllerPosting(array $params): SetupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(static fn (string $key) => ($params[$key] ?? null));

		return new SetupController($request, $this->appConfig, $this->logger, $this->demoData);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function choices(): array {
		return [
			['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => ''],
			['id' => 'humaniq-demo', 'label' => 'Example data', 'description' => '', 'objectCount' => 12, 'icon' => ''],
		];
	}

	public function testTheCardPostsItsDatasetAndTheLoadRecordsTheChoice(): void {
		// The card's Load button posts `{ dataset }` to the step's
		// `loadAction`. Nothing was stored before: the card IS the choice.
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('listChoices')->willReturn($this->choices());
		$this->demoData->expects($this->once())->method('install')
			->willReturn(['objects' => 12, 'registers' => 1, 'schemas' => 3]);

		$written = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;

				return true;
			});

		$data = $this->controllerPosting(['dataset' => 'humaniq-demo'])->runAction('load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('12', $data['message']);
		$this->assertSame(['demo_dataset' => 'humaniq-demo', 'demo_data_decided' => 'installed'], $written);
	}

	public function testAnUnknownPostedDatasetIsRefusedAndNothingLoads(): void {
		$this->appConfig->method('getValueString')->willReturn('humaniq-demo');
		$this->demoData->method('listChoices')->willReturn($this->choices());
		$this->demoData->expects($this->never())->method('install');
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controllerPosting(['dataset' => 'atlantis'])->runAction('load-demo-data');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('atlantis', $response->getData()['message']);
	}

	public function testAFailedCardLoadStoresNothing(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('listChoices')->willReturn($this->choices());
		$this->demoData->method('install')->willThrowException(new RuntimeException('OpenRegister is not installed.'));
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controllerPosting(['dataset' => 'humaniq-demo'])->runAction('load-demo-data');

		$this->assertSame(500, $response->getStatus());
	}
}
