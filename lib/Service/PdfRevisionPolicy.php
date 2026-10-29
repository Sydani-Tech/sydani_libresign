<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
/** Reconcile only independently verified, extension-only LT revisions. Never waive a page/form/content change. */
final class PdfRevisionPolicy {
 public static function reconcile(array $signers,array $report): array {
  $signed=array_filter($signers,static fn(array $s):bool=>!empty($s['signed']));$proof=$report['signatures']??[];
  if(empty($report['checked'])||($report['trustScope']??'')!=='organisation'||!$signed||count($signed)!==count($proof))return $signers;
  foreach($signed as $s)if(($s['signature_validation']['id']??null)!==1)return $signers;
  $extensions=0;
  foreach($proof as $p){$r=$p['revision']??[];
   if(($p['indication']??'')!=='TOTAL_PASSED'||!in_array($p['format']??'',['PAdES_BASELINE_LT','PAdES_BASELINE_LTA'],true)||empty($p['timestampVerified'])||($r['checked']??null)!==true||($r['byteRangeValid']??null)!==true||($r['dictionaryConsistent']??null)!==true)return $signers;
   foreach(['signatureOrFormChanges','annotationChanges','undefinedChanges','visualDifferences','pageDifferences','annotationOverlaps'] as $key)if(($r[$key]??null)!==0)return $signers;
   if(!isset($r['extensionChanges'])||!is_int($r['extensionChanges'])||$r['extensionChanges']<0)return $signers;$extensions+=$r['extensionChanges'];
  }
  if($extensions===0)return $signers;
  foreach($signers as &$signer)if(!empty($signer['signed'])&&($signer['modification_validation']['status']??null)===3){$signer['legacy_modification_validation']=$signer['modification_validation'];$signer['modification_validation']=['valid'=>true,'status'=>2,'source'=>'dss-pdf-revisions','message'=>'Only long-term validation evidence was added; signed content and document structure passed independent revision checks.'];}unset($signer);
  return $signers;
 }
}
