/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page's Add integration handler.
 *
 * The rows on that page are integriq's `app_connection` objects (hydra
 * connection-registry, design D8). Add integration leaves this app for
 * integriq's Connections overview, preset to humaniq and opening the
 * link-a-source dialog (D9). It is a handler, not a header action `navigate`,
 * because `navigate` only pushes a route inside this app's router.
 *
 * The page's two formatters, `connectionStatus` and `connectionSettingsLabel`,
 * are built-ins of @conduction/nextcloud-vue, so this app carries no copy.
 *
 * @spec openspec/changes/platform-integrations-catalogue/specs/humaniq-integrations/spec.md#requirement-an-administrator-shall-add-an-integration-from-humaniq-req-hin-002
 */

import { generateUrl as ncGenerateUrl } from '@nextcloud/router'

/**
 * Where Add integration lands: integriq's Connections overview, preset to
 * humaniq with the link dialog open.
 */
export const INTEGRIQ_CONNECTIONS_PATH = '/apps/integriq/connections?app=humaniq&link=1'

/**
 * Build the Add integration handler.
 *
 * @param {{generateUrl: function(string): string, assign: function(string): void}} deps Builds the instance URL and navigates to it.
 * @return {{openIntegriqConnections: function(): void}} The handler, keyed by its manifest name.
 * @spec openspec/changes/platform-integrations-catalogue/specs/humaniq-integrations/spec.md#requirement-an-administrator-shall-add-an-integration-from-humaniq-req-hin-002
 */
export function createConnectionHandlers({ generateUrl, assign }) {
	return {
		/**
		 * Leave for integriq's Connections overview, preset and linking.
		 *
		 * @return {void}
		 * @spec openspec/changes/platform-integrations-catalogue/specs/humaniq-integrations/spec.md#requirement-an-administrator-shall-add-an-integration-from-humaniq-req-hin-002
		 */
		openIntegriqConnections() {
			assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
		},
	}
}

/**
 * The handler as the registry hands it to the page, bound to the real
 * Nextcloud router and the browser location.
 *
 * @return {void}
 * @spec openspec/changes/platform-integrations-catalogue/specs/humaniq-integrations/spec.md#requirement-an-administrator-shall-add-an-integration-from-humaniq-req-hin-002
 */
export function openIntegriqConnections() {
	createConnectionHandlers({
		generateUrl: ncGenerateUrl,
		assign: (url) => window.location.assign(url),
	}).openIntegriqConnections()
}
