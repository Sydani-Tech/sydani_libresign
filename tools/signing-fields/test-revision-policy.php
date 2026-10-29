<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
require_once __DIR__.'/../../lib/Service/PdfRevisionPolicy.php';
use OCA\Libresign\Service\PdfRevisionPolicy;
$count=0;
$assert=static function(bool $ok,string $message)use(&$count):void{if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";};
$s=[['signed'=>'2026-10-29T19:46:43Z','signature_validation'=>['id'=>1],'modification_validation'=>['status'=>3,'valid'=>false]]];
$r=['checked'=>true,'trustScope'=>'organisation','signatures'=>[['indication'=>'TOTAL_PASSED','format'=>'PAdES_BASELINE_LT','timestampVerified'=>true,'revision'=>['checked'=>true,'byteRangeValid'=>true,'dictionaryConsistent'=>true,'extensionChanges'=>1,'signatureOrFormChanges'=>0,'annotationChanges'=>0,'undefinedChanges'=>0,'visualDifferences'=>0,'pageDifferences'=>0,'annotationOverlaps'=>0]]]];
$clear=static fn(array $signers,array $report):bool=>(PdfRevisionPolicy::reconcile($signers,$report)[0]['modification_validation']['valid']??false)===true;
$assert($clear($s,$r),'independently verified extension-only revision is recognised');
$assert(isset(PdfRevisionPolicy::reconcile($s,$r)[0]['legacy_modification_validation']),'original checker result is preserved for audit');
foreach(['signatureOrFormChanges','annotationChanges','undefinedChanges','visualDifferences','pageDifferences','annotationOverlaps'] as $key){$bad=$r;$bad['signatures'][0]['revision'][$key]=1;$assert(!$clear($s,$bad),"$key cannot be hidden by validation evidence");}
foreach(['checked','byteRangeValid','dictionaryConsistent'] as $key){$bad=$r;$bad['signatures'][0]['revision'][$key]=false;$assert(!$clear($s,$bad),"failed $key cannot be waived");}
$bad=$r;unset($bad['signatures'][0]['revision']['undefinedChanges']);$assert(!$clear($s,$bad),'missing analysis fails closed');
$bad=$r;$bad['signatures'][0]['indication']='TOTAL_FAILED';$assert(!$clear($s,$bad),'failed independent signature cannot be waived');
$bad=$s;$bad[0]['signature_validation']['id']=3;$assert(!$clear($bad,$r),'native digest failure cannot be waived');
$assert(!$clear([$s[0],$s[0]],$r),'evidence must cover all signed participants');
$bad=$r;$bad['signatures'][0]['revision']['extensionChanges']=0;$assert(!$clear($s,$bad),'unexplained changes are never cleared');
$bad=$r;$bad['checked']=false;$assert(!$clear($s,$bad),'unavailable verifier cannot clear warnings');
if(isset($argv[1])){foreach(['original'=>true,'page-changed'=>false,'byte-changed'=>false] as $file=>$expected){$report=$r;$report['signatures']=[];$revisions=[];foreach(file($argv[1].'/'.$file.'.report',FILE_IGNORE_NEW_LINES) as $line){$v=explode("\t",$line);if($v[0]==='REVISION'&&count($v)===12){$keys=['extensionChanges','signatureOrFormChanges','annotationChanges','undefinedChanges','visualDifferences','pageDifferences','annotationOverlaps'];$rev=['checked'=>$v[2]==='1','byteRangeValid'=>$v[3]==='1','dictionaryConsistent'=>$v[4]==='1'];foreach($keys as $i=>$key)$rev[$key]=(int)$v[$i+5];$revisions[$v[1]]=$rev;}if($v[0]==='SIGNATURE'&&count($v)===8)$report['signatures'][]=['id'=>$v[1],'indication'=>$v[4],'format'=>$v[3],'timestampVerified'=>$v[6]==='1'];}foreach($report['signatures'] as &$p)$p['revision']=$revisions[$p['id']]??[];unset($p);$assert($clear($s,$report)===$expected,"actual PDF fixture $file gives expected policy result");}}
echo json_encode(['checksPassed'=>$count])."\n";
