<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 DevicePushWidget (self-service-mobile design.md D5, REQ-MOB-004).

 The "Notifications on this device" setting on Mijn HR. Opening the page
 only reads whether this device is subscribed; the browser's permission
 prompt comes from flipping the switch, never from loading. The
 subscription is registered with openregister, which owns the web-push
 channel; which notifications use it is set in the notification rules.
-->
<template>
	<CnWidgetWrapper :title="title" :widgetId="widgetId" :documentationUrl="documentationUrl">
		<div class="device-push">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="state === 'on'"
				:disabled="busy || !switchable"
				data-testid="device-push-switch"
				@update:modelValue="toggle">
				{{ t('humaniq', 'Notifications on this device') }}
			</NcCheckboxRadioSwitch>
			<p class="device-push__hint">
				{{ hint }}
			</p>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { browserEnv, currentState, turnOff, turnOn } from '../pwa/pushOptIn.js'

export default {
	name: 'DevicePushWidget',

	components: { CnWidgetWrapper, NcCheckboxRadioSwitch },

	props: {
		/** Widget title, shown in the wrapper header. */
		title: { type: String, default: '' },
		/** Widget id, merged in by CnWidgetGrid. */
		widgetId: { type: String, default: '' },
		/** Optional docs link in the wrapper's menu. */
		documentationUrl: { type: String, default: '' },
	},

	data() {
		return {
			state: 'off',
			busy: true,
		}
	},

	computed: {
		/**
		 * Whether the switch can do anything on this device.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-notifications-shall-reach-the-installed-app-only-after-the-employee-turns-them-on-req-mob-004
		 */
		switchable() {
			return this.state === 'on' || this.state === 'off'
		},

		/**
		 * The line under the switch, saying what it does or why it cannot.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-notifications-shall-reach-the-installed-app-only-after-the-employee-turns-them-on-req-mob-004
		 */
		hint() {
			switch (this.state) {
			case 'blocked':
				return this.t('humaniq', 'Your browser blocks notifications for this site. Allow them in the browser settings first.')
			case 'needs-install':
				return this.t('humaniq', 'On an iPhone or iPad, add My HR to the home screen first, then turn notifications on from there.')
			case 'unsupported':
				return this.t('humaniq', 'This browser cannot receive notifications.')
			case 'unavailable':
				return this.t('humaniq', 'Notifications to devices are not set up on this server.')
			default:
				return this.t('humaniq', 'Decisions on your requests reach this device, also when the app is closed.')
			}
		},
	},

	async mounted() {
		try {
			this.state = await currentState(browserEnv())
		} catch {
			this.state = 'unsupported'
		} finally {
			this.busy = false
		}
	},

	methods: {
		/**
		 * Turn notifications on or off, from the switch (a user gesture).
		 *
		 * @param {boolean} on Whether the employee turned the switch on.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-notifications-shall-reach-the-installed-app-only-after-the-employee-turns-them-on-req-mob-004
		 */
		async toggle(on) {
			this.busy = true
			try {
				this.state = on
					? await turnOn(browserEnv(), axios, generateUrl)
					: await turnOff(browserEnv(), axios, generateUrl)
			} catch {
				showError(this.t('humaniq', 'The notification setting could not be changed.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.device-push__hint {
	margin-top: calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
}
</style>
