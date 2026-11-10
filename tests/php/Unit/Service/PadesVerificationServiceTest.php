<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;
use OCA\Libresign\Service\PadesVerificationService;
use PHPUnit\Framework\TestCase;
final class PadesVerificationServiceTest extends TestCase {
 public function testManagedProofRequiresOneExplicitCanonicalResult(): void {
  foreach(['verified','invalid','not_applicable'] as $state)$this->assertSame($state,PadesVerificationService::parseManagedFormRevisions("MANAGED_FORM_REVISIONS\t$state\n"));
  foreach(['',"MANAGED_FORM_REVISIONS\ttrue", "MANAGED_FORM_REVISIONS\tverified\nMANAGED_FORM_REVISIONS\tinvalid", "MANAGED_FORM_REVISIONS\tverified\nMANAGED_FORM_REVISIONS\tbogus"] as $output)$this->assertSame('unavailable',PadesVerificationService::parseManagedFormRevisions($output));
 }
}
