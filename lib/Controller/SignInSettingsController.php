<?php

/**
 * Humaniq SignInSettingsController
 *
 * The Inloggen section of the humaniq admin settings. `GET /api/settings/sign-in`
 * reads the sign-in methods and Nextcloud's password policy; `PUT` saves the
 * policy in `password_policy`'s own config. Both are for administrators only:
 * the methods carry no NoAdminRequired and no delegated admin setting, because
 * the password policy is the whole instance's, not humaniq's.
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
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use InvalidArgumentException;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\PolicyAppMissingException;
use OCA\Humaniq\Service\SignInSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The sign-in settings, for administrators.
 *
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */
class SignInSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request  The request.
	 * @param SignInSettingsService $settings Reads and saves Nextcloud's sign-in settings.
	 */
	public function __construct(
		IRequest $request,
		private readonly SignInSettingsService $settings,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `GET /api/settings/sign-in`: the sign-in methods and the password policy.
	 * Admin-only by Nextcloud's default for an un-attributed method.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	public function show(): JSONResponse {
		return new JSONResponse(data: $this->settings->read());
	}//end show()

	/**
	 * `PUT /api/settings/sign-in`: save password policy values in Nextcloud's
	 * password policy app. Admin-only by Nextcloud's default for an
	 * un-attributed method.
	 *
	 * @param mixed $passwordPolicy The values by key.
	 *
	 * @return JSONResponse The saved settings, 400 for an unknown key or a bad
	 *                      value, 409 when the password policy app is not enabled.
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-006
	 */
	public function update(mixed $passwordPolicy = null): JSONResponse {
		if (is_array($passwordPolicy) === false || ($passwordPolicy !== [] && array_is_list($passwordPolicy) === true)) {
			return new JSONResponse(data: ['message' => 'Send the password policy values by name.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse(data: $this->settings->save(values: $passwordPolicy));
		} catch (PolicyAppMissingException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end update()

}//end class
