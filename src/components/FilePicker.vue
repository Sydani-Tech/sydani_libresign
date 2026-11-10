<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
 <div class="file-picker" :class="{'file-picker--disabled': disabled}">
  <label class="file-picker__surface">
   <input :aria-label="label" type="file" :accept="accept" :disabled="disabled" :required="required" @change="$emit('change', $event)">
   <span class="file-picker__icon" aria-hidden="true"><NcIconSvgWrapper :path="filename ? mdiFileCheckOutline : mdiCloudUploadOutline" :size="28" /></span>
   <span class="file-picker__details"><strong>{{ filename || label }}</strong><small>{{ hint || t('libresign', 'Choose a file from your device') }}</small></span>
   <span class="file-picker__button">{{ filename ? t('libresign', 'Replace file') : t('libresign', 'Choose file') }}</span>
  </label>
 </div>
</template>
<script setup lang="ts">
import {t} from '@nextcloud/l10n'
import {mdiCloudUploadOutline,mdiFileCheckOutline} from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
withDefaults(defineProps<{label:string,filename?:string,hint?:string,accept?:string,disabled?:boolean,required?:boolean}>(),{filename:'',hint:'',accept:'',disabled:false,required:false})
defineEmits<{(event:'change',value:Event):void}>()
</script>
<style scoped lang="scss">
.file-picker { min-width:0; width:100%; }
.file-picker__surface { position:relative; display:flex; flex-flow:row wrap; align-items:center; gap:12px; min-height:84px; margin:0; padding:16px; box-sizing:border-box; border:1px dashed var(--color-primary-element); border-radius:12px; background:var(--color-primary-element-light); cursor:pointer; }
.file-picker__surface:hover { background:var(--color-background-hover); }
.file-picker__surface:focus-within { outline:2px solid var(--color-primary-element); outline-offset:3px; }
.file-picker__surface input { position:absolute; inset:0; opacity:0; width:100%; height:100%; margin:0; cursor:pointer; z-index:1; }
.file-picker__icon { display:flex; color:var(--color-primary-element); }
.file-picker__details { flex:1 1 140px; min-width:0; display:flex; flex-direction:column; gap:5px; }
.file-picker__details strong { font-size:14px; overflow-wrap:anywhere; line-height:1.5; }
.file-picker__details small { color:var(--color-text-maxcontrast); font-size:12px; line-height:1.5; }
.file-picker__button { display:flex; align-items:center; min-height:44px; padding:0 14px; border:1px solid var(--color-primary-element); border-radius:8px; background:var(--color-main-background); color:var(--color-main-text); font-weight:600; font-size:14px; }
.file-picker--disabled { opacity:.65; }.file-picker--disabled .file-picker__surface,.file-picker--disabled input { cursor:not-allowed; }
</style>
