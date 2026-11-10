/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { parse as parseComponent } from '@vue/compiler-sfc'
import { compileString } from 'sass'
import { parse as parseCss } from 'postcss'

const compiledStyles = new Map<string, ReturnType<typeof parseCss>>()

function declarations(file: string, selector: string, mobile = false) {
	if (!compiledStyles.has(file)) {
		const source = readFileSync(new URL(file, import.meta.url), 'utf8')
		const { descriptor } = parseComponent(source)
		const css = compileString(descriptor.styles.filter(style => style.scoped).map(style => style.content).join('\n')).css
		compiledStyles.set(file, parseCss(css))
	}
	const values: Record<string, string> = {}
	compiledStyles.get(file)!.walkRules(selector, rule => {
		if (mobile && !(rule.parent?.type === 'atrule' && rule.parent.params === '(max-width: 512px)')) return
		rule.walkDecls(declaration => { values[declaration.prop] = declaration.value + (declaration.important ? ' !important' : '') })
	})
	return values
}

describe('mobile signing scroll layout', () => {
	it('bounds the sidebar scrollport instead of letting it grow above its origin', () => {
		const styles = declarations('../../../components/RightSidebar/RightSidebar.vue', '.app-sidebar', true)
		expect(styles).toMatchObject({ top: '0', bottom: '0', height: '100%', 'max-height': '100%', 'overflow-y': 'auto' })
		expect(styles.transform).toBe('none !important')
	})

	it('lets all tab contents contribute to the one outer scroll region', () => {
		expect(declarations('../../../components/RightSidebar/RightSidebar.vue', '.app-sidebar :deep(.app-sidebar-tabs)', true).flex).toBe('0 0 auto')
		expect(declarations('../../../components/RightSidebar/RightSidebar.vue', '.app-sidebar :deep(.app-sidebar-tabs__content)', true)).toMatchObject({ height: 'auto', 'min-height': '0' })
	})

	it('does not shrink or clip the signature and signing actions below a long notice', () => {
		const sign = '../../../views/SignPDF/_partials/Sign.vue'
		expect(declarations(sign, '.document-sign').height).toBe('auto')
		expect(declarations(sign, '.sign-elements').flex).toBe('0 0 auto')
		expect(declarations(sign, '.sign-elements').overflow).not.toBe('hidden')
		expect(declarations(sign, '.button-wrapper').flex).toBe('0 0 auto')
	})
})
