<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Policy;

use OCA\Libresign\Service\Policy\Model\ResolvedPolicy;
use OCA\Libresign\Service\Policy\PolicyService;
use OCA\Libresign\Service\Policy\Provider\RequestSignGroups\RequestSignGroupsPolicy;
use OCA\Libresign\Service\Policy\RequestSignAuthorizationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RequestSignAuthorizationServiceTest extends TestCase {
	private PolicyService&MockObject $policyService;
	private IGroupManager&MockObject $groupManager;
	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->policyService = $this->createMock(PolicyService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}

	public function testDeniesWhenUserIsNotAuthenticated(): void {
		$service = new RequestSignAuthorizationService($this->policyService, $this->groupManager, $this->appConfig);
		$this->assertFalse($service->canRequestSign(null));
	}

	public function testAllowsWhenUserBelongsToAuthorizedGroup(): void {
		$user = $this->createMock(IUser::class);
		$resolvedPolicy = (new ResolvedPolicy())->setEffectiveValue('{"allowGroups":["admin","finance"],"denyGroups":[]}');

		$this->policyService
			->expects($this->once())
			->method('resolveForUser')
			->with(RequestSignGroupsPolicy::KEY, $user)
			->willReturn($resolvedPolicy);

		$this->groupManager
			->expects($this->once())
			->method('getUserGroupIds')
			->with($user)
			->willReturn(['finance']);

		$service = new RequestSignAuthorizationService($this->policyService, $this->groupManager, $this->appConfig);
		$this->assertTrue($service->canRequestSign($user));
	}

	public function testDeniesWhenUserDoesNotBelongToAuthorizedGroups(): void {
		$user = $this->createMock(IUser::class);
		$resolvedPolicy = (new ResolvedPolicy())->setEffectiveValue('{"allowGroups":["admin","finance"],"denyGroups":[]}');

		$this->policyService
			->expects($this->once())
			->method('resolveForUser')
			->with(RequestSignGroupsPolicy::KEY, $user)
			->willReturn($resolvedPolicy);

		$this->groupManager
			->expects($this->once())
			->method('getUserGroupIds')
			->with($user)
			->willReturn(['sales']);

		$service = new RequestSignAuthorizationService($this->policyService, $this->groupManager, $this->appConfig);
		$this->assertFalse($service->canRequestSign($user));
	}

	public function testDeniesWhenUserBelongsToDeniedGroupEvenIfAllowed(): void {
		$user = $this->createMock(IUser::class);
		$resolvedPolicy = (new ResolvedPolicy())->setEffectiveValue('{"allowGroups":["board"],"denyGroups":["board"]}');

		$this->policyService
			->expects($this->once())
			->method('resolveForUser')
			->with(RequestSignGroupsPolicy::KEY, $user)
			->willReturn($resolvedPolicy);

		$this->groupManager
			->expects($this->once())
			->method('getUserGroupIds')
			->with($user)
			->willReturn(['board']);

		$service = new RequestSignAuthorizationService($this->policyService, $this->groupManager, $this->appConfig);
		$this->assertFalse($service->canRequestSign($user));
	}

	public function testAllowsAuthenticatedUserWhenAllSignedInAccessIsEnabled(): void {
		$this->appConfig->method('getValueBool')->with('libresign', 'allow_all_signed_in_request_sign', true)->willReturn(true);
		$this->policyService->expects($this->never())->method('resolveForUser');
		$service = new RequestSignAuthorizationService($this->policyService, $this->groupManager, $this->appConfig);
		$this->assertTrue($service->canRequestSign($this->createMock(IUser::class)));
	}

	public function testAllSignedInAccessStillRejectsAnonymousRequests(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->policyService->expects($this->never())->method('resolveForUser');
		$service = new RequestSignAuthorizationService($this->policyService, $this->groupManager, $this->appConfig);
		$this->assertFalse($service->canRequestSign(null));
	}
}
