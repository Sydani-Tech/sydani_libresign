/* SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
import {beforeEach, describe, expect, it, vi} from 'vitest'
import axios from '@nextcloud/axios'
import {campaignDate, campaignError, campaignGet, campaignPost, campaignPut, mergeCampaignRecipients} from '../../services/campaigns'
vi.mock('@nextcloud/axios', () => ({default: {get: vi.fn(), post: vi.fn(), put: vi.fn()}}))
vi.mock('@nextcloud/router', () => ({generateOcsUrl: (path: string) => '/ocs/v2.php' + path}))
describe('Campaign API and recipient helpers', () => {
  beforeEach(() => vi.clearAllMocks())
  it('unwraps the OCS envelope for reads and writes', async () => {
    vi.mocked(axios.get).mockResolvedValue({data:{ocs:{data:{id: 1}}}})
    vi.mocked(axios.post).mockResolvedValue({data:{ocs:{data:{id: 2}}}})
    vi.mocked(axios.put).mockResolvedValue({data:{ocs:{data:{revision: 3}}}})
    expect(await campaignGet('campaigns/1')).toEqual({id:1})
    expect(await campaignPost('campaigns', {campaign:{name:'Handbook'}})).toEqual({id:2})
    expect(await campaignPut('campaigns/1', {revision:2})).toEqual({revision:3})
    expect(axios.post).toHaveBeenCalledWith('/ocs/v2.php/apps/libresign/api/v1/campaigns', {campaign:{name:'Handbook'}})
  })
  it('deduplicates accounts and case-insensitive external addresses', () => {
    const merged = mergeCampaignRecipients([{name:'A',email:'A@example.test',uid:'a'}], [
      {name:'Again',email:'a@example.test',uid:'a'}, {name:'External',email:'B@example.test'}, {name:'Again',email:'b@example.test'},
    ])
    expect(merged.skipped).toBe(2)
    expect(merged.recipients).toHaveLength(2)
  })
  it('does not treat absent or invalid dates as completion', () => {
    expect(campaignDate(null)).toBe('—')
    expect(campaignDate('nonsense')).toBe('—')
    expect(campaignDate('2026-10-22 12:00:00')).toContain('Oct 2026')
    expect(campaignDate(1792670400)).not.toBe('—')
  })
  it('shows actionable API errors without arbitrary exception dumps', () => {
    expect(campaignError({response:{data:{ocs:{data:{message:'Account disabled'}}}}})).toBe('Account disabled')
    expect(campaignError(new Error('secret stack'))).not.toContain('secret')
  })
})
