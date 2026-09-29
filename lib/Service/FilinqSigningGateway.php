<?php

/**
 * Filinq Signing Gateway
 *
 * The one place humaniq talks to filinq's `SigningService`
 * (people-esign-hr-documents D1), shared by the offer letter path
 * (`OfferEsignService`) and the HR document path
 * (`HrDocumentSigningService`). It resolves the service duck-typed through
 * FleetAppId, raises a request and turns filinq's session guard
 * (`RuntimeException('No authenticated user')` from an occ process) into a
 * failed outcome instead of an exception, recovers a request row that
 * filinq wrote before a signer write failed (keyed on `correlationId` and
 * `documentFileId` together, only on exactly one match), cancels fail-soft,
 * and reads a request's status through `getRequest()`, which needs no
 * session.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raise, cancel and read filinq signing requests.
 *
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
 */
class FilinqSigningGateway {

	/**
	 * The app id filinq registers under.
	 *
	 * @var string
	 */
	private const DOCUMENT_APP = 'filinq';

	/**
	 * filinq's signing service, by relative class name.
	 *
	 * @var string
	 */
	private const SIGNING_SERVICE_CLASS = 'Service\SigningService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface          $container  Resolves filinq's service.
	 * @param IAppManager                 $appManager Tells whether filinq is installed.
	 * @param OfferSigningRecoveryService $recovery   The orphaned request lookup.
	 * @param LoggerInterface             $logger     Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly OfferSigningRecoveryService $recovery,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether filinq is installed and its signing service resolves.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is the fleet's static resolver for renamed apps.
	 */
	public function available(): bool {
		if (FleetAppId::isInstalled($this->appManager, self::DOCUMENT_APP) === false) {
			return false;
		}

		try {
			$this->signingService();
		} catch (\Throwable $e) {
			return false;
		}

		return true;
	}//end available()

	/**
	 * Raise a signing request; never throws.
	 *
	 * @param array<string, mixed> $requestData The createRequest() payload, with `correlationId` and `documentFileId`.
	 *
	 * @return array{requestId: string|null, status: string, error: string|null}
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
	 */
	public function create(array $requestData): array {
		$signing = $this->signingService();
		try {
			$created = $signing->createRequest($requestData);
		} catch (\Throwable $e) {
			$recovered = $this->recovery->recoverOrphanedRequestId(
				$signing,
				(string)($requestData['correlationId'] ?? ''),
				(int)($requestData['documentFileId'] ?? 0)
			);

			return ['requestId' => $recovered, 'status' => 'failed', 'error' => $e->getMessage()];
		}

		$requestId = (string)($created['id'] ?? $created['uuid'] ?? '');

		return [
			'requestId' => ($requestId === '' ? null : $requestId),
			'status' => (string)($created['status'] ?? 'PENDING'),
			'error' => null,
		];
	}//end create()

	/**
	 * Cancel a request, fail-soft: a failure is logged and never blocks.
	 *
	 * @param string $requestId The request id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
	 */
	public function cancel(string $requestId): void {
		try {
			$this->signingService()->cancelRequest($requestId);
		} catch (\Throwable $e) {
			$this->logger->info('FilinqSigningGateway: kon signing-request ' . $requestId . ' niet annuleren (wordt genegeerd): ' . $e->getMessage());
		}
	}//end cancel()

	/**
	 * Read a request; exceptions from filinq are passed on to the caller.
	 *
	 * @param string $requestId The request id.
	 *
	 * @return array<string, mixed>|null The request, or null when it is not accessible.
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
	 */
	public function read(string $requestId): ?array {
		$request = $this->signingService()->getRequest($requestId);

		return (is_array($request) === true ? $request : null);
	}//end read()

	/**
	 * filinq's SigningService.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When no namespace resolves.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is the fleet's static resolver for renamed apps.
	 */
	private function signingService(): mixed {
		return FleetAppId::getService($this->container, self::DOCUMENT_APP, self::SIGNING_SERVICE_CLASS)
			?? throw new RuntimeException('filinq service ' . self::SIGNING_SERVICE_CLASS . ' is not available under any known namespace.');
	}//end signingService()

}//end class
