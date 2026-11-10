<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;
use OCA\Libresign\Service\ShortValidationUrlService;
use OCA\Libresign\Service\ShortValidationCodeStore;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
class ShortValidationUrlServiceTest extends TestCase {
 private const UUID='8fe023cd-1f40-43d1-84c4-80cddfc672dc';
 public function testCodePreservesEveryUuidBitAndIsStable(): void {
  foreach([self::UUID,strtoupper(self::UUID),'00000000-0000-4000-8000-000000000001','ffffffff-ffff-ffff-ffff-ffffffffffff'] as $uuid){$code=ShortValidationUrlService::encode($uuid);$this->assertSame(22,strlen($code));$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/',$code);$this->assertSame(strtolower($uuid),ShortValidationUrlService::decode($code));}
  $this->assertSame(ShortValidationUrlService::encode(self::UUID),ShortValidationUrlService::encode(strtoupper(self::UUID)));
  for($i=0;$i<100;$i++){$hex=bin2hex(random_bytes(16));$uuid=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);$this->assertSame($uuid,ShortValidationUrlService::decode(ShortValidationUrlService::encode($uuid)));}
 }
 public static function badCodes(): array{return array_map(static fn($s)=>[$s],['','105','../../etc/passwd','https://evil.invalid','AAAAAAAAAAAAAAAAAAAAAB','AAAAAAAAAAAAAAAAAAAAAA==',str_repeat('a',21),str_repeat('a',23),str_repeat('/',22),str_repeat('_',21).'!']);}
 #[DataProvider('badCodes')]
 public function testRejectsMalformedAndNonCanonicalCodes(string $code):void{$this->assertNull(ShortValidationUrlService::decode($code));}
 public function testInvalidUuidCannotGenerateALink():void{$this->expectException(\InvalidArgumentException::class);ShortValidationUrlService::encode('105');}
 public static function environments():array{return [['https://nextcloud.sydani.org'],['https://documents.sydani.org']];}
 #[DataProvider('environments')]
 public function testUsesCurrentServerAndOnlyEnablesRootPathWhenConfigured(string $host):void{
  $urls=$this->createMock(IURLGenerator::class);$config=$this->createMock(IAppConfig::class);$enabled=false;
  $config->method('getValueBool')->willReturnCallback(static function($app,$key)use(&$enabled){return $key===ShortValidationUrlService::ROOT_ENABLED_KEY&&$enabled;});
  $code=ShortValidationUrlService::encode(self::UUID);
  $urls->expects($this->once())->method('linkToRouteAbsolute')->with('libresign.shortValidation.open',['code'=>$code])->willReturn($host.'/apps/libresign/v/'.$code);
  $urls->expects($this->once())->method('getAbsoluteURL')->with('/v/'.$code)->willReturn($host.'/v/'.$code);
  $service=new ShortValidationUrlService($urls,$config,$this->createMock(ShortValidationCodeStore::class));$this->assertSame($host.'/apps/libresign/v/'.$code,$service->forUuid(self::UUID));$enabled=true;$this->assertSame($host.'/v/'.$code,$service->forUuid(self::UUID));
 }

 #[DataProvider('environments')]
 public function testNewLinksUsePersistentEightCharacterGroupsOnCurrentHost(string $host): void {
  $urls=$this->createMock(IURLGenerator::class);$config=$this->createMock(IAppConfig::class);$codes=$this->createMock(ShortValidationCodeStore::class);
  $config->method('getValueBool')->willReturn(true);
  $codes->expects($this->once())->method('getOrCreate')->with(self::UUID)->willReturn(ShortValidationCodeStore::parse('SDs2-Vh4e'));
  $urls->expects($this->once())->method('getAbsoluteURL')->with('/v/SDs2-Vh4e')->willReturn($host.'/v/SDs2-Vh4e');
  $this->assertSame($host.'/v/SDs2-Vh4e',(new ShortValidationUrlService($urls,$config,$codes))->forUuid(strtoupper(self::UUID)));
 }
 public function testLegacyLinksResolveWithoutMappedStorageOrFeatureActivation(): void {
  $config=$this->createMock(IAppConfig::class);$config->method('getValueBool')->willReturn(false);$codes=$this->createMock(ShortValidationCodeStore::class);
  $codes->expects($this->never())->method('findUuid');
  $service=new ShortValidationUrlService($this->createMock(IURLGenerator::class),$config,$codes);
  $this->assertSame(self::UUID,$service->resolve(ShortValidationUrlService::encode(self::UUID)));
  $this->assertNull($service->resolve('SDs2-Vh4e'));
 }
 public function testExistingGroupedLinksStillResolveWhenNewGenerationIsDisabled(): void {
  $config=$this->createMock(IAppConfig::class);$config->method('getValueBool')->willReturnCallback(static fn($app,$key)=>$key===ShortValidationUrlService::STORE_READY_KEY);
  $codes=$this->createMock(ShortValidationCodeStore::class);$codes->expects($this->exactly(2))->method('findUuid')->with(ShortValidationCodeStore::parse('SDs2-Vh4e'))->willReturn(self::UUID);
  $service=new ShortValidationUrlService($this->createMock(IURLGenerator::class),$config,$codes);
  $this->assertSame(self::UUID,$service->resolve('SDs2-Vh4e'));
  $this->assertSame(self::UUID,$service->resolve('SDs2Vh4e'));
  $this->assertNull($service->resolve('SDs2_Vh4e'));
 }
 public function testUnknownAliasDoesNotInventAUuid(): void {
  $config=$this->createMock(IAppConfig::class);$config->method('getValueBool')->willReturn(true);$codes=$this->createMock(ShortValidationCodeStore::class);
  $codes->method('findUuid')->willReturn(null);
  $this->assertNull((new ShortValidationUrlService($this->createMock(IURLGenerator::class),$config,$codes))->resolve('SDs2-Vh4e'));
 }
 public function testGenerationRequiresPreparedStorage(): void {
  $config=$this->createMock(IAppConfig::class);$config->method('getValueBool')->willReturnCallback(static fn($app,$key)=>$key===ShortValidationUrlService::GROUPED_ENABLED_KEY);
  $codes=$this->createMock(ShortValidationCodeStore::class);$codes->expects($this->never())->method('getOrCreate');
  $this->expectException(\RuntimeException::class);
  (new ShortValidationUrlService($this->createMock(IURLGenerator::class),$config,$codes))->forUuid(self::UUID);
 }
}
