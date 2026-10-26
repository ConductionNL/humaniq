<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="hq-hours"
		:class="{ 'hq-hours--compact': compactHeader }"
		:data-surface="surface"
		data-testid="hq-hours-widget">
		<!-- ON A HOST'S ROW the leaf is one "Hours" field beside the host's note
		     (dossiq "Close out your day"), not the card: a row has no room for a
		     header, figures and menus. What is typed is booked when the host
		     records that row's note, so one press records both. -->
		<label v-if="isRow" class="hq-hours__row" data-testid="hq-hours-row">
			<span class="hq-hours__row-label">{{ t('humaniq', 'Hours') }}</span>
			<input
				v-model="rowHours"
				class="hq-hours__row-input"
				type="text"
				inputmode="decimal"
				:disabled="saving"
				:aria-invalid="String(rowHours !== '' && rowHoursValue === null)"
				data-testid="hq-hours-row-input">
			<span v-if="error" class="hq-hours__row-error" role="alert">{{ error }}</span>
		</label>
		<template v-else>
		<!-- CHROME. A host places a mount-mode leaf into a bare element and hands
		     it no card, so the leaf draws its own or it reads as loose text
		     sitting on the page between the cards that do have one. -->
		<!-- The header is drawn the way the host draws every other card's:
		     a coloured icon and a bold title on the left, the controls on the
		     right, one rule beneath. The host cannot draw it for a mount-mode
		     leaf, so the leaf copies the shape rather than inventing one, or
		     this card is the one on the page whose caption is grey and whose
		     header has no rule. -->
		<div class="hq-hours__header">
			<div class="hq-hours__header-left">
				<span v-if="!compactHeader" class="hq-hours__icon" aria-hidden="true">
					<svg width="24" height="24" viewBox="0 0 24 24" focusable="false">
						<path
							d="M12 20a8 8 0 1 0 0-16 8 8 0 0 0 0 16m0-18a10 10 0 1 1 0 20 10 10 0 0 1 0-20m.5 5v5.25l4.5 2.67-.75 1.23L11 13V7z"
							fill="currentColor" />
					</svg>
				</span>
				<!-- The leaf names itself for the same reason: the host hands it no
				     title either, and a KPI that is only a number does not say what
				     was counted. -->
				<h3 class="hq-hours__caption" data-testid="hq-hours-caption">
					{{ caption }}
				</h3>
			</div>

			<div class="hq-hours__controls">
				<span v-if="weekLine !== ''" class="hq-hours__week" data-testid="hq-hours-week">{{ weekLine }}</span>
				<!-- The stopwatch is its own control, left of the actions, because
				     it is the one-press shortcut for work happening right now.
				     Putting it inside the menu would cost two presses for the
				     thing that has to be instant. -->
				<button
					v-if="!compactHeader"
					type="button"
					class="hq-hours__timer"
					:class="{ 'hq-hours__timer--running': runningHere }"
					:disabled="busy || !canUseTimer"
					:aria-busy="String(busy)"
					:title="timerTitle"
					:aria-label="timerTitle"
					data-testid="hq-hours-timer"
					@click="toggleTimer">
					<!-- Starting or stopping writes a row before anything changes on
					     the card, and that write took long enough that a press looked
					     ignored. The spinner stands in for the icon until the server
					     has answered. -->
					<svg
						v-if="busy"
						class="hq-hours__spinner"
						width="18"
						height="18"
						viewBox="0 0 24 24"
						aria-hidden="true"
						focusable="false"
						data-testid="hq-hours-timer-busy">
						<circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="32 18" />
					</svg>
					<svg v-else width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<template v-if="runningHere">
							<rect x="7" y="7" width="10" height="10" rx="1.5" fill="currentColor" />
						</template>
						<template v-else>
							<path
								d="M9 2h6M12 7a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM12 4.5V7M18.5 6l1.5-1.5M12 10.5V14h2.5"
								fill="none"
								stroke="currentColor"
								stroke-width="1.8"
								stroke-linecap="round"
								stroke-linejoin="round" />
						</template>
					</svg>
				</button>

				<!-- ONE action button, not three. Booking and viewing are the two
				     ordinary paths and they belong together behind the control the
				     reader reaches for; the tile's job is the figure. -->
				<div class="hq-hours__menu">
					<button
						ref="actionsTrigger"
						type="button"
						class="hq-hours__action"
						:aria-expanded="String(menuOpen)"
						:aria-label="t('humaniq', 'Actions')"
						aria-haspopup="menu"
						data-testid="hq-hours-actions"
						@click="toggleMenu">
						<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path
								d="M16 12a2 2 0 0 1 2-2 2 2 0 0 1 2 2 2 2 0 0 1-2 2 2 2 0 0 1-2-2m-6 0a2 2 0 0 1 2-2 2 2 0 0 1 2 2 2 2 0 0 1-2 2 2 2 0 0 1-2-2m-6 0a2 2 0 0 1 2-2 2 2 0 0 1 2 2 2 2 0 0 1-2 2 2 2 0 0 1-2-2"
								fill="currentColor" />
						</svg>
						<span class="hq-hours__action-label">{{ t('humaniq', 'Actions') }}</span>
					</button>

					<!-- FIXED, not absolute. The host puts this card in a grid cell
					     that scrolls, and an absolutely positioned list is clipped at
					     that cell's edge: the menu opened and showed one item and a
					     half. A fixed list is positioned against the viewport, which
					     no ancestor's overflow can cut, and it is placed from the
					     trigger's on-screen box at the moment it opens. Scrolling or
					     resizing would leave it floating where the trigger was, so
					     either one closes it. -->
					<div
						v-if="menuOpen"
						class="hq-hours__menu-list"
						:style="menuStyle"
						role="menu">
						<button
							v-if="compactHeader"
							type="button"
							class="hq-hours__menu-item"
							role="menuitem"
							:disabled="busy || !canUseTimer"
							data-testid="hq-hours-menu-timer"
							@click="closeMenu(); toggleTimer()">
							{{ timerTitle }}
						</button>
						<button
							type="button"
							class="hq-hours__menu-item"
							role="menuitem"
							data-testid="hq-hours-book"
							@click="openBooking">
							{{ t('humaniq', 'Book hours') }}
						</button>
						<a
							class="hq-hours__menu-item"
							role="menuitem"
							:href="administrationHref"
							data-testid="hq-hours-view">
							{{ t('humaniq', 'View hours') }}
						</a>
					</div>
				</div>
			</div>
		</div>

		<div class="hq-hours__body">
		<!-- Timing THIS object: the tile becomes the timer.
		     Only this object. A timer running elsewhere leaves the figures alone,
		     because putting another object's elapsed time where this object's
		     total goes reads as this object's time. -->
		<div v-if="runningHere" class="hq-hours__figures" data-testid="hq-hours-running">
			<div class="hq-hours__headline">
				<span class="hq-hours__value hq-hours__value--running">{{ elapsed }}</span>
			</div>
			<p class="hq-hours__sub">
				{{ t('humaniq', 'Timer running on this item') }}
			</p>
			<button
				v-if="compactHeader"
				type="button"
				class="hq-hours__timer-start"
				:disabled="busy"
				data-testid="hq-hours-stop-timer"
				@click="toggleTimer">
				{{ t('humaniq', 'Stop the timer') }}
			</button>
		</div>

		<!-- Idle: the week on this object, one row per person with a bar
		     against the week's largest share, beside a form that books hours
		     without opening a dialog (board DqZaakMeer, "Uren registreren"). -->
		<div v-else class="hq-hours__week-body">
			<div class="hq-hours__people-column">
				<p v-if="weekUnread" class="hq-hours__sub" data-testid="hq-hours-week-unread">
					{{ t('humaniq', 'The hours of this week are not known yet') }}
				</p>
				<ul v-else-if="people.length > 0" class="hq-hours__people" data-testid="hq-hours-people">
					<li v-for="person in people" :key="person.userId" class="hq-hours__person" data-testid="hq-hours-person">
						<span class="hq-hours__person-line">
							<span class="hq-hours__person-name">
								<strong>{{ person.displayName }}</strong>
								<span v-if="person.team" class="hq-hours__team">{{ person.team }}</span>
							</span>
							<strong class="hq-hours__person-hours">{{ hoursLabel(person.hours) }}</strong>
						</span>
						<span class="hq-hours__bar" aria-hidden="true">
							<span class="hq-hours__bar-fill" :style="{ width: barWidth(person.hours) }" />
						</span>
					</li>
				</ul>
				<p v-else class="hq-hours__sub" data-testid="hq-hours-week-empty">
					{{ t('humaniq', 'No hours recorded on this item this week') }}
				</p>
				<!-- Estimated and remaining stand beside the week, never instead of
				     it (REQ-HL-EST-003); an overrun is a negative remainder. -->
				<p v-if="hasEstimate" class="hq-hours__sub" data-testid="hq-hours-estimate">
					{{ estimateLine }}
				</p>
				<p v-if="running" class="hq-hours__sub" data-testid="hq-hours-running-elsewhere">
					{{ t('humaniq', 'Timer running on another item') }}
				</p>
			</div>
			<form class="hq-hours__form" data-testid="hq-hours-form" @submit.prevent="recordHours">
				<div class="hq-hours__form-row">
					<label class="hq-hours__field">
						<span>{{ t('humaniq', 'Date') }}</span>
						<input v-model="form.date"
							type="date"
							:required="!compactHeader"
							aria-required="true"
							data-testid="hq-hours-form-date">
					</label>
					<label class="hq-hours__field">
						<span>{{ t('humaniq', 'Hours') }}</span>
						<input
							v-model="form.hours"
							type="number"
							min="0.25"
							max="24"
							step="0.25"
							:required="!compactHeader"
							aria-required="true"
							data-testid="hq-hours-form-hours">
					</label>
				</div>
				<label class="hq-hours__field">
					<span>{{ t('humaniq', 'Description') }}</span>
					<input v-model="form.description" type="text" data-testid="hq-hours-form-description">
				</label>
				<button
					type="submit"
					class="hq-hours__submit"
					:disabled="saving || !formComplete"
					data-testid="hq-hours-form-submit">
					{{ t('humaniq', 'Record hours') }}
				</button>
				<!-- With the header's stopwatch in the menu, the timer is also a
				     button of its own under the form (board DqZaakMeer). -->
				<button
					v-if="compactHeader"
					type="button"
					class="hq-hours__timer-start"
					:disabled="busy || !canUseTimer"
					:title="canUseTimer ? '' : timerTitle"
					data-testid="hq-hours-form-timer"
					@click="toggleTimer">
					<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path
							d="M9 2h6M12 7a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM12 4.5V7M18.5 6l1.5-1.5M12 10.5V14h2.5"
							fill="none"
							stroke="currentColor"
							stroke-width="1.8"
							stroke-linecap="round"
							stroke-linejoin="round" />
					</svg>
					{{ t('humaniq', 'Start timer') }}
				</button>
			</form>
		</div>

		<p v-if="error" class="hq-hours__error" role="alert">
			{{ error }}
		</p>
		<p v-if="sourceNote" class="hq-hours__source-note" data-testid="hq-hours-source-note">
			{{ t('humaniq', 'Hours are recorded through humaniq. Without humaniq this card is left out.') }}
		</p>
		</div>
		</template>

		<HoursBookingDialog
			v-if="showBooking"
			:domainObjectType="domainObjectType"
			:domainObjectRef="objectId"
			@close="showBooking = false"
			@booked="onBooked" />
	</div>
