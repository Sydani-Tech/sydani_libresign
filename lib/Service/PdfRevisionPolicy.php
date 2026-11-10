<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
/** Reconcile verified LT evidence and preassigned form completion; unknown and page changes remain warnings. */
final class PdfRevisionPolicy {
 public static function reconcile(array $signers,array $report): array {
  $signed=array_filter($signers,static fn(array $s):bool=>!empty($s['signed']));$proof=$report['signatures']??[];
  if(empty($report['checked'])||($report['trustScope']??'')!=='organisation'||!$signed||count($signed)!==count($proof))return $signers;
  foreach($signed as $s)if(($s['signature_validation']['id']??null)!==1)return $signers;
  $managed=($report['managedFormRevisions']??'')==='verified';
  if(($report['managedFormRevisions']??'')==='invalid')return $signers;
  $extensions=0;$formChanges=0;
  foreach($proof as $p){$r=$p['revision']??[];
   if(($p['indication']??'')!=='TOTAL_PASSED'||!in_array($p['format']??'',['PAdES_BASELINE_LT','PAdES_BASELINE_LTA'],true)||empty($p['timestampVerified'])||($r['checked']??null)!==true||($r['byteRangeValid']??null)!==true||($r['dictionaryConsistent']??null)!==true)return $signers;
   foreach(['annotationChanges','undefinedChanges','pageDifferences','annotationOverlaps'] as $key)if(($r[$key]??null)!==0)return $signers;
   $forms=$r['signatureOrFormChanges']??null;$visual=$r['visualDifferences']??null;
   if(!is_int($forms)||$forms<0||!is_int($visual)||$visual<0||(($forms>0||$visual>0)&&!$managed))return $signers;
   $formChanges+=$forms;
   if(!isset($r['extensionChanges'])||!is_int($r['extensionChanges'])||$r['extensionChanges']<0)return $signers;$extensions+=$r['extensionChanges'];
  }
  if($extensions===0&&$formChanges===0)return $signers;
  foreach($signers as &$signer)if(!empty($signer['signed'])&&in_array($signer['modification_validation']['status']??null,[2,3],true)){$signer['legacy_modification_validation']=$signer['modification_validation'];$signer['modification_validation']=['valid'=>true,'status'=>2,'source'=>$formChanges>0?'dss-managed-form-revisions':'dss-pdf-revisions','message'=>$formChanges>0?'Later signers completed their pre-assigned fields. Earlier signed values, appearances and document pages were preserved.':'Only long-term validation evidence was added; signed content and document structure passed independent revision checks.'];}unset($signer);
  return $signers;
 }
}
