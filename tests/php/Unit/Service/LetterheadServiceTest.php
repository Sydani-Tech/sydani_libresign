<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;
use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Enum\SignatureFlow;
use OCA\Libresign\Service\{LetterheadService,LetterheadStorage,LetterheadPdfService,LetterheadNotificationService,AccountService,CampaignRecipientResolver,FileService,RequestSignatureService,IdentifyMethodService};
use OCA\Libresign\Service\File\Pdf\PdfMetadataExtractor;
use OCP\DB\QueryBuilder\{IQueryBuilder,IExpressionBuilder};
use OCP\{IDBConnection,IGroupManager,IUserManager,IUser};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
class LetterheadServiceTest extends TestCase {
 private function fixture(array $replace=[],int $recipientCount=1): array {
  $db=$this->createMock(IDBConnection::class);$q=$this->createMock(IQueryBuilder::class);$db->method('getQueryBuilder')->willReturn($q);
  foreach(['update','set','where','andWhere','insert','setValue'] as $method)$q->method($method)->willReturnSelf();
  $q->method('executeStatement')->willReturn(1);$q->method('createNamedParameter')->willReturn(':p');$q->method('getLastInsertId')->willReturn(1);
  $expr=$this->createMock(IExpressionBuilder::class);$expr->method('eq')->willReturn('condition');$expr->method('isNull')->willReturn('condition');$q->method('expr')->willReturn($expr);
  $user=$this->createMock(IUser::class);$user->method('getUID')->willReturn('owner');$user->method('isEnabled')->willReturn(true);
  $accounts=$this->createMock(AccountService::class);$accounts->method('canRequestSign')->willReturn(true);
  $storage=$this->createMock(LetterheadStorage::class);$node=$this->createMock(\OCP\Files\File::class);$node->method('getContent')->willReturn('%PDF-approved');$storage->method('verified')->willReturn($node);$storage->method('write')->willReturn($node);
  $recipient=['recipient_key'=>'email:guest@example.invalid','uid'=>null,'email'=>'guest@example.invalid','name'=>'Approved guest'];
  $recipients=[$recipient];if($recipientCount===2)$recipients[]=['recipient_key'=>'email:guest2@example.invalid','uid'=>null,'email'=>'guest2@example.invalid','name'=>'Second approved guest'];
  $resolver=$this->createMock(CampaignRecipientResolver::class);$resolver->method('resolve')->willReturnArgument(0);
  $requests=$this->createMock(RequestSignatureService::class);$mapper=$this->createMock(FileMapper::class);$identity=$this->createMock(IdentifyMethodService::class);
  $empty=static fn(string $class)=>(new \ReflectionClass($class))->newInstanceWithoutConstructor();
  $letters=$this->getMockBuilder(LetterheadService::class)->onlyMethods(['authorised'])->setConstructorArgs([$db,$this->createMock(IGroupManager::class),$this->createMock(IUserManager::class),$accounts,$storage,$empty(LetterheadPdfService::class),$resolver,$empty(FileService::class),$empty(PdfMetadataExtractor::class),$requests,$mapper,$empty(LetterheadNotificationService::class),$identity])->getMock();
  $document=array_replace(['id'=>15,'owner_uid'=>'owner','state'=>'approved','file_id'=>null,'recipients'=>json_encode($recipients),'composed_file'=>'approved.pdf','composed_hash'=>hash('sha256','%PDF-approved'),'name'=>'Approved letterhead','revision'=>3,'template_id'=>1,'approved_at'=>123,'approver_uid'=>'approver'],$replace);
  $letters->method('authorised')->with($user,15)->willReturn($document);
  return compact('letters','user','db','requests','mapper','identity','storage','node','document');
 }
 public static function inheritedFlows(): array {return [['none',1],['none',2],['ordered_numeric',2]];}
 #[DataProvider('inheritedFlows')]
 public function testPreparationDoesNotForceARequestPolicyOverride(string $flow,int $recipientCount): void {
  $f=$this->fixture([], $recipientCount);$file=new File();$file->setId(123);$file->setUuid('prepared-uuid');$file->setUserId('owner');$file->setStatus(0);
  $f['identity']->expects($this->once())->method('clearCache');$f['identity']->expects($this->once())->method('setIsRequest')->with(true);
  $assertData=function(array $data)use($f):void {
   $this->assertArrayNotHasKey('policyOverrides',$data);$this->assertSame($f['user'],$data['userManager']);$this->assertSame(0,$data['status']);
   $expected=[];foreach(json_decode($f['document']['recipients'],true) as $i=>$recipient)$expected[]=['displayName'=>$recipient['name'],'notify'=>0,'signingOrder'=>$i+1,'identifyMethods'=>[['method'=>'email','value'=>$recipient['email']]]];$this->assertSame($expected,$data['signers']);
  };
  $f['requests']->expects($this->once())->method('validateNewRequestToFile')->willReturnCallback(function(array $data)use($assertData):void{$assertData($data);$this->assertSame('%PDF-approved',base64_decode($data['file']['base64']));});
  $f['requests']->expects($this->once())->method('save')->willReturnCallback(function(array $data)use($assertData,$file,$flow,$f):File{$assertData($data);$this->assertSame($f['node'],$data['file']['fileNode']);$file->setSignatureFlowEnum(SignatureFlow::from($flow));return $file;});
  $f['mapper']->expects($this->once())->method('update')->with($file)->willReturn($file);$f['db']->expects($this->once())->method('commit');
  $this->assertSame(['id'=>123,'uuid'=>'prepared-uuid'],$f['letters']->prepareRequest($f['user'],15));
  $this->assertSame($flow,$file->getSignatureFlowEnum()->value);$this->assertSame($f['document']['composed_hash'],$file->getMetadata()['letterhead']['sourceHash']);
 }
 public function testUnapprovedDocumentCannotPrepareSigning(): void {
  $f=$this->fixture(['state'=>'pending']);$f['requests']->expects($this->never())->method('save');$f['storage']->expects($this->never())->method('write');
  $this->expectException(\OCP\Files\NotPermittedException::class);$f['letters']->prepareRequest($f['user'],15);
 }
 public function testPreparingAnExistingRequestReusesIt(): void {
  $f=$this->fixture(['file_id'=>123]);$file=new File();$file->setId(123);$file->setUuid('prepared-uuid');$f['mapper']->method('getById')->with(123)->willReturn($file);
  $f['requests']->expects($this->never())->method('save');$f['identity']->expects($this->never())->method('clearCache');
  $this->assertSame(['id'=>123,'uuid'=>'prepared-uuid'],$f['letters']->prepareRequest($f['user'],15));
 }
 public function testFailedPreparationRollsBackTheBinding(): void {
  $f=$this->fixture();$f['requests']->method('save')->willThrowException(new \OCA\Libresign\Exception\LibresignException('Policy validation failed.',422));
  $f['db']->expects($this->once())->method('rollBack');$f['db']->expects($this->never())->method('commit');$f['mapper']->expects($this->never())->method('update');
  $this->expectException(\OCA\Libresign\Exception\LibresignException::class);$f['letters']->prepareRequest($f['user'],15);
 }
}
