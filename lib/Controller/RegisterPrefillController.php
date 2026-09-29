<?php

/**
 * Register Prefill Controller
 *
 * `POST /api/prefill/vehicle/{assetId}` fills a company car from the RDW and
 * `POST /api/prefill/employee/{employeeId}` fills an employee from the BRP
 * (people-register-prefill). Only HR or an administrator may ask (D4); the
 * record is read under the caller's own rights. The BRP is asked only when
 * the employee's administration records a legal basis in
 * `hrAdministration.brpGrondslag`; without one the request is refused and
 * nothing is sent (D3). The filled fields are saved; a save an approval rule
 * refuses answers 409 with that rule's message, so a guarded field still
 * goes through a change request.
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
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\RegisterPrefillService;
use OCA\Humaniq\Service\RegisterPrefillUnavailableException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Fill a record from the RDW or the BRP.
 *
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */
class RegisterPrefillController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     The request.
	 * @param RegisterPrefillService $prefill     Looks the record up.
	 * @param HoursRegisterGateway   $gateway     Saves the filled fields, reads the administration.
	 * @param RbacObjectReader       $rbac        Reads under the caller's own rights.
	 * @param HumaniqRoles           $roles       The HR check.
	 * @param IUserSession           $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly RegisterPrefillService $prefill,
		private readonly HoursRegisterGateway $gateway,
		private readonly RbacObjectReader $rbac,
		private readonly HumaniqRoles $roles,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Fill a vehicle asset from the RDW.
	 *
	 * @param string $assetId The Asset id.
	 *
	 * @return JSONResponse `{filled, differs}`; 403, 404 or 409 otherwise.
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	#[NoAdminRequired]
	public function vehicle(string $assetId): JSONResponse {
		if ($this->mayFill() === false) {
			return $this->refused();
		}

		$asset = $this->rbac->findOrNull(id: $assetId, schema: 'Asset');
		if ($asset === null) {
			return new JSONResponse(['message' => 'Middel niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		return $this->fill(schema: 'Asset', objectId: $assetId, lookup: fn (): array => $this->prefill->vehicle($asset));
	}//end vehicle()

	/**
	 * Fill an employee from the BRP, on a recorded legal basis.
	 *
	 * @param string $employeeId The Employee id.
	 *
	 * @return JSONResponse `{filled, differs}`; 403, 404 or 409 otherwise.
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
	 */
	#[NoAdminRequired]
	public function employee(string $employeeId): JSONResponse {
		if ($this->mayFill() === false) {
			return $this->refused();
		}

		$employee = $this->rbac->findOrNull(id: $employeeId, schema: 'Employee');
		if ($employee === null) {
			return new JSONResponse(['message' => 'Medewerker niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->hasBrpBasis((string)($employee['administrationId'] ?? '')) === false) {
			return new JSONResponse(
				['message' => 'Voor deze administratie is geen grondslag voor het gebruik van de BRP vastgelegd.', 'reason' => 'no-legal-basis'],
				Http::STATUS_FORBIDDEN
			);
		}

		return $this->fill(schema: 'Employee', objectId: $employeeId, lookup: fn (): array => $this->prefill->employee($employee));
	}//end employee()

	/**
	 * Run the lookup and save what it filled.
	 *
	 * @param string   $schema   The schema slug.
	 * @param string   $objectId The object id.
	 * @param callable $lookup   The lookup, answering `{filled, differs}`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	private function fill(string $schema, string $objectId, callable $lookup): JSONResponse {
		try {
			$result = $lookup();
		} catch (RegisterPrefillUnavailableException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_CONFLICT);
		}

		if ($result['filled'] !== []) {
			try {
				$this->gateway->save($result['filled'], $schema, $objectId);
			} catch (\Throwable $e) {
				return new JSONResponse(['message' => $e->getMessage(), 'reason' => 'refused'], Http::STATUS_CONFLICT);
			}
		}

		return new JSONResponse($result);
	}//end fill()

	/**
	 * Whether the caller is HR or an administrator.
	 *
	 * @return bool
	 */
	private function mayFill(): bool {
		return $this->roles->isHr($this->userSession->getUser()?->getUID()) === true;
	}//end mayFill()

	/**
	 * The refusal for anyone but HR or an administrator.
	 *
	 * @return JSONResponse
	 */
	private function refused(): JSONResponse {
		return new JSONResponse(['message' => 'Alleen HR of een beheerder kan een registratie uit een basisregister vullen.'], Http::STATUS_FORBIDDEN);
	}//end refused()

	/**
	 * Whether the administration records a legal basis for BRP use.
	 *
	 * @param string $administrationId The administration's business key.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
	 */
	private function hasBrpBasis(string $administrationId): bool {
		if ($administrationId === '') {
			return false;
		}

		$administration = ($this->gateway->findFiltered('hrAdministration', ['administrationId' => $administrationId])[0] ?? []);

		return trim((string)($administration['brpGrondslag'] ?? '')) !== '';
	}//end hasBrpBasis()

}//end class
