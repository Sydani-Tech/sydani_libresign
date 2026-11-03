<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
use OCA\Libresign\Service\PdfCountersigningPolicy as Policy;
use OCA\Libresign\Exception\LibresignException;
require_once __DIR__ . '/../../lib/Exception/LibresignException.php';
require_once __DIR__ . '/../../lib/Service/PdfCountersigningPolicy.php';

$revision = ['checked'=>true,'byteRangeValid'=>true,'dictionaryConsistent'=>true,'extensionChanges'=>0,
 'signatureOrFormChanges'=>0,'annotationChanges'=>0,'undefinedChanges'=>0,'visualDifferences'=>0,'pageDifferences'=>0,'annotationOverlaps'=>0];
$old = ['id'=>'S-A1','indication'=>'INDETERMINATE','subIndication'=>'NO_CERTIFICATE_CHAIN_FOUND',
 'cryptographicIntegrity'=>true,'docMdp'=>'','fieldLocked'=>false,'issuerTrusted'=>false,'revision'=>$revision];
$new = ['id'=>'S-B2','indication'=>'TOTAL_PASSED','cryptographicIntegrity'=>true,'issuerTrusted'=>true,
 'timestampVerified'=>true,'certificateId'=>'C-TEST','format'=>'PAdES_BASELINE_LT','revision'=>$revision];
$before = Policy::existing(['checked'=>true,'signatures'=>[$old]]);
Policy::preflight($before, []);
$valid = ['checked'=>true,'signatures'=>[$old,$new]];
if (Policy::appended('original', 'original+LT', $before, $valid, 'C-TEST', true) !== ['S-B2']) throw new RuntimeException('Expected exactly the new signature');
$reject = static function (callable $test, string $name): void {
 try {$test();} catch (LibresignException) {echo "PASS: $name\n";return;}
 throw new RuntimeException("Unexpected acceptance: $name");
};
$reject(fn()=>Policy::preflight(['S-A1'=>array_replace($old,['fieldLocked'=>true])],[]),'existing field lock blocks signing');
$reject(fn()=>Policy::preflight(['S-A1'=>array_replace($old,['docMdp'=>'NO_CHANGE_PERMITTED'])],[]),'certification forbids countersigning');
$reject(fn()=>Policy::preflight($before,[['type'=>'date']]),'cannot add fillable fields');
$reject(fn()=>Policy::appended('original','rewritten+LT',$before,$valid,'C-TEST',true),'cannot rewrite original bytes');
$reject(fn()=>Policy::appended('original','original+LT',$before,['checked'=>true,'signatures'=>[$new]],'C-TEST',true),'cannot remove external signature');
$reject(fn()=>Policy::appended('original','original+LT',$before,['checked'=>true,'signatures'=>[array_replace($old,['revision'=>array_replace($revision,['undefinedChanges'=>1])]),$new]],'C-TEST',true),'cannot append unexplained changes');
$reject(fn()=>Policy::appended('original','original+T',$before,['checked'=>true,'signatures'=>[$old,array_replace($new,['format'=>'PAdES_BASELINE_T'])]],'C-TEST',true),'cannot publish an intermediate signature');
$reject(fn()=>Policy::appended('original','original+LT',$before,$valid,'C-WRONG',true),'cannot substitute signing certificate');
echo "PASS: an intact external signature can coexist with a newly verified LT signature\n";
