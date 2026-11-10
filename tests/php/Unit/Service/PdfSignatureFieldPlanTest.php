<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;
use OCA\Libresign\Db\{FileElement,SignRequest};
use OCA\Libresign\Service\PdfSignatureFieldPlan;
use PHPUnit\Framework\TestCase;
final class PdfSignatureFieldPlanTest extends TestCase {
 public function testReservesAllVisibleAndInvisibleParticipantsWithoutExposingOtherValues(): void {
  $marker=new FileElement();foreach(['getId'=>10,'getSignRequestId'=>1,'getType'=>'signature','getPage'=>1,'getLlx'=>20,'getLly'=>30,'getUrx'=>120,'getUry'=>60,'getMetadata'=>['label'=>'Signature']] as $method=>$value)$marker->{'set'.substr($method,3)}($value);
  $text=new FileElement();$text->setType('text');
  $a=new SignRequest();$a->setId(1);$b=new SignRequest();$b->setId(2);
  $plan=PdfSignatureFieldPlan::build([$marker,$text],[$a,$b],2);
  $this->assertCount(2,$plan);$this->assertSame('10',$plan[0]['id']);$this->assertSame(1,$plan[0]['owner']);$this->assertArrayNotHasKey('value',$plan[0]);
  $this->assertSame('s2',$plan[1]['id']);$this->assertSame(2,$plan[1]['owner']);$this->assertSame(true,$plan[1]['value']);$this->assertSame([0,0,0,0],array_values(array_intersect_key($plan[1],array_flip(['llx','lly','urx','ury']))));
 }
 public function testSeveralSignatureMarkersForOneSignerDoNotCreateAnExtraInvisibleSlot(): void {
  $markers=[];foreach([10,11] as $id){$m=new FileElement();$m->setId($id);$m->setSignRequestId(1);$m->setType('initial');$m->setPage(1);$markers[]=$m;}
  $request=new SignRequest();$request->setId(1);$plan=PdfSignatureFieldPlan::build($markers,[$request],1);
  $this->assertSame(['10','11'],array_column($plan,'id'));$this->assertSame([true,true],array_column($plan,'value'));
 }
}
