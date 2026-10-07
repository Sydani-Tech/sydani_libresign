/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { resolve } from 'node:path'

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
