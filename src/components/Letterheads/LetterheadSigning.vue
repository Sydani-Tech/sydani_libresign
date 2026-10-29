<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
 <section class="letterhead-signing" :aria-label="t('libresign','Signing workflow')">
  <header><div><h3>{{ t('libresign','Signing workflow') }}</h3><p>{{ t('libresign','Place fields, send invitations and follow signing progress here. The approved document and recipient order stay fixed.') }}</p></div><NcButton :disabled="loading" @click="load">{{ t('libresign','Refresh signing status') }}</NcButton></header>
  <p v-if="loading" role="status">{{ t('libresign','Loading signing details…') }}</p>
  <div v-else-if="error" class="signing-error" role="alert"><strong>{{ error }}</strong><NcButton @click="load">{{ t('libresign','Try again') }}</NcButton></div>
  <template v-else-if="ready">
   <p class="signing-status" role="status"><strong>{{ t('libresign','Signing status') }}:</strong> {{ files.getFile()?.statusText }}</p>
   <RequestSignatureTab :key="requestId" :use-modal="true" :fixed-recipients="true" />
  </template>
 </section>
</template>
<script setup lang="ts">
import {onMounted,onBeforeUnmount,ref} from 'vue'
import {t} from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import RequestSignatureTab from '../RightSidebar/RequestSignatureTab.vue'
import {useFilesStore} from '../../store/files.js'
import {useSidebarStore} from '../../store/sidebar.js'
import {campaignPost,campaignError} from '../../services/campaigns'
const props=defineProps<{documentId:number}>()
const emit=defineEmits<{(event:'prepared',id:number):void}>()
const files=useFilesStore(),sidebar=useSidebarStore(),loading=ref(false),ready=ref(false),error=ref(''),requestId=ref(0)
let active=true,version=0
async function load(){
 if(loading.value)return
 const run=++version
 loading.value=true;ready.value=false;error.value=''
 try{
  const request=await campaignPost<{id:number,uuid:string}>('letterhead/documents/'+props.documentId+'/request')
  if(!active||run!==version)return
  requestId.value=request.id
  // Select only this approved request; wait for complete signer/field data before mounting the editor.
  const id=await files.selectFileByUuid(request.uuid)
  if(!active||run!==version){if(files.selectedFileId===request.id)files.selectFile();return}
  if(id!==request.id)throw new Error(t('libresign','Unable to load this document’s signing request.'))
  await files.fetchFileDetail({fileId:request.id,force:true})
  if(!active||run!==version)return
  sidebar.setActiveTab('')
  files.disableIdentifySigner()
  ready.value=true;emit('prepared',request.id)
 }catch(e){if(active&&run===version)error.value=e instanceof Error?e.message:campaignError(e)}
 finally{if(active&&run===version)loading.value=false}
}
onMounted(load)
onBeforeUnmount(()=>{active=false;version++;if(requestId.value&&files.selectedFileId===requestId.value)files.selectFile()})
</script>
<style scoped lang="scss">
.letterhead-signing{margin-block:28px;padding:24px;border:1px solid var(--color-primary-element);border-radius:14px;background:var(--color-main-background)}
header{display:flex;align-items:start;justify-content:space-between;gap:16px;flex-wrap:wrap}h3{font-size:21px;font-weight:650;margin-bottom:8px}p{line-height:1.6;margin-bottom:18px}.signing-error{padding:16px;border-inline-start:4px solid var(--color-error);background:var(--color-background-dark);display:flex;flex-wrap:wrap;gap:12px;align-items:center}.signing-status{padding:12px;background:var(--color-background-dark);border-radius:8px}
:deep(#request-signature-tab){width:100%;max-width:720px;margin-inline:auto}
@media(max-width:767px){.letterhead-signing{padding:16px 12px}}
</style>
