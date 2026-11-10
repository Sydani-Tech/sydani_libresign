<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;
use OCA\Libresign\Service\PdfSigningFieldsService;
use OCA\Libresign\Exception\LibresignException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class PdfSigningFieldsServiceTest extends TestCase {
 public function testAcceptsOnlyCanonicalAssignedSlotNames(): void {
  $manifest=PdfSigningFieldsService::readManifest("SIGNATURE_FIELD\t10\tlibresign_signature_10\nSIGNATURE_LAYOUT\tprepared\n",[['id'=>'10','value'=>true]]);
  $this->assertSame(['prepared'=>true,'signatureFields'=>[10=>'libresign_signature_10']],$manifest);
 }
 public function testLegacyModeCannotInventNewSlots(): void {$this->assertSame(['prepared'=>false,'signatureFields'=>[]],PdfSigningFieldsService::readManifest("SIGNATURE_LAYOUT\tlegacy\n",[['id'=>'10','value'=>true]]));}
 public static function invalid(): array {return [
  [''],["SIGNATURE_LAYOUT\tprepared\n"],["SIGNATURE_FIELD\t11\tlibresign_signature_11\nSIGNATURE_LAYOUT\tprepared"],
  ["SIGNATURE_FIELD\t10\tforeign_field\nSIGNATURE_LAYOUT\tprepared"],["SIGNATURE_FIELD\t10\tlibresign_signature_10\nSIGNATURE_FIELD\t10\tlibresign_signature_10\nSIGNATURE_LAYOUT\tprepared"],
  ["SIGNATURE_FIELD\t10\tlibresign_signature_10\nSIGNATURE_LAYOUT\tlegacy"],["SIGNATURE_LAYOUT\tlegacy\nSIGNATURE_LAYOUT\tprepared"],
 ];}
 #[DataProvider('invalid')]
 public function testRejectsMissingForeignDuplicateOrConflictingLayoutOutput(string $output): void {$this->expectException(LibresignException::class);PdfSigningFieldsService::readManifest($output,[['id'=>'10','value'=>true]]);}
}
