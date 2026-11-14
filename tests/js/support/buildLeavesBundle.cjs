/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Builds only the `leaves` entry of webpack.config.js, in production mode,
 * into the directory given as the second argument, so a test can measure the
 * file OpenRegister's LeafScriptListener looks for without writing into js/
 * or building the whole SPA.
 *
 * It also writes `modules.json` beside the bundle: the source modules webpack
 * put into it, so the test can see what reached another app's page.
 *
 * Usage: node buildLeavesBundle.cjs <repo> <outDir>
 *
 * @spec openspec/changes/hours-leaf-loads-on-a-consuming-page/specs/hours-leaf-delivery/spec.md#requirement-humaniq-ships-a-leaves-bundle-req-hld-001
 */
const fs = require('fs')
const path = require('path')

const [repo, out] = process.argv.slice(2)
const pkg = require(path.join(repo, 'package.json'))

// @nextcloud/webpack-vue-config reads these from npm's environment.
process.env.NODE_ENV = 'production'
process.env.npm_package_name = pkg.name
process.env.npm_package_version = pkg.version
process.chdir(repo)

const webpack = require(path.join(repo, 'node_modules', 'webpack'))
const config = require(path.join(repo, 'webpack.config.js'))

if (!config.entry || !config.entry.leaves) {
	console.error('webpack.config.js declares no leaves entry')
	process.exit(2)
}

config.entry = { leaves: config.entry.leaves }
config.output = { ...config.output, path: out }
config.devtool = false

webpack(config, (error, stats) => {
	if (error || stats.hasErrors()) {
		console.error(error || stats.toString('errors-only'))
		process.exit(1)
	}

	const modules = stats.toJson({ all: false, modules: true, nestedModules: true }).modules || []
	const names = []
	const walk = (list) => list.forEach((module) => {
		if (module.name) names.push(module.name)
		if (module.modules) walk(module.modules)
	})
	walk(modules)
	fs.writeFileSync(path.join(out, 'modules.json'), JSON.stringify(names, null, '\t'))
})
