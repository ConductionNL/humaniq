<?php

/**
 * Golden test for every portal contribution manifest.
 *
 * The manifests declare what each external audience may read and submit:
 * the schemas, the scope field and claim, the minimum trust level, anonymous
 * access, filters, field projections and create/update whitelists. This test
 * pins every audience's full manifest, key for key and in order, against
 * `tests/fixtures/golden/portal-manifests.json`, and pins that an unknown or
 * missing audience gets no manifest at all.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Portal
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
 * @spec openspec/changes/portal-contribution/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Portal;

use OCA\Humaniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every audience's manifest stays exactly as pinned.
 *
 * @spec openspec/changes/portal-contribution/tasks.md#task-3
 */
class PortalManifestGoldenTest extends TestCase {

	/**
	 * @return void
	 */
	public function testEveryAudienceManifestMatchesTheGolden(): void {
		$golden   = json_decode((string)file_get_contents(__DIR__.'/../../fixtures/golden/portal-manifests.json'), true, 512, JSON_THROW_ON_ERROR);
		$provider = new PortalContributionProvider();

		$actual = [];
		foreach (array_merge($provider->getAudiences(), ['staff', '']) as $audience) {
			$actual[$audience === '' ? '(none)' : $audience] = $provider->getContribution(['audience' => $audience]);
		}

		$this->assertSame($golden, $actual);
		$this->assertNull($provider->getContribution([]));

	}//end testEveryAudienceManifestMatchesTheGolden()
}//end class
