/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Node module hooks that let a test import a leaf module without a bundler:
 * every `.vue` import loads as an empty named component, and `vue` resolves to
 * vueFake.mjs, which records what the leaf does with `createApp`.
 *
 * @spec exclude test support, not product behaviour
 */

/**
 * Send the bare `vue` specifier to the fake; pass the rest on.
 *
 * @param {string} specifier The import specifier.
 * @param {object} context The resolver context.
 * @param {Function} nextResolve The next resolve hook in the chain.
 * @return {Promise<object>} The resolve result.
 */
export async function resolve(specifier, context, nextResolve) {
	if (specifier === 'vue') {
		return { url: new URL('./vueFake.mjs', import.meta.url).href, shortCircuit: true }
	}
	return nextResolve(specifier, context)
}

/**
 * Answer a `.vue` URL with a stub component named after the file.
 *
 * @param {string} url The resolved module URL.
 * @param {object} context The loader context.
 * @param {Function} nextLoad The next load hook in the chain.
 * @return {Promise<object>} The load result.
 */
export async function load(url, context, nextLoad) {
	if (url.endsWith('.vue')) {
		const name = url.slice(url.lastIndexOf('/') + 1, -4)
		return { format: 'module', source: `export default { name: '${name}' }`, shortCircuit: true }
	}
	return nextLoad(url, context)
}
