<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Helper;

use OCA\Libresign\Helper\FrontendAssets;
use PHPUnit\Framework\TestCase;

class FrontendAssetsTest extends TestCase {
	protected function tearDown(): void {
		(new \ReflectionProperty(FrontendAssets::class, 'manifest'))->setValue(null, null);
		parent::tearDown();
	}

	private function resolve(array $manifest, string $type = 'script', string $application = 'libresign'): string {
		(new \ReflectionProperty(FrontendAssets::class, 'manifest'))->setValue(null, $manifest);
		return (new \ReflectionMethod(FrontendAssets::class, 'resolve'))->invoke(null, $application, 'libresign-main', $type);
	}

	public function testUsesMatchingHashedScriptAndStyle(): void {
		$manifest = ['main' => ['script' => 'libresign-main-Ab12cd34', 'style' => 'libresign-main-Ab12cd34']];
		$this->assertSame('libresign-main-Ab12cd34', $this->resolve($manifest));
		$this->assertSame('libresign-main-Ab12cd34', $this->resolve($manifest, 'style'));
	}

	public function testConventionalBuildAndOtherApplicationsRemainSupported(): void {
		$this->assertSame('libresign-main', $this->resolve([]));
		$this->assertSame('libresign-main', $this->resolve(['main' => ['script' => 'libresign-main-Ab12cd34']], 'script', 'files'));
	}

	public function testRejectsNonBasenameAndMalformedManifestEntries(): void {
		foreach (['../escape', 'https://example.invalid/x', ['bad'], 'libresign-other-Ab12cd34'] as $bad) {
			$this->assertSame('libresign-main', $this->resolve(['main' => ['script' => $bad]]));
		}
		$this->assertSame('libresign-main', $this->resolve(['main' => 'bad']));
	}
}
