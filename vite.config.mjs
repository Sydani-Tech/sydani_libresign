/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { basename, resolve } from 'node:path'
import { mkdir, readFile, rename, writeFile } from 'node:fs/promises'

const entries = {
	main: resolve('src/main.ts'),
	init: resolve('src/init.ts'),
	tab: resolve('src/tab.ts'),
	settings: resolve('src/settings.ts'),
	external: resolve('src/external.ts'),
	validation: resolve('src/validation.ts'),
}
const selectedEntry = process.env.LIBRESIGN_BUILD_ENTRY
if (selectedEntry && !Object.hasOwn(entries, selectedEntry)) {
	throw new Error(`Unknown LibreSign entry: ${selectedEntry}`)
}

export default createAppConfig(selectedEntry ? { [selectedEntry]: entries[selectedEntry] } : entries, {
	// Allow a lower-memory staging build without changing production's default.
	minify: process.env.LIBRESIGN_MINIFY !== 'false',
	emptyOutputDirectory: !selectedEntry,
	extractLicenseInformation: selectedEntry ? false : undefined,
	config: {
		build: {
			sourcemap: process.env.LIBRESIGN_SOURCEMAP !== 'false',
			rollupOptions: { output: { entryFileNames: 'js/libresign-[name]-[hash].mjs' } },
		},
		server: {
			port: 3000,
			host: '0.0.0.0',
		},
		resolve: {
			alias: {
				'@': resolve(import.meta.dirname, 'src'),
			},
		},
		plugins: [
			{
				name: 'libresign-php-asset-manifest',
				enforce: 'post',
				async writeBundle(_options, bundle) {
					const path = resolve('resources/frontend-assets.json')
					const manifest = selectedEntry ? await readFile(path, 'utf8').then(JSON.parse).catch(() => ({})) : {}
					for (const chunk of Object.values(bundle)) {
						if (chunk.type !== 'chunk' || !chunk.isEntry || !Object.hasOwn(entries, chunk.name)) continue
						const name = basename(chunk.fileName, '.mjs')
						const css = `css/${name}.css`
						manifest[chunk.name] = { script: name,
							...(await readFile(resolve(css)).then(() => true).catch(() => false) ? { style: name } : {}),
						}
					}
					await mkdir(resolve('resources'), { recursive: true })
					await writeFile(`${path}.tmp`, `${JSON.stringify(manifest, null, 2)}\n`)
					await rename(`${path}.tmp`, path)
				},
			},
			{
				name: 'vue-devtools',
				config(_, { mode }) {
					return {
						define: {
							__VUE_PROD_DEVTOOLS__: mode !== 'production',
						},
					}
				},
			},
		],
	},
})
