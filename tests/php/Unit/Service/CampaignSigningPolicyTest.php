<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\CampaignSigningPolicy;
use OCA\Libresign\Service\Policy\Model\ResolvedPolicy;
use OCA\Libresign\Service\Policy\PolicyService;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CampaignSigningPolicyTest extends TestCase {
	public static function flows(): array {
		return [
			'no counter, locked none' => [false, 'none', false, [], []],
			'no counter, locked parallel' => [false, 'parallel', false, [], []],
			'counter, inherited ordered locked' => [true, 'ordered_numeric', false, [], []],
			'counter, delegated none' => [true, 'none', true, ['none', 'ordered_numeric'], ['signature_flow' => 'ordered_numeric']],
			'counter, delegated parallel' => [true, 'parallel', true, [], ['signature_flow' => 'ordered_numeric']],
			'counter, locked none' => [true, 'none', false, ['ordered_numeric'], null],
			'counter, sequential excluded' => [true, 'parallel', true, ['none', 'parallel'], null],
		];
	}

	#[DataProvider('flows')]
	public function testOnlyAuthorisedSequentialOverridesAreRequested(bool $counter, string $flow, bool $delegated, array $allowed, ?array $expected): void {
		$owner = $this->createMock(IUser::class);
		$resolved = (new ResolvedPolicy())->setEffectiveValue($flow)->setAllowedValues($allowed)->setCanUseAsRequestOverride($delegated);
		$policies = $this->createMock(PolicyService::class);
		$policies->expects($counter ? $this->once() : $this->never())->method('resolveForUser')->with('signature_flow', $owner)->willReturn($resolved);
		if ($expected === null) {
			$this->expectException(LibresignException::class);
			$this->expectExceptionCode(422);
			$this->expectExceptionMessage('requires sequential signing');
		}
		$result = (new CampaignSigningPolicy($policies))->overrides($owner, $counter);
		$this->assertSame($expected, $result);
	}
}
