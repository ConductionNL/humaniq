/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the vacancy and survey question widgets: each widget is
 * compiled from its SFC, mounted in jsdom with stand-ins for the library
 * components and axios, and driven through loading, a failed read, answers
 * without a question list, a survey that is no longer a draft, saving (rows
 * without a key dropped), a failed save, and the detail context a widget host
 * hands in next to the object id. The rendered markup (class names left out,
 * they only carry styling), the props handed to CnFormBuilder and every
 * request are compared with tests/js/fixtures/questionsWidgets.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite that file.
 *
 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-002
 */
import { compileTemplate, parse } from '@vue/compiler-sfc'
import { JSDOM } from 'jsdom'
import assert from 'node:assert/strict'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const GOLDEN = fileURLToPath(new URL('./fixtures/questionsWidgets.golden.json', import.meta.url))
const BUILD = join(ROOT, 'node_modules/.cache/questions-widgets-golden')
const STUBS = ['@conduction/nextcloud-vue', '@nextcloud/vue', '@nextcloud/axios', '@nextcloud/router']

const dom = new JSDOM('<!doctype html><html><body></body></html>')
globalThis.window = dom.window
globalThis.document = dom.window.document
globalThis.Element = dom.window.Element
globalThis.SVGElement = dom.window.SVGElement

const golden = { builder: null, calls: [], answers: {} }
globalThis.__questionsGolden = golden

const vue = await import('vue')

/**
 * The stand-ins for the library components, axios and the router.
 *
 * @return {string} The module source.
 */
function stubSource() {
	return `import { h } from 'vue'
const g = globalThis.__questionsGolden
const sorted = (o) => Object.fromEntries(Object.keys(o).sort().map((k) => [k, o[k]]))
export const CnFormBuilder = {
	name: 'CnFormBuilder',
	inheritAttrs: false,
	mounted() { g.builder = this },
	render() {
		const attrs = sorted(Object.fromEntries(Object.entries(this.$attrs).filter(([k]) => !k.startsWith('on'))))
		return h('div', { 'data-stub': 'CnFormBuilder', 'data-attrs': JSON.stringify(attrs) })
	},
}
export const NcButton = {
	name: 'NcButton',
	props: { variant: String, disabled: Boolean },
	emits: ['click'],
	render() {
		return h('button', { 'data-variant': this.variant, disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
	},
}
export const NcLoadingIcon = { name: 'NcLoadingIcon', render: () => h('span', { 'data-stub': 'NcLoadingIcon' }) }
export const NcNoteCard = {
	name: 'NcNoteCard',
	props: { type: String },
	render() { return h('div', { 'data-stub': 'NcNoteCard', 'data-type': this.type }, this.$slots.default?.()) },
}
const answer = async (method, url, body) => {
	g.calls.push(body === undefined ? [method, url] : [method, url, JSON.parse(JSON.stringify(body))])
	const next = g.answers[method].shift()
	if (next instanceof Error) throw next
	return { data: next }
}
export default {
	get: (url) => answer('get', url),
	patch: (url, body) => answer('patch', url, body),
}
export const generateUrl = (url, params) => url.replace(/{(\\w+)}/g, (_m, k) => String(params[k]))
`
}

/**
 * Compile one SFC, and the SFCs it imports, into ES modules under BUILD.
 *
 * @param {string} file The SFC's absolute path.
 * @return {string} The compiled module's path.
 */
function compile(file) {
	const out = join(BUILD, relative(ROOT, file) + '.mjs')
	const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
	const template = compileTemplate({ source: descriptor.template.content, filename: file, id: 'golden' })
	let script = descriptor.script.content
	script = script.replace(/from '(\.[^']+\.vue)'/g, (_m, spec) => `from '${pathToFileURL(compile(resolve(dirname(file), spec))).href}'`)
	for (const name of STUBS) {
		script = script.replaceAll(`from '${name}'`, `from '${pathToFileURL(join(BUILD, 'stubs.mjs')).href}'`)
	}
	script = script.replace('export default {', 'const __component = {')
	mkdirSync(dirname(out), { recursive: true })
	writeFileSync(out, `${script}\n${template.code}\n__component.render = render\nexport default __component\n`)
	return out
}

