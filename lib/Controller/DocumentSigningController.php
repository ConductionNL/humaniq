<?php

/**
 * Document Signing Controller
 *
 * `POST /api/documents/{id}/request-signature` sends a generated HR document
 * for electronic signature (people-esign-hr-documents D4). The document is
 * read under the caller's own rights first (404 when they may not read it),
 * then only HR or an administrator may send it. It runs in the caller's
 * session, which filinq requires to raise a request. Its own controller
 * rather than DocumentController, which sits at the coupling limit.
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
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HrDocumentSigningService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Send a generated HR document for signature.
 *
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
 */
class DocumentSigningController extends Controller {

	/**
	 * HTTP status per outcome.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = [
		'requested' => Http::STATUS_OK,
		'existing' => Http::STATUS_OK,
		'already-signed' => Http::STATUS_OK,
		'refused' => Http::STATUS_BAD_REQUEST,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request     The request.
	 * @param HrDocumentSigningService $signing     Raises the request.
	 * @param RbacObjectReader         $rbac        Reads under the caller's own rights.
	 * @param HumaniqRoles             $roles       The HR check.
	 * @param IUserSession             $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly HrDocumentSigningService $signing,
		private readonly RbacObjectReader $rbac,
		private readonly HumaniqRoles $roles,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Send one generated document for signature.
	 *
	 * @param string $id The HrGeneratedDocument id.
	 *
	 * @return JSONResponse The outcome; 404, 403, 400 or 409 otherwise.
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-002
	 */
	#[NoAdminRequired]
	public function requestSignature(string $id): JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		$document = $this->rbac->findOrNull(id: $id, schema: 'HrGeneratedDocument');
		if ($document === null) {
			return new JSONResponse(['message' => 'Document niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->roles->isHr($uid) === false) {
			return new JSONResponse(['message' => 'Alleen HR of een beheerder kan een document laten ondertekenen.'], Http::STATUS_FORBIDDEN);
		}

		$outcome = $this->signing->requestSignature(array_merge($document, ['id' => $id]), $uid);

		return new JSONResponse($outcome, (self::STATUS[$outcome['status']] ?? Http::STATUS_CONFLICT));
	}//end requestSignature()

}//end class
