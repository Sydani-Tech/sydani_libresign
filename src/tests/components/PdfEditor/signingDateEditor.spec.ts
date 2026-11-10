/* SPDX-License-Identifier: AGPL-3.0-or-later */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import PdfEditor from '../../../components/PdfEditor/PdfEditor.vue'
const state = vi.hoisted(() => ({ object: null as any, update: vi.fn() }))
vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())
vi.mock('../../../helpers/pdfWorker.js', () => ({ ensurePdfWorker: vi.fn() }))
vi.mock('@libresign/pdf-elements', () => ({ default: { name: 'PDFElements', template: '<div><slot name="actions" :object="object" :actionAttrs="{}" /></div>', setup() { return { object: state.object, pdfDocuments: [{ allObjects: [[state.object]] }], updateObject: state.update } } } }))
const create = () => mount(PdfEditor, { props: { files: [], signers: [], readOnly: false }, global: { stubs: { NcButton: true, NcIconSvgWrapper: true, SignerMenu: true, SignatureBox: true, SigningFieldBox: true } } })
describe('Date field creator controls', () => {
 beforeEach(() => { state.object = reactive({ id: 'date-1', type: 'date', metadata: { label: 'Signed on', required: false } }); state.update.mockReset(); state.update.mockImplementation((_doc, _id, patch) => Object.assign(state.object, patch)) })
 it('sets automatic signing date independently of Required, and permits switching it off', async () => {
  const wrapper = create(); const labels = wrapper.findAll('label'); const automatic = labels.find(l => l.text().includes('Use signing date'))!.get('input');
  await automatic.setValue(true);
  expect(state.object.metadata).toEqual({ label: 'Signed on', required: false, useSigningDate: true, signingDateTimezone: 'Africa/Lagos' });
  await labels.find(l => l.text() === 'Required')!.get('input').setValue(true);
  expect(state.object.metadata.useSigningDate).toBe(true); expect(state.object.metadata.required).toBe(true);
  await automatic.setValue(false); expect(state.object.metadata.useSigningDate).toBe(false); expect(state.object.metadata.required).toBe(true); wrapper.unmount()
 })
 it('offers the option only on Date fields', () => { state.object.type = 'text'; const wrapper = create(); expect(wrapper.text()).not.toContain('Use signing date'); wrapper.unmount() })
})
