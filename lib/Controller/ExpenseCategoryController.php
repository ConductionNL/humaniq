<?php

/**
 * Humaniq ExpenseCategoryController
 *
 * Reads and saves which expense categories employees may claim.
 * `GET /api/expense-categories` is open to every signed-in user, so a form can
 * show what may be claimed. `PUT /api/expense-categories` is an admin setting.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
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
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use InvalidArgumentException;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\ExpenseCategoryPolicy;
use OCA\Humaniq\Settings\HumaniqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The expense category switches.
 *
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */
class ExpenseCategoryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ExpenseCategoryPolicy $policy The category switches.
	 */
	public function __construct(
		IRequest $request,
		private readonly ExpenseCategoryPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `GET /api/expense-categories`: every category and whether it is on. The
	 * list holds no personal data, so every signed-in user may read it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse(data: ['categories' => $this->policy->categories()]);
	}//end index()

	/**
	 * `PUT /api/expense-categories`: save which categories are on.
	 *
	 * @param mixed $allowed The categories that are on, as a list of names.
	 *
	 * @return JSONResponse The saved switches, or 400 when the list is missing,
	 *                      not a list of names, names an unknown category or
	 *                      switches every category off.
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	#[AuthorizedAdminSetting(HumaniqAdmin::class)]
	public function update(mixed $allowed = null): JSONResponse {
		if (is_array($allowed) === false || array_is_list($allowed) === false || array_filter($allowed, 'is_string') !== $allowed) {
			return new JSONResponse(data: ['message' => 'Send the categories that are on as a list of names.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->policy->saveAllowed($allowed);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return $this->index();
	}//end update()

}//end class