mkdirSync(BUILD, { recursive: true })
writeFileSync(join(BUILD, 'stubs.mjs'), stubSource())

/**
 * Load a widget.
 *
 * @param {string} name The widget's file name under src/widgets.
 * @return {Promise<object>} The component.
 */
async function widget(name) {
	return (await import(pathToFileURL(compile(join(ROOT, 'src/widgets', name))).href)).default
}

/**
 * Let pending promises and Vue's render queue settle.
 *
 * @return {Promise<void>}
 */
async function settle() {
	for (let i = 0; i < 5; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0))
		await vue.nextTick()
	}
}

/**
 * The rendered markup without class attributes.
 *
 * @param {Element} el The mount element.
 * @return {string} The markup.
 */
function markup(el) {
	const copy = el.cloneNode(true)
	for (const node of copy.querySelectorAll('[class]')) {
		node.removeAttribute('class')
	}
	return copy.innerHTML
}

/**
 * Mount a widget, run the steps and record the markup after each.
 *
 * @param {object} component The widget.
 * @param {object} scenario The object id, the answers and the steps.
 * @return {Promise<object>} The trace.
 */
async function run(component, scenario) {
	golden.builder = null
	golden.calls = []
	golden.answers = { get: [...scenario.get], patch: [...(scenario.patch ?? [])] }
	const el = document.createElement('div')
	document.body.appendChild(el)
	const app = vue.createApp(component, { objectId: scenario.objectId ?? 'v-1', ...scenario.attrs })
	app.config.globalProperties.t = (_app, text) => text
	app.mount(el)
	const trace = { mounted: markup(el) }
	await settle()
	trace.loaded = markup(el)
	for (const [i, step] of (scenario.steps ?? []).entries()) {
		if (step.edit) {
			golden.builder.$attrs['onUpdate:modelValue'](step.edit)
		}
		if (step.save) {
			el.querySelector('button').click()
			await vue.nextTick()
			trace[`step${i}.saving`] = markup(el)
		}
		await settle()
		trace[`step${i}`] = markup(el)
	}
	trace.calls = golden.calls
	app.unmount()
	el.remove()
	return trace
}

const QUESTIONS = [{ key: 'motivation', type: 'textarea', label: 'Why?' }, { key: 'start', type: 'string' }]
const EDITED = [{ key: 'a', type: 'string' }, { key: '  ', type: 'string' }, { key: '', type: 'number' }, { key: 0, type: 'boolean' }, { type: 'enum' }, { key: ' b ', type: 'enum', options: ['x'] }]

const SCENARIOS = {
	loads: { get: [{ questions: QUESTIONS, status: 'concept' }] },
	'no-question-list': { get: [{ questions: 'nope' }] },
	'empty-answer': { get: [null] },
	'read-fails': { get: [new Error('down')] },
	'no-longer-draft': { get: [{ questions: QUESTIONS, status: 'open' }] },
	'host-context': {
		attrs: { content: {}, register: 'humaniq', schema: 'page-schema', objectData: { id: 'v-1' }, objectType: 'page-type', store: null },
		get: [{ questions: QUESTIONS, status: 'open' }],
	},
	'numeric-id': { objectId: 7, get: [{ questions: [] }] },
	saves: { get: [{ questions: QUESTIONS }], patch: [{}], steps: [{ edit: EDITED, save: true }] },
	'save-fails': { get: [{ questions: QUESTIONS }], patch: [new Error('nope')], steps: [{ save: true }] },
	'save-then-fail': { get: [{ questions: QUESTIONS }], patch: [{}, new Error('nope')], steps: [{ save: true }, { save: true }] },
}

const recorded = existsSync(GOLDEN) ? JSON.parse(readFileSync(GOLDEN, 'utf8')) : {}

for (const file of ['VacancyQuestionsWidget.vue', 'SurveyQuestionsWidget.vue']) {
	for (const [name, scenario] of Object.entries(SCENARIOS)) {
		test(`${file} ${name}`, async () => {
			const key = `${file} ${name}`
			const actual = JSON.parse(JSON.stringify(await run(await widget(file), scenario)))
			if (process.env.RECORD_GOLDEN === '1') {
				recorded[key] = actual
				writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
				return
			}
			assert.deepEqual(actual, recorded[key])
		})
	}
}
