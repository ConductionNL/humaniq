/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The host contract of the hours leaf (REQ-HLD-003): a consuming page passes
 * the host object's register slug, schema slug and uuid. With any of the three
 * missing the leaf has no object to read or book hours against, and it says so
 * instead of drawing a total, because "0 hours" and "no host" look the same to
 * a caseworker and only one of them is a fact.
 *
 * Plain module, no Vue, so `node --test` runs it.
 *
 * @spec openspec/changes/hours-leaf-loads-on-a-consuming-page/specs/hours-leaf-delivery/spec.md#requirement-the-leaf-states-what-a-host-page-must-pass-req-hld-003
 */

/** The props a host must pass, in the order a reader would name them. */
export const HOST_PROPS = ['register', 'schema', 'objectId']

/**
 * The host props a mount left empty.
 *
 * @param {{register?: unknown, schema?: unknown, objectId?: unknown}} host The props the host passed.
 * @return {Array<string>} The missing prop names, in HOST_PROPS order.
 *
 * @spec openspec/changes/hours-leaf-loads-on-a-consuming-page/specs/hours-leaf-delivery/spec.md#requirement-the-leaf-states-what-a-host-page-must-pass-req-hld-003
 */
export function missingHostProps(host) {
	return HOST_PROPS.filter((name) => {
		const value = host?.[name]
		return typeof value !== 'string' || value.trim() === ''
	})
}

/**
 * Whether the host named an object the leaf can read and book against.
 *
 * @param {{register?: unknown, schema?: unknown, objectId?: unknown}} host The props the host passed.
 * @return {boolean} True when all three host props are present.
 *
 * @spec openspec/changes/hours-leaf-loads-on-a-consuming-page/specs/hours-leaf-delivery/spec.md#requirement-the-leaf-states-what-a-host-page-must-pass-req-hld-003
 */
export function isResolvableHost(host) {
	return missingHostProps(host).length === 0
}