</template>

<script>
/**
 * CnHoursWidget — hours booked against ANY object, and the three ways to act.
 *
 * humaniq owns hours (ADR-107 decision 6: "hours logged on a case are humaniq
 * time entries carrying the case reference"), so humaniq renders them. The
 * consuming app places this leaf and passes the object context; it does not
 * query humaniq's register itself.
 *
 * That indirection is the point. dossiq used to aggregate `humaniq/TimeEntry`
 * from its own manifest, which meant that on an install without humaniq the
 * request 404'd and the tile rendered `0`, indistinguishable from a real zero
 * (ADR-113). A leaf cannot render at all when its app is absent, so the failure
 * mode disappears rather than being handled.
 *
 * TWO FIGURES FROM ONE READ. The headline is what the object cost everyone; the
 * line beneath is what it cost the reader. Both are summed from the same array,
 * because a second request for the second figure would let the two disagree.
 *
 * THE TIMER IS A ROW, NOT A FLAG. Starting one writes a `TimeEntry` with no end,
 * so it is still running when the reader comes back to the page, or opens it on
 * another device. This component asks the server what is running on mount rather
 * than remembering, which is the only version of that promise it can keep.
 *
 * The bound object is identified the way humaniq stores it: `domainObjectType`
 * is the `<app>:<schema>` literal (`dossiq:case`) and `domainObjectRef` is the
 * object's uuid. Both are written by integrations rather than typed by an
 * employee, which is why neither appears in any `includeFields` allowlist.
 */
