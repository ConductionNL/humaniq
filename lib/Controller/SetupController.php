<?php
/**
 * Humaniq SetupController.
 *
 * The ADR-042 first-time setup contract, in its smallest honest form:
 *
 *   GET  /api/setup/status            per-step state
 *   POST /api/setup/action/{actionId} run a privileged server-side action
 *
 * This app declares no configuration of its own yet, so the wizard orients and
 * offers the demo data the app ALREADY ships — a dataset generated from its own
 * schemas that no operator could previously reach. It deliberately does not
 * invent configuration steps: a wizard that asks questions the app does not act
 * on is worse than none.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Settings\HumaniqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCA\Humaniq\Service\DemoDataService;

/**
 * First-time setup wizard endpoints.
 *
 * @spec exclude First-time-setup action dispatch; ADR-042 contract, no per-app behavioural spec.
 */
class SetupController extends Controller {
	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * @var integer
	 */
	private const SETUP_VERSION = 1;

	/**
	 * App-config key recording that the demo-data step was DEALT WITH.
	 *
	 * Not "objects exist": an operator who declines has finished the step, and
	 * re-offering the import on every visit would make "no thanks" impossible to
	 * express. Since @conduction/nextcloud-vue 2.21 that also matters visually —
	 * an OUTSTANDING OPTIONAL step opens the wizard over every page
	 * (nextcloud-vue#806), so a step that can never be marked done is a dialog
	 * that never closes.
	 *
	 * @var string
	 */
	private const DEMO_DECIDED_KEY = 'demo_data_decided';

