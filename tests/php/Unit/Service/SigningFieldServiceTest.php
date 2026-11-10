<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\SigningFieldService;
use OCA\Libresign\Tests\Unit\TestCase;

final class SigningFieldServiceTest extends TestCase {
	public function testSigningDateUsesServerInstantAndIgnoresClientDate(): void {
		$field = new FileElement(); $field->setId(77); $field->setType('date');
		$field->setMetadata(['required' => true, 'useSigningDate' => true, 'signingDateTimezone' => 'Africa/Lagos']);
		$when = new \DateTimeImmutable('2026-10-26T23:30:00Z');
		$this->assertSame('', SigningFieldService::submittedValue($field, []));
		$this->assertSame('2026-10-27', SigningFieldService::submittedValue($field, [['documentElementId' => 77, 'value' => '1999-12-31']], $when));
		$this->assertSame('2026-10-26T23:30:00+00:00', $when->format(DATE_ATOM));
	}

	public function testSigningDateCannotBeEnabledForText(): void {
		$this->expectException(LibresignException::class);
		SigningFieldService::definition(['useSigningDate' => true], 'text');
	}

	public function testValidValuesIncludingUnicode(): void {
		$this->assertSame('Ékomobong', SigningFieldService::validateValue('text', ['required' => true], ' Ékomobong '));
		$this->assertSame('2026-10-06', SigningFieldService::validateValue('date', ['required' => true], '2026-10-06'));
		$this->assertTrue(SigningFieldService::validateValue('checkbox', ['required' => true], true));
	}

	public function testRequiredCheckboxCannotBeSkipped(): void {
		$this->expectException(LibresignException::class);
		SigningFieldService::validateValue('checkbox', ['label' => 'Consent', 'required' => true], false);
	}

	public function testOptionalFieldsCanBeSkipped(): void {
		$this->assertSame('', SigningFieldService::validateValue('text', ['required' => false], ''));
		$this->assertSame('', SigningFieldService::validateValue('date', ['required' => false], ''));
		$this->assertFalse(SigningFieldService::validateValue('checkbox', ['required' => false], false));
	}

	public function testInvalidDateCannotBeSigned(): void {
		$this->expectException(LibresignException::class);
		SigningFieldService::validateValue('date', ['required' => true], '2026-02-30');
	}

	public function testControlCharactersCannotBeSigned(): void {
		$this->expectException(LibresignException::class);
		SigningFieldService::validateValue('text', ['required' => true], "Approved\nChanged");
	}

	public function testDuplicateValuesAreRejected(): void {
		$field = new FileElement();
		$field->setId(77);
		$field->setType('text');
		$field->setMetadata(['label' => 'Name', 'required' => true]);
		$this->expectException(LibresignException::class);
		SigningFieldService::submittedValue($field, [
			['documentElementId' => 77, 'value' => 'One'],
			['documentElementId' => 77, 'value' => 'Two'],
		]);
	}
}
