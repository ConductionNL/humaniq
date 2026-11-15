/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A stand-in for `vue` that records every app a test creates. Each app logs
 * its createApp, mount and unmount calls to `vueFake.events`; mounting an
 * element whose `failMount` is true throws.
 *
 * @spec exclude test support, not product behaviour
 */

export const vueFake = { events: [], apps: [] }

/**
 * Record a created app and return a fake with mount, unmount and config.
 *
 * @param {object} component The root component.
 * @param {object} props The root props.
 * @return {object} The fake app.
 */
export function createApp(component, props) {
	const index = vueFake.apps.length
	const app = {
		component,
		props,
		config: { globalProperties: {} },
		mount(el) {
			vueFake.events.push({ event: 'mount', app: index, el: el.name, globalT: typeof app.config.globalProperties.t })
			if (el.failMount === true) {
				throw new Error('mount failed')
			}
		},
		unmount() {
			vueFake.events.push({ event: 'unmount', app: index })
		},
	}
	vueFake.apps.push(app)
	vueFake.events.push({ event: 'createApp', app: index, component: component.name, props: { ...props } })
	return app
}
