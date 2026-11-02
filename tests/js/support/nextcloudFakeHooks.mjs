/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Node module hooks that resolve `@nextcloud/axios`, `@nextcloud/dialogs` and
 * `@nextcloud/router` to nextcloudFakes.mjs when a module under src/ imports
 * them, so a test can import a dialog module without a bundler or a Nextcloud
 * page. Imports from node_modules keep the real packages.
 *
 * @spec exclude test support, not product behaviour
 */

const SRC = /\/src\//
const FAKED = ['@nextcloud/axios', '@nextcloud/dialogs', '@nextcloud/router']

/**
 * Send the faked specifiers imported from src/ to the fake; pass the rest on.
 *
 * @param {string} specifier The import specifier.
 * @param {object} context The resolver context.
 * @param {Function} nextResolve The next resolve hook in the chain.
 * @return {Promise<object>} The resolve result.
 */
export async function resolve(specifier, context, nextResolve) {
	if (FAKED.includes(specifier) && SRC.test(context.parentURL || '')) {
		return { url: new URL('./nextcloudFakes.mjs', import.meta.url).href, shortCircuit: true }
	}
	return nextResolve(specifier, context)
}
