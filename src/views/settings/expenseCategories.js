// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The expense category switches on the admin settings page: what to send
 * when one switch changes, read and save through the expense-categories API.
 *
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */

/**
 * The categories that are on after one switch changes.
 *
 * @param {Array<{id: string, allowed: boolean}>} categories The current switches.
 * @param {string} id The category whose switch changed.
 * @param {boolean} on Whether it is now on.
 * @return {Array<string>} The categories that are on, in list order.
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */
export function allowedAfterToggle(categories, id, on) {
	return categories
		.filter((category) => (category.id === id ? on : category.allowed))
		.map((category) => category.id)
}

/**
 * Read the switches.
 *
 * @param {{get: Function}} http An axios-like client.
 * @param {string} url The API url.
 * @return {Promise<Array<{id: string, allowed: boolean}>>}
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */
export async function loadCategories(http, url) {
	const response = await http.get(url)
	return response.data?.categories ?? []
}

/**
 * Save the categories that are on; returns the switches as stored.
 *
 * @param {{put: Function}} http An axios-like client.
 * @param {string} url The API url.
 * @param {Array<string>} allowed The categories that are on.
 * @return {Promise<Array<{id: string, allowed: boolean}>>}
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */
export async function saveCategories(http, url, allowed) {
	const response = await http.put(url, { allowed })
	return response.data?.categories ?? []
}
