<?php

/**
 * Register Lookup Gateway
 *
 * Calls a base register through integriq (people-register-prefill D1). The
 * register's API and any credential belong to integriq and OpenRegister's
 * credential broker; humaniq only names the integriq source per register,
 * through app config `prefill_rdw_source` and `prefill_brp_source` (a source
 * uuid). Integriq is resolved under either of its app ids through
 * FleetAppId; without it the lookup answers `skipped-no-integriq`, the
 * duck-typed degradation humaniq uses for its other sibling apps.
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
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * One call to a base register's integriq source.
 *
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */
class RegisterLookupGateway {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container  Resolves integriq's services.
	 * @param IAppManager        $appManager Tells whether integriq is installed.
	 * @param SettingsService    $settings   The configured source per register.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * Call a register's source and answer the decoded JSON body.
	 *
	 * @param RegisterCall $call The register, the path, the verb, the request config and whether a CallLog is kept.
	 *
	 * @return array<mixed>
	 *
	 * @throws RegisterPrefillUnavailableException When there is no answer to read.
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)         FleetAppId is the fleet's static resolver for renamed apps.
	 */
	public function call(RegisterCall $call): array {
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'integriq') === false) {
			throw new RegisterPrefillUnavailableException('Integriq is niet geïnstalleerd, dus het register kan niet worden geraadpleegd.', 'skipped-no-integriq');
		}

		$sourceRef = $this->settings->getRegisterPrefillSource($call->register);
		$environment = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: 'Service\EnvironmentService');
		$caller = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: 'Service\CallService');
		$source = $this->sourceFor(sourceRef: $sourceRef, environment: $environment);
		if ($source === null || $caller === null) {
			throw new RegisterPrefillUnavailableException('Er is geen koppeling met dit register ingesteld. Vraag de beheerder de bron in integriq te koppelen.', 'no-source');
		}

		$log = $caller->call(source: $source, endpoint: $call->endpoint, method: $call->method, config: $call->config, persistLog: $call->persistLog);

		return $this->bodyOf($log);
	}//end call()

	/**
	 * The configured integriq source, or null when none is set or integriq cannot resolve one.
	 *
	 * @param string      $sourceRef   The configured source uuid, or ''.
	 * @param object|null $environment Integriq's EnvironmentService, or null.
	 *
	 * @return mixed
	 */
	private function sourceFor(string $sourceRef, ?object $environment): mixed {
		if ($sourceRef !== '' && $environment !== null) {
			return $environment->resolveSource($sourceRef);
		}

		return null;
	}//end sourceFor()

	/**
	 * The decoded body of a CallLog's response.
	 *
	 * @param object $log The CallLog integriq returned.
	 *
	 * @return array<mixed>
	 *
	 * @throws RegisterPrefillUnavailableException When the call failed or the body is no JSON.
	 */
	private function bodyOf(object $log): array {
		$data = method_exists($log, 'getObject') === true ? ($log->getObject() ?? []) : [];
		$response = (is_array($data['response'] ?? null) === true ? $data['response'] : []);
		$decoded = json_decode((string)($response['body'] ?? ''), true);
		if ((int)($response['statusCode'] ?? 0) >= 400 || is_array($decoded) === false) {
			throw new RegisterPrefillUnavailableException('Het register gaf geen bruikbaar antwoord. Probeer het later opnieuw.', 'error');
		}

		return $decoded;
	}//end bodyOf()

}//end class
