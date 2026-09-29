<?php

/**
 * Route Distance Service
 *
 * Asks integriq for the road distance between two Dutch postcodes
 * (expenses-travel-calculation D3). The external route planner API and its
 * credentials belong to integriq (ADR-091); humaniq only names the integriq
 * source to call, through app config `route_distance_source` (a source
 * uuid) and `route_distance_endpoint` (the path on it). The source is called
 * with `GET <endpoint>?from=<postcode>&to=<postcode>` and must answer JSON
 * carrying `distanceKm`, which an integriq mapping can shape from any
 * planner's response. Integriq is resolved under either of its app ids and
 * namespaces through FleetAppId; without it, without a source, or without a
 * distance in the answer, the lookup is refused with a reason and the typed
 * distance stays.
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * The road distance between two postcodes, from integriq.
 *
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
 */
class RouteDistanceService {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container  Resolves integriq's services.
	 * @param IAppManager        $appManager Tells whether integriq is installed.
	 * @param SettingsService    $settings   The configured source.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * The road distance from one postcode to another.
	 *
	 * @param string $originPostcode      Where the trip starts.
	 * @param string $destinationPostcode Where it ends.
	 *
	 * @return array{distanceKm: float, provider: string}
	 *
	 * @throws RouteDistanceUnavailableException When no distance can be obtained.
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is the fleet's static
	 *  resolver for renamed apps; there is no instance to inject.
	 */
	public function lookup(string $originPostcode, string $destinationPostcode): array {
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'integriq') === false) {
			throw new RouteDistanceUnavailableException('Er is geen routeplanner beschikbaar: integriq is niet geïnstalleerd. Vul de afstand zelf in.');
		}

		$config = $this->settings->getRouteDistanceSource();
		$environment = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: 'Service\EnvironmentService');
		$caller = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: 'Service\CallService');
		$source = null;
		if ($config['source'] !== '' && $environment !== null && $caller !== null) {
			$source = $environment->resolveSource($config['source']);
		}

		if ($source === null || $caller === null) {
			throw new RouteDistanceUnavailableException('Er is geen routeplanner ingesteld. Vul de afstand zelf in, of vraag de beheerder een routeplanner te koppelen.');
		}

		$log = $caller->call(
			source: $source,
			endpoint: $config['endpoint'],
			method: 'GET',
			config: ['query' => ['from' => $this->normalise($originPostcode), 'to' => $this->normalise($destinationPostcode)]]
		);

		return ['distanceKm' => $this->distanceFrom($log), 'provider' => $this->providerOf($source)];
	}//end lookup()

	/**
	 * The distance in a CallLog's response.
	 *
	 * @param object $log The CallLog integriq returned.
	 *
	 * @return float
	 *
	 * @throws RouteDistanceUnavailableException When the answer carries no distance.
	 */
	private function distanceFrom(object $log): float {
		$data = method_exists($log, 'getObject') === true ? ($log->getObject() ?? []) : [];
		$response = (is_array($data['response'] ?? null) === true ? $data['response'] : []);
		$decoded = json_decode((string)($response['body'] ?? ''), true);
		$distance = (is_array($decoded) === true ? ($decoded['distanceKm'] ?? null) : null);
		if ((int)($response['statusCode'] ?? 0) >= 400 || is_numeric($distance) === false || (float)$distance <= 0.0) {
			throw new RouteDistanceUnavailableException('De routeplanner gaf geen afstand terug. Vul de afstand zelf in.');
		}

		return round((float)$distance, 1);
	}//end distanceFrom()

	/**
	 * The name of the source that answered.
	 *
	 * @param object $source The integriq source.
	 *
	 * @return string
	 */
	private function providerOf(object $source): string {
		$data = method_exists($source, 'getObject') === true ? ($source->getObject() ?? []) : [];

		return (string)($data['name'] ?? $data['slug'] ?? 'routeplanner');
	}//end providerOf()

	/**
	 * A postcode without spaces, upper case.
	 *
	 * @param string $postcode The postcode as typed.
	 *
	 * @return string
	 */
	private function normalise(string $postcode): string {
		return strtoupper(str_replace(' ', '', trim($postcode)));
	}//end normalise()

}//end class
