// SPDX-License-Identifier: AGPL-3.0-or-later
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Filters from '../../../components/DocumentFilters/DocumentFilters.vue'
import SenderSelect from '../../../components/DocumentFilters/SenderSelect.vue'
import { documentFilterDefaults, documentFilterParams } from '../../../services/documentFilters'
vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())
vi.mock('@nextcloud/vue/components/NcButton', () => ({default: {template: '<button><slot /></button>'}}))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({default: {name:'NcSelect',props:['modelValue','options','searchable','placeholder','clearable'],template:'<div />'}}))
const senders=[{id:'alice',name:'Alice Example'},{id:'bob',name:'Bob Example'}]
describe('Shared document filters',()=>{
 it('requires an existing sender choice and supports searchable clearing',async()=>{const w=mount(SenderSelect,{props:{modelValue:'',options:senders}});const select=w.findComponent({name:'NcSelect'});expect(select.props()).toMatchObject({searchable:true,clearable:true,placeholder:'All Senders',modelValue:null});select.vm.$emit('update:modelValue',senders[1]);expect(w.emitted('update:modelValue')?.[0]).toEqual(['bob']);select.vm.$emit('update:modelValue',null);expect(w.emitted('update:modelValue')?.[1]).toEqual([''])})
 it('does not apply typed filters before submit',async()=>{const w=mount(Filters,{props:{modelValue:documentFilterDefaults(),senders}});await w.find('input[type=search]').setValue('Handbook');expect(w.emitted('apply')).toBeUndefined();await w.find('form').trigger('submit');expect(w.emitted('apply')?.[0]?.[0]).toMatchObject({name:'Handbook',status:'all',dateBy:'requested'})})
 it('rejects a reversed date interval without applying',async()=>{const w=mount(Filters,{props:{modelValue:documentFilterDefaults(),senders}});const dates=w.findAll('input[type=date]');await dates[0].setValue('2026-10-20');await dates[1].setValue('2026-10-01');await w.find('form').trigger('submit');expect(w.emitted('apply')).toBeUndefined();expect(w.find('[role=alert]').text()).toContain('From date')})
 it('resets every filter and uses protected-document defaults in the archive',async()=>{const w=mount(Filters,{props:{modelValue:{...documentFilterDefaults(true),name:'old',sender:'alice'},senders,archive:true}});await w.findAll('button')[1].trigger('click');expect(w.emitted('apply')?.[0]?.[0]).toEqual(documentFilterDefaults(true));expect(w.text()).not.toContain('Draft')})
 it('converts whole-day date boundaries and keeps recipient name/email separate',()=>{const f={...documentFilterDefaults(),recipientName:'A',recipientEmail:'a@example.test',fromDate:'2026-10-01',toDate:'2026-10-02'};expect(documentFilterParams(f)).toMatchObject({recipientName:'A',recipientEmail:'a@example.test',from:Math.floor(new Date('2026-10-01T00:00:00').getTime()/1000),to:Math.floor(new Date('2026-10-02T23:59:59').getTime()/1000)});expect(documentFilterParams(documentFilterDefaults()).from).toBeUndefined()})
})