import { getLanguage, translate as t } from '@nextcloud/l10n'
import HoursBookingDialog from '../dialogs/HoursBookingDialog.vue'
import {
	administrationUrl,
	bookHours,
	fetchEstimate,
	fetchRunningTimer,
	fetchWeek,
	startTimer,
	stopTimer,
} from './hoursApi.js'
import { isForObject, parseHours, ROW_RECORDED_EVENT } from './rowHours.mjs'

/** How often the running figure is redrawn, in milliseconds. */
const TICK_MS = 1000

export default {
	name: 'CnHoursWidget',

	components: {
		HoursBookingDialog,
	},

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

		/** The host object's uuid, what `domainObjectRef` points at. */
		objectId: {
			type: String,
			default: '',
		},

		/**
		 * The render surface the host mounted us into. Part of the leaf contract the
		 * host always passes, exposed as `data-surface` so a host can style the card
		 * per surface without the leaf guessing how much room it has.
		 */
		surface: {
			type: String,
			default: 'detail-page',
		},

		/**
		 * The card's title as the host names it ("Record hours" on a dossiq
		 * case), translated by the host. "Hours booked" without one.
		 */
		title: {
			type: String,
			default: '',
		},

		/**
		 * What the host calls the object, in English, for the week line:
		 * "this case" reads "11.5 h on this case". humaniq translates the
		 * nouns it knows; another is shown as written. "this item" without one.
		 */
		objectNoun: {
			type: String,
			default: '',
		},

		/**
		 * A header with only the title, the week and the Actions menu: the
		 * stopwatch moves into the menu and under the form as "Start timer",
		 * the clock icon is left out (board DqZaakMeer, "Uren registreren").
		 */
		compactHeader: {
			type: Boolean,
			default: false,
		},

		/** A note band saying the hours come from humaniq and the card goes without it. */
		sourceNote: {
			type: Boolean,
			default: false,
		},

	},

	data() {
		return {
			/** The week on this object (`GET /api/time-entries/week`), or null while unread. */
			week: null,
			/** The inline booking form. */
			form: { date: new Date().toISOString().slice(0, 10), hours: '', description: '' },
			saving: false,
			loading: false,
			busy: false,
			error: '',
			/** The caller's running entry, from the server, or null. */
			running: null,
			now: Date.now(),
			tick: null,
			showBooking: false,
			/** The estimate summary humaniq derives, or null when it is unread. */
			estimate: null,
			menuOpen: false,
			/** Viewport coordinates the open menu is pinned at, or null. */
			menuStyle: null,
			/** Whether the document listeners behind the open menu are bound. */
			menuListenersBound: false,
			/** The hours typed in the row field (`surface: "row"`). */
			rowHours: '',
		}
	},

	computed: {
		/**
		 * Whether the leaf sits on a host's row: one "Hours" field, no card.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/hours-row-surface/specs/hours-leaf/spec.md#requirement-the-hours-leaf-on-a-row-is-one-hours-field-booked-with-the-rows-note
		 */
		isRow() {
			return this.surface === 'row'
		},

		/**
		 * The row field's hours as a number, or null.
		 *
		 * @return {number|null}
		 *
		 * @spec openspec/changes/hours-row-surface/specs/hours-leaf/spec.md#requirement-the-hours-leaf-on-a-row-is-one-hours-field-booked-with-the-rows-note
		 */
		rowHoursValue() {
			return parseHours(this.rowHours)
		},

		/**
		 * The card's title: the host's, else "Hours booked".
		 *
		 * @return {string} The title.
		 *
		 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-takes-its-hosts-title
		 */
		caption() {
			return this.title !== '' ? this.title : t('humaniq', 'Hours booked')
		},

		/**
		 * Whether the week could not be read (yet). The rows and the header's
		 * total then say nothing rather than a 0 that reads as no hours.
		 *
		 * @return {boolean} True while the week is unknown.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-unreadable-total-is-not-rendered-as-a-number
		 */
		weekUnread() {
			return this.week === null || this.error !== ''
		},

		/**
		 * The week's people, most hours first, as the server sends them.
		 *
		 * @return {Array<object>} `{ userId, displayName, team, hours }` rows.
		 *
		 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
		 */
		people() {
			return Array.isArray(this.week?.people) ? this.week.people : []
		},

		/**
		 * "Week 41 · 5 Oct to 11 Oct · 11.5 h on this item", or '' while unread.
		 *
		 * @return {string} The line.
		 *
		 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
		 */
		weekLine() {
			if (this.weekUnread === true) {
				return ''
			}
			const values = {
				week: String(this.week.week),
				from: this.dayLabel(this.week.weekStart),
				to: this.dayLabel(this.week.weekEnd),
				hours: this.hoursLabel(this.week.total),
			}
			if (this.objectNoun === '') {
				return t('humaniq', 'Week {week} · {from} to {to} · {hours} on this item', values)
			}
			return t('humaniq', 'Week {week} · {from} to {to} · {hours} on {object}', { ...values, object: t('humaniq', this.objectNoun) })
		},

		/**
		 * Whether the form holds a day and a positive number of hours.
		 *
		 * @return {boolean} True when it can be sent.
		 *
		 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
		 */
		formComplete() {
			return this.form.date !== '' && Number(this.form.hours) > 0
		},

		/**
		 * Whether this object carries an estimate at all.
		 *
		 * False while the summary is unread, so a failed read renders the
		 * no-estimate line rather than a remainder computed from nothing.
		 *
		 * @return {boolean} True when an estimate exists.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-003
		 */
		hasEstimate() {
			return this.estimate !== null && this.estimate.hasEstimate === true
		},

		/**
		 * Estimated and remaining, as a full line beside the spent total.
		 *
		 * @return {string} The line.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-003
		 */
		estimateLine() {
			if (this.hasEstimate === false) {
				return ''
			}

			const estimated = this.formatHours(Number(this.estimate.estimatedHours || 0))
			const remaining = this.formatHours(Number(this.estimate.remainingHours || 0))

			return t('humaniq', '{estimated} estimated, {remaining} remaining', { estimated, remaining })
		},

		/**
		 * Whether the running timer belongs to the object on screen.
		 *
		 * @return {boolean} True when this object is the one being timed.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
		 */
		runningHere() {
			return this.running !== null
				&& String(this.running.domainObjectRef || '') === this.objectId
		},

		/**
		 * Whether the timer control does anything if pressed.
		 *
		 * DISABLED rather than hidden while a timer runs on ANOTHER object. It was
		 * hidden, and the card then changed shape by state: the stopwatch simply
		 * vanished, which reads as a broken card rather than as a rule, and the
		 * one reader most likely to press it is the one who forgot the other
		 * timer. A present, disabled control carries its reason in its name.
		 * Nothing depends on this for the rule itself: the server refuses a
		 * second start regardless.
		 *
		 * @return {boolean} True when pressing the control does something.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
		 */
		canUseTimer() {
			return this.running === null || this.runningHere === true
		},

		/**
		 * The timer control's accessible name.
		 *
		 * The control is an icon alone, so its whole name lives here: without it
		 * a screen reader announces a button with no label.
		 *
		 * @return {string} The name.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		timerTitle() {
			if (this.runningHere === true) {
				return t('humaniq', 'Stop the timer')
			}
			if (this.canUseTimer === false) {
				return t('humaniq', 'You already have a timer running on another item')
			}
			return t('humaniq', 'Start a timer')
		},

		/**
		 * The running timer as `H:MM:SS`, counting from the stored start.
		 *
		 * Counted from the SERVER's `startedAt` rather than from the moment this
		 * component mounted, which is what makes the figure survive a reload.
		 *
		 * @return {string} The elapsed time.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
		 */
		elapsed() {
			const started = Date.parse(String(this.running?.startedAt || ''))
			if (Number.isNaN(started) === true) {
				return '0:00:00'
			}

			const seconds = Math.max(0, Math.floor((this.now - started) / 1000))
			const h = Math.floor(seconds / 3600)
			const m = Math.floor((seconds % 3600) / 60)
			const s = seconds % 60

			return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
		},

		/**
		 * The `<app>:<schema>` literal humaniq stores for the host object.
		 *
		 * @return {string} For example `dossiq:case`.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-humaniq-supplies-the-hours-surface-for-any-object
		 */
		domainObjectType() {
			return this.register !== '' && this.schema !== '' ? `${this.register}:${this.schema}` : ''
		},

		/**
		 * The link into humaniq's hour administration for this object.
		 *
		 * @return {string} The url.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		administrationHref() {
			return administrationUrl(this.domainObjectType, this.objectId)
		},
	},

	/**
	 * Read the object's hours, ask what timer is running, and start ticking.
	 *
	 * The uid is read from Nextcloud rather than from the entries, because the
	 * caller's own share must render `0` when they have booked nothing here, and
	 * a uid inferred from the rows cannot tell that apart from having no rows.
	 *
	 * @return {void}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-reads-as-a-kpi-tile
	 */
	mounted() {
		if (this.isRow) {
			// A row reads nothing up front: it only books, when its row is recorded.
			document.addEventListener(ROW_RECORDED_EVENT, this.onRowRecorded)
			return
		}
		this.load()
		this.loadRunning()
		this.loadEstimate()
		this.tick = window.setInterval(() => {
			this.now = Date.now()
		}, TICK_MS)
	},

	/**
	 * Stop the tick.
	 *
	 * The host unmounts this leaf when the bound object changes or the surface
	 * hides, and an interval that outlives its component keeps redrawing a tile
	 * nobody is looking at, forever.
	 *
	 * @return {void}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
	 */
	beforeUnmount() {
		document.removeEventListener(ROW_RECORDED_EVENT, this.onRowRecorded)
		if (this.tick !== null) {
			window.clearInterval(this.tick)
			this.tick = null
		}
		this.unbindMenuListeners()
	},

	methods: {
		t,

		/**
		 * Book the row field's hours on this object today, with the row's note
		 * as the description, when the host has recorded that row's note.
		 *
		 * @param {CustomEvent} event The host's recorded-note event.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/hours-row-surface/specs/hours-leaf/spec.md#requirement-the-hours-leaf-on-a-row-is-one-hours-field-booked-with-the-rows-note
		 */
		async onRowRecorded(event) {
			const hours = this.rowHoursValue
			if (isForObject(event?.detail, this.objectId) === false || hours === null || this.saving === true) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				await bookHours({
					domainObjectType: this.domainObjectType,
					domainObjectRef: this.objectId,
					date: new Date().toISOString().slice(0, 10),
					hours,
					description: String(event.detail.note ?? ''),
				})
				this.rowHours = ''
			} catch (e) {
				this.error = e?.response?.data?.message || e?.response?.data?.error || t('humaniq', 'The hours could not be recorded.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Read this object's time entries from OpenRegister.
		 *
		 * @return {Promise<void>} Resolves when the list has settled.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-unreadable-total-is-not-rendered-as-a-number
		 */
		async load() {
			if (this.objectId === '' || this.domainObjectType === '') {
				return
			}

			this.loading = true
			this.error = ''
			try {
				this.week = await fetchWeek(this.domainObjectType, this.objectId)
			} catch {
				// Say so rather than render 0. See the component docblock.
				this.error = t('humaniq', 'The hours for this item could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Ask humaniq for estimated, spent and remaining on this object.
		 *
		 * A failed read leaves `estimate` null, which renders the no-estimate
		 * line: a remainder is only ever shown when one was actually answered,
		 * so an unread estimate cannot render as a number the reader believes.
		 *
		 * @return {Promise<void>} Resolves when the summary has settled.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-002
		 */
		async loadEstimate() {
			if (this.objectId === '' || this.domainObjectType === '') {
				return
			}

			try {
				this.estimate = await fetchEstimate(this.domainObjectType, this.objectId)
			} catch {
				this.estimate = null
			}
		},

		/**
		 * Ask the server what timer, if any, the caller has running.
		 *
		 * Failing quietly is right here and nowhere else on this tile: not knowing
		 * whether a timer runs costs the reader the timer control, while the
		 * figures are still true. Saying so would put an error over a working
		 * surface.
		 *
		 * @return {Promise<void>} Resolves when the timer state has settled.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
		 */
		async loadRunning() {
			try {
				this.running = await fetchRunningTimer()
			} catch {
				this.running = null
			}
		},

		/**
		 * Start a timer on this object, or stop the one running on it.
		 *
		 * @return {Promise<void>} Resolves when the entry has been written.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
		 */
		async toggleTimer() {
			this.busy = true
			this.error = ''
			try {
				if (this.runningHere === true) {
					await stopTimer()
					this.running = null
					await this.load()
					// A stopped timer always lands, even past an enforced
					// ceiling, so the remainder it moved has to be re-read.
					await this.loadEstimate()
				} else {
					const result = await startTimer(this.domainObjectType, this.objectId)
					if (result.status === 'running') {
						this.running = result.entry || null
					} else {
						// A refusal is an answer. Keep the entry the server named, so
						// the tile can say a timer runs elsewhere rather than only
						// that this one would not start.
						this.running = result.entry || null
						this.error = result.error || t('humaniq', 'You already have a timer running.')
					}
				}
			} catch (e) {
				this.error = e?.response?.data?.error || t('humaniq', 'The timer could not be started or stopped.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Take up a booking made in the dialog.
		 *
		 * @return {Promise<void>} Resolves when the figures have caught up.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		async onBooked() {
			this.showBooking = false
			await this.load()
			// The remainder is derived from the entries, so a new booking moves
			// it. Re-read rather than subtract locally: the server's number is
			// the one an enforced ceiling is applied against.
			await this.loadEstimate()
		},

		/**
		 * Open the actions menu under its trigger, or close it.
		 *
		 * The list is `position: fixed`, so it is placed here from the
		 * trigger's on-screen box rather than flowing from its parent: the
		 * host's grid cell scrolls, and a list positioned inside it was cut off
		 * at the cell's edge. Its right edge sits on the trigger's right edge.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		toggleMenu() {
			if (this.menuOpen === true) {
				this.closeMenu()
				return
			}

			const trigger = this.$refs.actionsTrigger
			const rect = trigger && typeof trigger.getBoundingClientRect === 'function'
				? trigger.getBoundingClientRect()
				: null
			this.menuStyle = rect === null
				? null
				: {
					top: `${Math.round(rect.bottom + 4)}px`,
					right: `${Math.round(window.innerWidth - rect.right)}px`,
				}
			this.menuOpen = true
			this.bindMenuListeners()
			// The card is often the last thing in a column, so a list that
			// always drops downward ends below the viewport, where the reader
			// cannot reach it: scrolling to it closes it. Once the list has a
			// size, flip it above the trigger when there is no room beneath.
			this.$nextTick(() => {
				const list = this.$el?.querySelector?.('.hq-hours__menu-list')
				if (rect === null || !list || this.menuOpen === false) {
					return
				}
				const height = list.getBoundingClientRect().height
				if (rect.bottom + 4 + height > window.innerHeight && rect.top - 4 - height >= 0) {
					this.menuStyle = {
						bottom: `${Math.round(window.innerHeight - rect.top + 4)}px`,
						right: `${Math.round(window.innerWidth - rect.right)}px`,
					}
				}
			})
		},

		/**
		 * Close the actions menu and drop its listeners.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		closeMenu() {
			this.menuOpen = false
			this.menuStyle = null
			this.unbindMenuListeners()
		},

		/**
		 * Listen for the three things that end an open menu.
		 *
		 * A press outside it, Escape, and any scroll or resize. The last two
		 * matter because the list is pinned to viewport coordinates taken when
		 * it opened; after a scroll it would sit where the trigger used to be.
		 * Bound only while the menu is open, so a closed card costs the page
		 * no listeners.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		bindMenuListeners() {
			if (this.menuListenersBound === true) {
				return
			}
			this.menuListenersBound = true
			document.addEventListener('pointerdown', this.onDocumentPointerDown, true)
			document.addEventListener('keydown', this.onDocumentKeydown, true)
			window.addEventListener('scroll', this.closeMenu, true)
			window.addEventListener('resize', this.closeMenu)
		},

		/**
		 * Undo `bindMenuListeners`. Safe to call when nothing is bound.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		unbindMenuListeners() {
			if (this.menuListenersBound !== true) {
				return
			}
			this.menuListenersBound = false
			document.removeEventListener('pointerdown', this.onDocumentPointerDown, true)
			document.removeEventListener('keydown', this.onDocumentKeydown, true)
			window.removeEventListener('scroll', this.closeMenu, true)
			window.removeEventListener('resize', this.closeMenu)
		},

		/**
		 * Close the menu on a press anywhere but the menu or its trigger.
		 *
		 * @param {PointerEvent} event The press.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		onDocumentPointerDown(event) {
			const target = event.target
			const inside = target instanceof Node
				&& this.$el instanceof Node
				&& this.$el.querySelector('.hq-hours__menu')?.contains(target) === true
			if (inside === false) {
				this.closeMenu()
			}
		},

		/**
		 * Close the menu on Escape and hand focus back to the trigger.
		 *
		 * @param {KeyboardEvent} event The key press.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		onDocumentKeydown(event) {
			if (event.key !== 'Escape') {
				return
			}
			this.closeMenu()
			const trigger = this.$refs.actionsTrigger
			if (trigger && typeof trigger.focus === 'function') {
				trigger.focus()
			}
		},

		/**
		 * Open the booking dialog from the actions menu.
		 *
		 * Closes the menu first: leaving it open behind a modal puts two focus
		 * traps on the page, and the one underneath is the one a screen reader
		 * finds on Escape.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		openBooking() {
			this.closeMenu()
			this.showBooking = true
		},

		/**
		 * Book the form's hours on this object, then re-read the week and the
		 * estimate the booking moved.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
		 */
		async recordHours() {
			if (this.formComplete === false || this.saving === true) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				await bookHours({
					domainObjectType: this.domainObjectType,
					domainObjectRef: this.objectId,
					date: this.form.date,
					hours: Number(this.form.hours),
					description: this.form.description,
				})
				this.form = { ...this.form, hours: '', description: '' }
				await this.load()
				await this.loadEstimate()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.response?.data?.error || t('humaniq', 'The hours could not be recorded.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Hours as "6.5 h" in the user's language.
		 *
		 * @param {number} value The hours.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
		 */
		hoursLabel(value) {
			const hours = (Math.round((Number(value) || 0) * 100) / 100).toLocaleString(getLanguage())
			return t('humaniq', '{hours} h', { hours })
		},

		/**
		 * A day as "5 Oct" in the user's language.
		 *
		 * @param {string} day The day, `YYYY-MM-DD`.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
		 */
		dayLabel(day) {
			const date = new Date(`${day}T12:00:00`)
			return Number.isNaN(date.getTime()) ? String(day || '') : date.toLocaleDateString(getLanguage(), { day: 'numeric', month: 'short' })
		},

		/**
		 * A person's bar, as a share of the week's largest.
		 *
		 * @param {number} hours The person's hours.
		 *
		 * @return {string} The CSS width.
		 *
		 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
		 */
		barWidth(hours) {
			const most = Math.max(0, ...this.people.map((person) => Number(person.hours) || 0))
			return most > 0 ? `${Math.round(((Number(hours) || 0) / most) * 100)}%` : '0%'
		},

		/**
		 * Format an hours figure to at most two decimals, without trailing zeroes.
		 *
		 * @param {number} value The hours.
		 *
		 * @return {string} The formatted figure.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-unreadable-total-is-not-rendered-as-a-number
		 */
		formatHours(value) {
			return String(Math.round(((Number(value) || 0) * 100)) / 100)
		},

	},
}
</script>

<style scoped>
/* On a host's row: the label over a narrow field, as the host's own note
   field beside it (dossiq DqDagAfsluiten "Uren"). */
.hq-hours[data-surface='row'] {
	display: block;
	width: 104px;
}

.hq-hours__row {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.hq-hours__row-label {
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 600;
}

/* Nextcloud's own input rule (seven :not() parts) outweighs a class, and
   would cap the field at its 34px clickable area; the host's fields beside
   it are 40px. */
.hq-hours__row-input {
	box-sizing: border-box;
	width: 100%;
	height: 40px !important;
	margin: 0;
	padding: 0 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 15px;
}

.hq-hours__row-input[aria-invalid='true'] {
	border-color: var(--color-error);
}

.hq-hours__row-error {
	color: var(--color-error-text, var(--color-error));
	font-size: 13px;
}

/* The card the host does not draw. A mount-mode leaf is handed a bare element,
   so without this the tile is loose text between neighbours that have chrome. */
.hq-hours {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-sizing: border-box;
	container: hq-hours / inline-size;
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
	padding: 0;
}

/* The header, spelled like the host's widget header: 12px 16px of padding,
   a rule beneath, icon and title on the left, controls on the right. The
   figures match the host's numbers because the host's are the ones every
   neighbouring card on the page is drawn with. */
.hq-hours__header {
	align-items: center;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	flex-shrink: 0;
	gap: 8px;
	justify-content: space-between;
	padding: 12px 16px;
}

.hq-hours__header-left {
	align-items: center;
	display: flex;
	flex: 1 1 auto;
	gap: 8px;
	min-width: 0;
}

.hq-hours__icon {
	color: var(--color-primary-element);
	display: flex;
	flex-shrink: 0;
	height: 24px;
	width: 24px;
}

/* The compact header reads as the host's card header: no rule, the host's
   title size and inset. The form's empty required fields are not drawn red
   before anything was typed; Record hours stays disabled until they are filled. */
.hq-hours--compact .hq-hours__header {
	border-bottom: none;
	padding: 20px 22px 0;
}

.hq-hours--compact .hq-hours__caption {
	font-size: 17px;
	font-weight: 700;
}

.hq-hours__caption {
	font-size: 14px;
	font-weight: 600;
	margin: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.hq-hours__body {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: 8px;
	justify-content: center;
	min-height: 0;
	padding: 12px 16px;
}

.hq-hours__controls {
	align-items: center;
	display: flex;
	flex: 0 0 auto;
	gap: 4px;
}

.hq-hours__menu {
	position: relative;
}

/* Pinned to the viewport, so no scrolling ancestor can clip it. The
   coordinates come from the trigger's box when the menu opens; see
   toggleMenu(). */
.hq-hours__menu-list {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.2);
	display: flex;
	flex-direction: column;
	min-width: 160px;
	padding: 4px;
	position: fixed;
	z-index: 10000;
}

.hq-hours__menu-item {
	background: transparent;
	border: none;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	cursor: pointer;
	font: inherit;
	padding: 8px 10px;
	text-align: start;
	text-decoration: none;
	white-space: nowrap;
}

.hq-hours__menu-item:hover,
.hq-hours__menu-item:focus-visible {
	background-color: var(--color-background-hover);
}

.hq-hours__action-label {
	font-weight: bold;
}

/* A four-column cell at a common desktop width is about 320px. The icon, the
   title, the stopwatch and a named Actions pill do not fit on that line, and
   the title is the one that gave: it read "Hours boo…". Below that width the
   pill drops its label and keeps its icon; the button's accessible name is
   set on the element, so nothing is lost for a screen reader. */
@container hq-hours (max-width: 340px) {
	.hq-hours__action-label {
		display: none;
	}

	.hq-hours__action {
		padding: 0 7px;
	}
}

.hq-hours__headline {
	align-items: baseline;
	display: flex;
	gap: 6px;
}

.hq-hours__value {
	color: var(--color-primary-element);
	font-size: 28px;
	font-weight: bold;
	line-height: 1.1;
}

.hq-hours__value--running {
	font-variant-numeric: tabular-nums;
}

.hq-hours__sub,
.hq-hours__unit {
	color: var(--color-text-maxcontrast);
}

.hq-hours__sub {
	margin: 2px 0 0;
}

.hq-hours__error {
	color: var(--color-error);
	margin: 0;
}

/* The week (board DqZaakMeer, "Uren registreren"): the people with their bars
   beside the booking form, the form under them where the card is narrow. */
.hq-hours__week {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	white-space: nowrap;
}

.hq-hours__week-body {
	display: grid;
	gap: 16px 24px;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

.hq-hours__people {
	display: flex;
	flex-direction: column;
	gap: 14px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.hq-hours__person {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.hq-hours__person-line {
	align-items: baseline;
	display: flex;
	gap: 8px;
	justify-content: space-between;
}

.hq-hours__team {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 6px;
}

.hq-hours__person-hours {
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.hq-hours__bar {
	background: var(--color-background-dark);
	border-radius: 2px;
	display: block;
	height: 4px;
	overflow: hidden;
}

.hq-hours__bar-fill {
	background: var(--color-primary-element);
	display: block;
	height: 100%;
}

.hq-hours__form {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.hq-hours__form-row {
	display: grid;
	gap: 10px;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

.hq-hours__field {
	display: flex;
	flex-direction: column;
	font-weight: 600;
	gap: 4px;
}

.hq-hours__field input {
	box-sizing: border-box;
	margin: 0;
	width: 100%;
}

.hq-hours__submit {
	align-self: flex-start;
	background: var(--color-primary-element);
	border: none;
	border-radius: var(--border-radius-element, 8px);
	color: var(--color-primary-element-text);
	cursor: pointer;
	font: inherit;
	font-weight: 600;
	min-height: var(--default-clickable-area, 34px);
	padding: 0 16px;
}

.hq-hours__timer-start {
	align-items: center;
	align-self: flex-start;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-maxcontrast, var(--color-border-dark));
	border-radius: var(--border-radius-element, 8px);
	color: var(--color-main-text);
	cursor: pointer;
	display: inline-flex;
	font: inherit;
	font-weight: 600;
	gap: 6px;
	min-height: var(--default-clickable-area, 34px);
	padding: 0 16px;
}

.hq-hours__timer-start:disabled {
	cursor: default;
	opacity: 0.5;
}

.hq-hours__source-note {
	background: var(--color-primary-element-light, var(--color-background-hover));
	border-radius: var(--border-radius-element, 8px);
	color: var(--color-main-text);
	font-size: 13px;
	margin: 16px 0 0;
	padding: 10px 14px;
}

.hq-hours__submit:disabled {
	cursor: default;
	opacity: 0.5;
}

@container hq-hours (max-width: 560px) {
	.hq-hours__week-body {
		grid-template-columns: minmax(0, 1fr);
	}

	.hq-hours__week {
		white-space: normal;
	}
}

/* The same shape as the named Actions trigger on every other card: a
   clickable-area-high pill, no border, icon then label, filled on hover. */
.hq-hours__action {
	align-items: center;
	background: var(--color-primary-element-light, var(--color-background-dark));
	border: none;
	border-radius: var(--border-radius-element, var(--border-radius-pill, 17px));
	color: var(--color-primary-element-light-text, var(--color-main-text));
	cursor: pointer;
	display: flex;
	font: inherit;
	gap: 4px;
	height: var(--default-clickable-area, 34px);
	min-height: var(--default-clickable-area, 34px);
	padding: 0 12px 0 10px;
	text-decoration: none;
}

.hq-hours__action:hover,
.hq-hours__action:focus-visible {
	background-color: var(--color-primary-element-light-hover, var(--color-background-hover));
}

/* The same pill as the Actions trigger beside it, icon only. It was the one
   round, outlined button in a header row of light-filled pills. */
.hq-hours__timer {
	align-items: center;
	background: var(--color-primary-element-light, var(--color-background-dark));
	border: none;
	border-radius: var(--border-radius-element, var(--border-radius-pill, 17px));
	color: var(--color-primary-element-light-text, var(--color-main-text));
	cursor: pointer;
	display: flex;
	flex: 0 0 auto;
	height: var(--default-clickable-area, 34px);
	justify-content: center;
	min-height: var(--default-clickable-area, 34px);
	min-width: var(--default-clickable-area, 34px);
	padding: 0 8px;
}

.hq-hours__timer:hover:enabled,
.hq-hours__timer:focus-visible {
	background-color: var(--color-primary-element-light-hover, var(--color-background-hover));
}

.hq-hours__spinner {
	animation: hq-hours-spin 0.9s linear infinite;
}

@keyframes hq-hours-spin {
	to {
		transform: rotate(360deg);
	}
}

@media (prefers-reduced-motion: reduce) {
	.hq-hours__spinner {
		animation-duration: 3s;
	}
}

.hq-hours__timer:disabled {
	cursor: default;
	opacity: 0.6;
}

.hq-hours__timer--running {
	background-color: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}
</style>
