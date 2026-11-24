// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The agent name the HR question assistant carries in Hermiq; the same name
 * `OfferHrAssistantListener::NAME` offers its template under.
 */
export const HR_ASSISTANT_AGENT = 'HR-vraagbaak'

/**
 * Whether a route belongs to Mijn HR, the employee's self-service area: its
 * path is `/mijn` or below it, or its page id starts with `Mijn` (the
 * self-service pages that kept an English path, such as `/my-approvals`).
 *
 * @spec openspec/specs/hr-policy-assistant/spec.md#requirement-the-assistant-shall-answer-from-the-employers-published-policies-and-cite-them-req-hpa-001
 * @param {{path?: string, name?: string|symbol}|null|undefined} route The current route.
 * @return {boolean} True on a Mijn HR route.
 */
export function isMijnHrRoute(route) {
	if (!route) {
		return false
	}
	const path = typeof route.path === 'string' ? route.path : ''
	if (path === '/mijn' || path.startsWith('/mijn/')) {
		return true
	}
	return typeof route.name === 'string' && route.name.startsWith('Mijn')
}

/**
 * The agent the AI companion starts on for a route: the HR question assistant
 * on Mijn HR routes (design D3), otherwise none, so the library keeps its
 * page-relevance default.
 *
 * @spec openspec/specs/hr-policy-assistant/spec.md#requirement-the-assistant-shall-answer-from-the-employers-published-policies-and-cite-them-req-hpa-001
 * @param {{path?: string, name?: string|symbol}|null|undefined} route The current route.
 * @return {string} The agent name, or '' for the library default.
 */
export function defaultAgentFor(route) {
	return isMijnHrRoute(route) ? HR_ASSISTANT_AGENT : ''
}
