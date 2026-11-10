<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Libresign\Tests\Unit\Service\Policy\Provider\SignatureText;

use OCA\Libresign\Service\Policy\Provider\SignatureText\SignatureTextPolicyValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureTextPolicyValueTest extends TestCase {
	public static function legacyModes(): array {
		return [['GRAPHIC_ONLY','graphic'],['DESCRIPTION_ONLY','description_only'],['SIGNAME_AND_DESCRIPTION','text'],['GRAPHIC_AND_DESCRIPTION','default']];
	}

	#[DataProvider('legacyModes')]
	public function testPreservesLegacyAppearanceWhenEncodingPolicy(string $legacy, string $expected): void {
		$value = SignatureTextPolicyValue::normalize(['render_mode'=>$legacy,'background_type'=>' DELETED ','signature_width'=>180,'signature_height'=>64]);
		$this->assertSame($expected,$value['render_mode']);
		$this->assertSame('deleted',$value['background_type']);
		$this->assertSame(180.0,$value['signature_width']);
		$this->assertSame(64.0,$value['signature_height']);
		$this->assertSame($value,SignatureTextPolicyValue::normalize(SignatureTextPolicyValue::encode($value)));
	}
}
