<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="hq-agenda" :data-surface="surface" data-testid="hq-agenda-widget">
		<!-- CHROME. A host places a mount-mode leaf into a bare element and hands
		     it no card, so the leaf draws its own header or it reads as loose
		     text between the cards that do have one. The shape is copied from
		     the hours leaf rather than invented, so two humaniq leaves on one
		     page do not look like two different products. -->
		<div class="hq-agenda__header">
			<span class="hq-agenda__icon" aria-hidden="true">
				<svg width="24" height="24" viewBox="0 0 24 24" focusable="false">
					<path
						d="M19 3h-1V1h-2v2H8V1H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2m0 16H5V9h14zm0-12H5V5h14z"
						fill="currentColor" />
				</svg>
			</span>
			<h3 class="hq-agenda__caption" data-testid="hq-agenda-caption">
				{{ t('humaniq', 'Agenda') }}
			</h3>
		</div>

		<p v-if="loading" class="hq-agenda__state" data-testid="hq-agenda-loading">
			{{ t('humaniq', 'Reading the agenda') }}
		</p>

		<!-- An error SAYS it is an error. An empty list drawn on a failed read is
		     the same picture as a free week, and a planner cannot tell them
		     apart. -->
		<p v-else-if="error" class="hq-agenda__state hq-agenda__state--error" data-testid="hq-agenda-error">
			{{ error }}
		</p>

		<p v-else-if="!subjectId" class="hq-agenda__state" data-testid="hq-agenda-no-subject">
			{{ t('humaniq', 'This record names no person, so there is no agenda to show.') }}
		</p>

		<p v-else-if="entries.length === 0" class="hq-agenda__state" data-testid="hq-agenda-empty">
			{{ t('humaniq', 'Nothing is planned in the coming week.') }}
		</p>

		<ul v-else class="hq-agenda__list" data-testid="hq-agenda-list">
			<li v-for="entry in entries" :key="entry.sourceType + entry.sourceId + entry.start" class="hq-agenda__item">
				<span class="hq-agenda__kind" :data-kind="entry.kind">{{ kindLabel(entry.kind) }}</span>
				<span class="hq-agenda__period">{{ period(entry) }}</span>
				<span class="hq-agenda__label">{{ entry.label }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { defaultWindow, handlerOf, readAgenda, readHostObject } from './agendaApi.js'

export default {
	name: 'CnAgendaWidget',

	props: {
		/** The host object's register slug. */
		register: { type: String, default: '' },
		/** The host object's schema slug. */
		schema: { type: String, default: '' },
		/** The host object's uuid. */
		objectId: { type: String, default: '' },
		/** Which surface the host mounted this leaf on. */
		surface: { type: String, default: 'detail-page' },
		/** An explicit subject, when the host already knows whose agenda to show. */
		subjectType: { type: String, default: 'employee' },
		/** An explicit subject id, which skips resolving one from the host object. */
		subject: { type: String, default: '' },
	},

	data() {
		return {
			loading: true,
			error: '',
			entries: [],
			subjectId: '',
		}
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Resolve the subject and read its week.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''

			try {
				this.subjectId = this.subject || handlerOf(
					await readHostObject(this.register, this.schema, this.objectId),
				) || ''

				if (!this.subjectId) {
					this.entries = []

					return
				}

				const window = defaultWindow()
				this.entries = await readAgenda(this.subjectType, this.subjectId, window.from, window.to)
			} catch {
				// Said, not swallowed: an empty agenda and a failed read must not
				// render as the same thing.
				this.error = this.t('humaniq', 'The agenda could not be read.')
				this.entries = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * The word a reader sees for an entry's kind.
		 *
		 * An absence says absent and never why: the boundary the server draws is
		 * repeated here so a future field cannot slip through the template.
		 *
		 * @param {string} kind The entry kind.
		 *
		 * @return {string} The label.
		 */
		kindLabel(kind) {
			const labels = {
				shift: this.t('humaniq', 'Shift'),
				leave: this.t('humaniq', 'Leave'),
				absent: this.t('humaniq', 'Absent'),
				interview: this.t('humaniq', 'Interview'),
				booking: this.t('humaniq', 'Booking'),
				busy: this.t('humaniq', 'Busy'),
			}

			return labels[kind] || kind
		},

		/**
		 * One entry's period, as a reader reads it.
		 *
		 * @param {object} entry The entry.
		 *
		 * @return {string} The period.
		 */
		period(entry) {
			const start = String(entry.start || '')
			const end = String(entry.end || '')
			const startDay = start.slice(0, 10)
			const endDay = end.slice(0, 10)

			if (startDay === endDay) {
				return `${startDay} ${start.slice(11, 16)} - ${end.slice(11, 16)}`.trim()
			}

			return `${startDay} - ${endDay}`
		},
	},
}
</script>

<style scoped>
.hq-agenda {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
}

.hq-agenda__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.hq-agenda__icon {
	color: var(--color-primary-element);
	display: inline-flex;
}

.hq-agenda__caption {
	font-weight: bold;
	margin: 0;
}

.hq-agenda__state {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.hq-agenda__state--error {
	color: var(--color-error);
}

.hq-agenda__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.hq-agenda__item {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
}

.hq-agenda__kind {
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 0 6px;
}

.hq-agenda__period {
	color: var(--color-text-maxcontrast);
}
</style>