	/**
	 * App-config key holding WHICH dataset the operator picked.
	 *
	 * Separate from DEMO_DECIDED_KEY on purpose: that one records that the step
	 * was dealt with, this one records the answer. A card's Load button posts
	 * `{ dataset }` and the load action stores it here once the load succeeds;
	 * a pick saved through the config endpoint lands here too. "none" and "not
	 * asked yet" have to be tellable apart: an empty value means the question
	 * is still open, and `none` means it was answered with a no.
	 *
	 * @var string
	 */
	private const DATASET_KEY = 'demo_dataset';

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request         The request.
	 * @param IAppConfig      $appConfig       Records the demo-data decision.
	 * @param LoggerInterface $logger          Records a failed import.
	 * @param DemoDataService $demoDataService Imports the shipped demo dataset.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly DemoDataService $demoDataService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * `completed` is deliberately TRUE: this app declares no REQUIRED step, so
	 * setup must never gate the app. The demo-data step is reported so the wizard
	 * can stop asking once it has an answer.
	 *
	 * @return JSONResponse The status document.
	 *
	 * @auth admin-only This app registers no admin settings class, so there
	 *       is nothing to authorize against. Nextcloud's SecurityMiddleware
	 *       already requires an admin session for a method that does not opt
	 *       out, and this method does not opt out — so there is no attribute
	 *       to add and this tag is the declaration. Deliberately NOT the
	 *       authorized-admin-setting attribute (named here without its
	 *       bracket form on purpose: gate-5 decides whether a routed method
	 *       declares a posture by grepping for that form, so writing it in a
	 *       comment would silently read as a real declaration), which would
	 *       additionally admit delegated settings admins.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(HumaniqAdmin::class)]
	public function status(): JSONResponse {
		$demoDecided = $this->appConfig->getValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, '') !== '';

		$picked = $this->appConfig->getValueString(Application::APP_ID, self::DATASET_KEY, '');

		return new JSONResponse(
			data: [
				'version'   => self::SETUP_VERSION,
				'completed' => true,
				// The choice step declares `optionsSource: datasets` and no
				// options of its own, so this list IS the card set.
				'datasets'  => $this->demoDataService->listChoices(),
				'steps'     => [
					'welcome'        => ['done' => true],
					// Exactly the ids of `manifest.setup.steps`: a step the
					// server never reports stays open and reopens the wizard.
					'demo-data'      => ['done' => ($demoDecided === true || $picked !== '')],
					'done'           => ['done' => true],
				],
			]
		);

	}//end status()

	/**
	 * Run a privileged server-side setup action.
	 *
	 * Admin-only by Nextcloud's default for an un-attributed method.
	 *
	 * @param string $actionId One of `install-demo-data` | `skip-demo-data`.
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @auth admin-only This app registers no admin settings class, so there
	 *       is nothing to authorize against. Nextcloud's SecurityMiddleware
	 *       already requires an admin session for a method that does not opt
	 *       out, and this method does not opt out — so there is no attribute
	 *       to add and this tag is the declaration. Deliberately NOT the
	 *       authorized-admin-setting attribute (named here without its
	 *       bracket form on purpose: gate-5 decides whether a routed method
	 *       declares a posture by grepping for that form, so writing it in a
	 *       comment would silently read as a real declaration), which would
	 *       additionally admit delegated settings admins.
	 *
	 * @spec exclude Setup action dispatch; ADR-042 contract, no per-app behavioural spec.
	 */
	#[AuthorizedAdminSetting(HumaniqAdmin::class)]
	public function runAction(string $actionId): JSONResponse {
		if ($actionId === 'load-demo-data' || $actionId === 'install-demo-data') {
			return $this->loadDataset(actionId: $actionId);
		}

		// DECLINING IS AN ANSWER — see DEMO_DECIDED_KEY.
		if ($actionId === 'skip-demo-data') {
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');

			return new JSONResponse(data: ['success' => true, 'message' => 'Demo data skipped.']);
		}

		return new JSONResponse(
			data: ['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			statusCode: Http::STATUS_NOT_FOUND,
		);

	}//end runAction()

	/**
	 * Persist app-config values a `choice` or `config-fields` step posted.
	 *
	 * @return JSONResponse `{ success }`.
	 *
	 * @auth admin-only Same posture as the other two methods on this
	 *       controller, declared the same way.
	 *
	 * @spec exclude First-time setup wizard backend (ADR-042); no per-app behavioural spec.
	 */
	#[AuthorizedAdminSetting(HumaniqAdmin::class)]
	public function saveConfig(): JSONResponse {
		// 🔴 THE DATASET IS VALIDATED BEFORE IT IS STORED. Everything else is
		// written as posted, because a `config-fields` step declares its own
		// keys and this endpoint cannot know them. The dataset is different: the
		// load step reads it back and hands it to the importer, so an unknown
		// value would surface a step later as a failed import with no clue why.
		$dataset = $this->request->getParam(self::DATASET_KEY);
		if ($dataset !== null) {
			$named = 'that';
			if (is_scalar($dataset) === true) {
				$named = (string)$dataset;
			}

			$known = array_column($this->demoDataService->listChoices(), 'id');
			if (in_array($named, $known, true) === false) {
				return new JSONResponse(
					data: ['success' => false, 'message' => 'No dataset is called "' . $named . '".'],
					statusCode: Http::STATUS_BAD_REQUEST,
				);
			}
		}

		foreach ($this->request->getParams() as $key => $value) {
			if ($key === '_route') {
				continue;
			}

			$stored = (string)json_encode($value);
			if (is_scalar($value) === true) {
				$stored = (string)$value;
			}

			$this->appConfig->setValueString(Application::APP_ID, (string)$key, $stored);
		}

		return new JSONResponse(data: ['success' => true]);

	}//end saveConfig()

	/**
	 * Act on the dataset a card's Load button posted as `dataset`, or on the
	 * stored pick when nothing is posted.
	 *
	 * @param string $actionId The action that asked, which decides what an
	 *                         unanswered choice means.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function loadDataset(string $actionId): JSONResponse {
		$picked = $this->appConfig->getValueString(Application::APP_ID, self::DATASET_KEY, '');

		// The card's Load button names its dataset in the body. An older wizard
		// posts nothing and relies on the pick stored a step earlier. Nothing is
		// stored before the load succeeds: a failed load must leave the step
		// open for an operator who asked for data and got none.
		$posted = $this->request->getParam('dataset');
		if ($posted !== null) {
			$refusal = $this->refuseDataset(value: $posted);
			if ($refusal !== null) {
				return $refusal;
			}

			$picked = (string)$posted;
		}

		// Declining is an answer, and the work is already done: record that the
		// step is finished and import nothing.
		if ($picked === DemoDataService::NONE_DATASET) {
			$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoDataService::NONE_DATASET);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');

			return new JSONResponse(data: ['success' => true, 'message' => 'No example data imported, as chosen.']);
		}

		// `install-demo-data` carries no answer of its own, so a caller that
		// posts it has said which one by posting it. `load-demo-data` with
		// nothing recorded has not been answered yet, and guessing would import
		// data nobody asked for.
		if ($picked === '' && $actionId === 'load-demo-data') {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Pick a dataset first.'],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		if ($picked === '') {
			$picked = DemoDataService::DEMO_DATASET;
		}

		return $this->installDemoData(dataset: $picked);

	}//end loadDataset()

	/**
	 * Refuse a posted dataset id no dataset answers to.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return JSONResponse|null The refusal, or null when the dataset is known.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function refuseDataset(mixed $value): ?JSONResponse {
		$named = 'that';
		if (is_scalar($value) === true) {
			$named = (string)$value;
		}

		$known = array_column($this->demoDataService->listChoices(), 'id');
		if (is_scalar($value) === true && in_array($named, $known, true) === true) {
			return null;
		}

		return new JSONResponse(
			data: ['success' => false, 'message' => 'No dataset is called "' . $named . '".'],
			statusCode: Http::STATUS_BAD_REQUEST,
		);

	}//end refuseDataset()

	/**
	 * Import the shipped demo dataset.
	 *
	 * Reports the FAILURE rather than a quiet success: an operator who asked for
	 * demo data and got none must be told, which is why DemoDataService::install()
	 * throws instead of returning an empty result.
	 *
	 * @param string $dataset The dataset id, recorded as the pick once the
	 *                        import succeeded.
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function installDemoData(string $dataset): JSONResponse {
		try {
			$imported = $this->demoDataService->install();
		} catch (\Throwable $e) {
			$this->logger->error(
				'Setup install-demo-data failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]
			);

			return new JSONResponse(
				data: ['success' => false, 'message' => 'Could not import the demo data: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		// Loading IS choosing the set, so the pick is recorded too.
		$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, $dataset);
		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'installed');

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'Imported ' . $imported['objects'] . ' demo object(s).',
			]
		);

	}//end installDemoData()
}//end class
