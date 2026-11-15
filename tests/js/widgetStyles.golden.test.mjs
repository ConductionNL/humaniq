/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the styles of the payroll, on-call, year transition,
 * application answers and hours widgets: every style block is compiled the
 * way the build compiles it (SCSS, scoped) and the CSS is compared with
 * tests/js/fixtures/widgetStyles.golden.json. Set RECORD_GOLDEN=1 to rewrite
 * that file.
 *
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 */
import { compileStyleAsync, parse } from '@vue/compiler-sfc'
import assert from 'node:assert/strict'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const GOLDEN = fileURLToPath(new URL('./fixtures/widgetStyles.golden.json', import.meta.url))
const RECORD = process.env.RECORD_GOLDEN === '1'
const FILES = [
	'src/widgets/PayrollPacksWidget.vue',
	'src/widgets/YearTransitionWidget.vue',
	'src/widgets/OnCallAverages.vue',
	'src/widgets/ApplicationAnswersWidget.vue',
	'src/integrations/CnHoursWidget.vue',
]

/**
 * The CSS of every style block of one SFC, normalised to one declaration per line.
 *
 * @param {string} rel The SFC path from the app root.
 * @return {Promise<Array<string>>} The compiled blocks.
 */
async function compiledStyles(rel) {
	const filename = join(ROOT, rel)
	const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename })
	const out = []
	for (const style of descriptor.styles) {
		const result = await compileStyleAsync({
			source: style.content,
			filename,
			id: 'data-v-golden',
			scoped: style.scoped,
			preprocessLang: style.lang,
		})
		assert.deepEqual(result.errors, [], rel)
		out.push(declarationsBySelector(result.code))
	}
	return out
}

/**
 * The value each property ends with for each single selector, per at-rule
 * context: a rule shared by a selector list counts for each selector, and a
 * later declaration of a property wins.
 *
 * @param {string} css Compiled CSS.
 * @return {object} Context, then selector, then property and value.
 */
function declarationsBySelector(css) {
	const out = {}
	const context = []
	let buffer = ''
	for (const char of css.replace(/\/\*[\s\S]*?\*\//g, '')) {
		if (char === '{') {
			context.push(buffer.trim().replace(/\s+/g, ' '))
			buffer = ''
		} else if (char === '}') {
			const head = context.pop()
			const declarations = buffer.split(';').map((d) => d.trim().replace(/\s+/g, ' ')).filter(Boolean)
			if (declarations.length > 0) {
				const at = context.join(' / ') || '(top)'
				out[at] ??= {}
				for (const selector of head.split(',').map((s) => s.trim())) {
					const effective = { ...(out[at][selector] ?? {}) }
					for (const declaration of declarations) {
						const colon = declaration.indexOf(':')
						effective[declaration.slice(0, colon).trim()] = declaration.slice(colon + 1).trim()
					}
					out[at][selector] = Object.fromEntries(Object.keys(effective).sort().map((k) => [k, effective[k]]))
				}
			}
			buffer = ''
		} else {
			buffer += char
		}
	}
	return out
}

const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(GOLDEN, 'utf8'))

for (const rel of FILES) {
	test(`the compiled styles of ${rel} are unchanged`, async () => {
		recorded[rel] = await compiledStyles(rel)
		if (!RECORD) {
			assert.deepEqual(recorded[rel], expected[rel])
		}
	})
}

test('write the golden file when recording', () => {
	if (RECORD) {
		writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
