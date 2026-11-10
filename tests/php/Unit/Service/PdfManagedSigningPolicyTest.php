<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;
use OCA\Libresign\Service\PdfManagedSigningPolicy;
use OCA\Libresign\Exception\LibresignException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class PdfManagedSigningPolicyTest extends TestCase {
 private function signature(string $id,string $format='PAdES_BASELINE_LT'): array {return ['id'=>$id,'certificateId'=>'C-NEW','format'=>$format,'indication'=>'TOTAL_PASSED','timestampVerified'=>true,'timestampAnchored'=>true,'cryptographicIntegrity'=>true,'issuerTrusted'=>true,'revision'=>['checked'=>true,'byteRangeValid'=>true,'dictionaryConsistent'=>true,'undefinedChanges'=>0,'annotationChanges'=>0,'pageDifferences'=>0,'annotationOverlaps'=>0]];}
 private function report(array $signatures): array {return ['checked'=>true,'trustScope'=>'organisation','managedFormRevisions'=>'verified','signatures'=>$signatures];}
 public function testTimestampIntermediateMustBecomeLtBeforeCompletion(): void {
  $old=$this->signature('S-AA');$new=$this->signature('S-BB','PAdES_BASELINE_T');$report=$this->report([$old,$new]);
  $this->assertSame(['S-BB'],PdfManagedSigningPolicy::appended(['S-AA'=>$old],$report,'C-NEW',false));
  $this->expectException(LibresignException::class);PdfManagedSigningPolicy::appended(['S-AA'=>$old],$report,'C-NEW',true);
 }
 public function testUnclassifiedRedundantDssIntermediateCanOnlyBeExtendedNotCompleted(): void {
  $old=$this->signature('S-AA');$new=$this->signature('S-BB','PAdES_BASELINE_T');$new['revision']['checked']=false;$report=$this->report([$old,$new]);
  $this->assertSame(['S-BB'],PdfManagedSigningPolicy::appended(['S-AA'=>$old],$report,'C-NEW',false));
  $this->expectException(LibresignException::class);PdfManagedSigningPolicy::appended(['S-AA'=>$old],$report,'C-NEW',true);
 }
 public function testCompleteLtPreservesPriorIdsAndAcceptsOnlyCurrentCertificate(): void {
  $old=$this->signature('S-AA');$new=$this->signature('S-BB');$this->assertSame(['S-BB'],PdfManagedSigningPolicy::appended(['S-AA'=>$old],$this->report([$old,$new]),'C-NEW',true));
 }
 public static function invalid(): array {return [['cryptographicIntegrity'],['issuerTrusted'],['timestampVerified'],['timestampAnchored'],['byteRangeValid'],['dictionaryConsistent'],['undefinedChanges'],['annotationChanges'],['pageDifferences'],['annotationOverlaps'],['ownerProof'],['missingPrevious'],['certificate'],['downgrade']];}
 #[DataProvider('invalid')]
 public function testNeverWaivesMissingTrustTamperingOrOldSignatureChanges(string $fault): void {
  $old=$this->signature('S-AA');$new=$this->signature('S-BB');$report=$this->report([$old,$new]);
  if($fault==='ownerProof')$report['managedFormRevisions']='invalid';
  elseif($fault==='missingPrevious')$report['signatures']=[$new];
  elseif($fault==='certificate')$report['signatures'][1]['certificateId']='C-OTHER';
  elseif($fault==='downgrade')$report['signatures'][0]['format']='PAdES_BASELINE_T';
  elseif(in_array($fault,['byteRangeValid','dictionaryConsistent'],true))$report['signatures'][1]['revision'][$fault]=false;
  elseif(in_array($fault,['undefinedChanges','annotationChanges','pageDifferences','annotationOverlaps'],true))$report['signatures'][1]['revision'][$fault]=1;
  else $report['signatures'][1][$fault]=false;
  $this->expectException(LibresignException::class);PdfManagedSigningPolicy::appended(['S-AA'=>$old],$report,'C-NEW',true);
 }
}
