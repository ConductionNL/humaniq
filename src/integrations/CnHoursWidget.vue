<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="hq-hours-widget">
		<div class="hq-hours-widget__figure">
			<span class="hq-hours-widget__value">{{ displayHours }}</span>
			<span class="hq-hours-widget__unit">{{ t('humaniq', 'hours') }}</span>
		</div>

		<p v-if="error" class="hq-hours-widget__error">
			{{ error }}
		</p>
		<p v-else-if="entries.length === 0 && !loading" class="hq-hours-widget__empty">
			{{ t('humaniq', 'No hours booked on this yet') }}
		</p>
		<ul v-else-if="visibleEntries.length > 0" class="hq-hours-widget__list">
			<li v-for="entry in visibleEntries" :key="entry.id" class="hq-hours-widget__row">
				<span class="hq-hours-widget__row-hours">{{ formatHours(entry.hours) }}</span>
				<span class="hq-hours-widget__row-desc">{{ entry.description || t('humaniq', 'No description') }}</span>
				<span class="hq-hours-widget__row-date">{{ formatDate(entry.startedAt) }}</span>
			</li>
		</ul>

		<div class="hq-hours-widget__actions">
			<button type="button" class="hq-hours-widget__action" @click="openLogHours">
				{{ t('humaniq', 'Log hours') }}
			</button>
			<button
				type="button"
				class="hq-hours-widget__action"
				:class="{ 'hq-hours-widget__action--running': running }"
				@click="toggleTimer">
				{{ running ? t('humaniq', 'Stop timer') : t('humaniq', 'Start timer') }}
			</button>
		</div>
	</div>
</template>

<script>
/**
 * CnHoursWidget — hours booked against ANY object, and the two ways to add more.
 *
 * humaniq owns hours (ADR-107 decision 6: "hours logged on a case are humaniq
 * time entries carrying the case reference"), so humaniq renders them. The
 * consuming app places this leaf and passes the object context; it does not
 * query humaniq's register itself.
 *
 * That indirection is the point. dossiq used to aggregate `humaniq/TimeEntry`
 * from its own manifest, which meant that on an install without humaniq the
 * request 404'd and the tile rendered `0` — indistinguishable from a real zero
 * (ADR-113). A leaf cannot render at all when its app is absent, so the failure
 * mode disappears rather than being handled.
 *
 * The bound object is identified the way humaniq stores it: `domainObjectType`
 * is the `<app>:<schema>` literal (`dossiq:case`) and `domainObjectRef` is the
 * object's uuid. Both are written by integrations rather than typed by an
 * employee, which is why neither appears in any `includeFields` allowlist.
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'CnHoursWidget',

	props: {
		/** OpenRegister register slug of the HOST object (not humaniq's). */
		register: {
			type: String,
			default: '',
		},

		/** OpenRegister schema slug of the host object. */
		schema: {
			type: String,
			default: '',
		},

		/** The host object's uuid — what `domainObjectRef` points at. */
		objectId: {
			type: String,
			default: '',
		},

		/** The render surface the host mounted us into. */
		surface: {
			type: String,
			default: 'detail-page',
		},

		/** How many entries to list under the total. */
		limit: {
			type: Number,
			default: 5,
		},
	},

	data() {
		return {
			entries: [],
			loading: false,
			error: '',
			running: false,
		}
	},

	computed: {
		/**
		 * The summed hours, or a dash while unknown.
		 *
		 * A dash rather than 0 on failure, deliberately: a zero that means "could
		 * not read" is the defect this whole leaf exists to remove.
		 *
		 * @return {string} The total, or '—'.
		 */
		displayHours() {
			if (this.error !== '' || (this.loading && this.entries.length === 0)) {
				return '—'
			}
			const total = this.entries.reduce((sum, e) => sum + (Number(e.hours) || 0), 0)
			return this.formatHours(total)
		},

		/**
		 * The entries to list under the total.
		 *
		 * A dashboard tile is a headline figure with room for barely a line, so
		 * it lists none; a detail page or a sidebar has room for the recent
		 * bookings that explain the total. This is what `surface` is for — the
		 * host tells the leaf how much room it has, and the leaf decides.
		 *
		 * @return {object[]} The entries to render.
		 */
		visibleEntries() {
			const compact = ['user-dashboard', 'app-dashboard'].includes(this.surface)
			return compact ? [] : this.entries.slice(0, this.limit)
		},

		/**
		 * The `<app>:<schema>` literal humaniq stores for the host object.
		 *
		 * @return {string} e.g. `dossiq:case`.
		 */
		domainObjectType() {
			return this.register && this.schema ? `${this.register}:${this.schema}` : ''
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read this object's time entries from OpenRegister.
		 *
		 * @return {Promise<void>} Resolves when the list has settled.
		 */
		async load() {
			if (this.objectId === '' || this.domainObjectType === '') {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/openregister/api/objects/humaniq/TimeEntry')
				const { data } = await axios.get(url, {
					params: {
						'filter[domainObjectType]': this.domainObjectType,
						'filter[domainObjectRef]': this.objectId,
						_limit: 100,
					},
				})
				this.entries = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
			} catch {
				// Say so rather than render 0. See the component docblock.
				this.error = t('humaniq', 'Could not read the hours for this object')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open humaniq's hour-booking form, seeded with this object.
		 *
		 * @return {void}
		 */
		openLogHours() {
			const url = generateUrl('/apps/humaniq/timesheets?domainObjectType={type}&domainObjectRef={ref}', {
				type: this.domainObjectType,
				ref: this.objectId,
			})
			window.open(url, '_self')
		},

		/**
		 * Start or stop a running timer against this object.
		 *
		 * @return {Promise<void>} Resolves when the entry has been written.
		 */
		async toggleTimer() {
			try {
				const url = generateUrl('/apps/humaniq/api/time-entries/timer')
				await axios.post(url, {
					action: this.running ? 'stop' : 'start',
					domainObjectType: this.domainObjectType,
					domainObjectRef: this.objectId,
				})
				this.running = !this.running
				if (this.running === false) {
					await this.load()
				}
			} catch {
				this.error = t('humaniq', 'Could not start or stop the timer')
			}
		},

		/**
		 * Format an hours figure to at most two decimals, without trailing zeroes.
		 *
		 * @param {number} value The hours.
		 * @return {string} The formatted figure.
		 */
		formatHours(value) {
			const n = Number(value) || 0
			return String(Math.round(n * 100) / 100)
		},

		/**
		 * Format a booking's start as a short local date.
		 *
		 * @param {string} value ISO timestamp.
		 * @return {string} The formatted date, or ''.
		 */
		formatDate(value) {
			if (!value) {
				return ''
			}
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.hq-hours-widget {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.hq-hours-widget__figure {
	align-items: baseline;
	display: flex;
	gap: 6px;
}

.hq-hours-widget__value {
	color: var(--color-main-text);
	font-size: 28px;
	font-weight: bold;
	line-height: 1.1;
}

.hq-hours-widget__unit,
.hq-hours-widget__empty,
.hq-hours-widget__row-date {
	color: var(--color-text-maxcontrast);
}

.hq-hours-widget__error {
	color: var(--color-error);
}

.hq-hours-widget__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.hq-hours-widget__row {
	display: flex;
	gap: 8px;
	justify-content: space-between;
}

.hq-hours-widget__row-desc {
	flex: 1 1 auto;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.hq-hours-widget__row-hours {
	font-weight: bold;
	min-width: 3em;
}

.hq-hours-widget__actions {
	display: flex;
	gap: 8px;
}

.hq-hours-widget__action {
	background: transparent;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	padding: 4px 10px;
}

.hq-hours-widget__action:hover,
.hq-hours-widget__action:focus-visible {
	background-color: var(--color-background-hover);
}

.hq-hours-widget__action--running {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}
</style>
