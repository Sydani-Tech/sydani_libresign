<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Service\{CampaignService, CampaignStore, CampaignAccessService, CampaignRecipientResolver, CampaignStorage, CampaignRequestContext, CampaignSigningPolicy, RequestSignatureService, IdentifyMethodService};
use OCA\Libresign\Exception\LibresignException;
use OCP\{IUser, IUserManager, IUserSession, IDBConnection};
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

final class CampaignServiceTest extends TestCase {
	private CampaignService $service;
	private CampaignStore $store;
	private IDBConnection $db;
	private CampaignSigningPolicy $planner;
	private RequestSignatureService $requests;
	private IJobList $jobs;
	private IUser $owner;
	private ?IUser $active;
	private IUser $administrator;
	private array $campaign;
	private array $rows;

	protected function setUp(): void {
		$this->owner = $this->createMock(IUser::class);
		$this->owner->method('getUID')->willReturn('owner');
		$this->administrator = $this->createMock(IUser::class);
		$this->active = $this->administrator;
		$this->db = $this->createMock(IDBConnection::class);
		$this->store = $this->getMockBuilder(CampaignStore::class)->setConstructorArgs([$this->db])->onlyMethods(['get', 'recipients', 'update', 'replaceDraftRecipients', 'activateRecipients', 'event'])->getMock();
		$this->campaign = ['id' => 17, 'owner_uid' => 'owner', 'name' => 'Synthetic', 'message' => '', 'status' => 'draft', 'revision' => 1, 'due_at' => null, 'counter' => 'null', 'source_hash' => 'hash', 'pdf_metadata' => json_encode(['d' => [['w' => 600, 'h' => 800]]]), 'fields' => json_encode([['role' => 'recipient', 'type' => 'signature', 'coordinates' => ['page' => 1, 'left' => 10, 'top' => 10, 'width' => 100, 'height' => 40]]])];
		$this->rows = [['id' => 1, 'name' => 'Recipient', 'email' => 'recipient@example.invalid', 'user_uid' => null, 'file_id' => null, 'queue_state' => 'failed', 'lease_token' => null]];
		$this->store->method('get')->willReturnCallback(fn () => $this->campaign);
		$this->store->method('recipients')->willReturnCallback(fn () => $this->rows);
		$access = $this->createMock(CampaignAccessService::class);
		$resolver = $this->createMock(CampaignRecipientResolver::class);
		$resolver->method('resolveMany')->willReturnCallback(fn ($rows) => array_map(static fn ($r) => $r + ['recipient_key' => $r['email']], $rows));
		$resolver->method('resolve')->willReturnCallback(static fn ($r) => $r);
		$storage = $this->createMock(CampaignStorage::class);
		$storage->method('verifiedContent')->willReturn('%PDF-synthetic');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('owner')->willReturn($this->owner);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->active);
		$session->method('setVolatileActiveUser')->willReturnCallback(function ($u): void { $this->active = $u; });
		$this->planner = $this->createMock(CampaignSigningPolicy::class);
		$this->requests = $this->createMock(RequestSignatureService::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->service = $this->getMockBuilder(CampaignService::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
		$this->service->method('get')->willReturn(['published' => true]);
		$dependencies = ['store' => $this->store, 'access' => $access, 'resolver' => $resolver, 'storage' => $storage, 'users' => $users, 'requests' => $this->requests, 'identify' => $this->createMock(IdentifyMethodService::class), 'jobs' => $this->jobs, 'requestPolicy' => $this->planner, 'requestContext' => new CampaignRequestContext($session, $access)];
		foreach ($dependencies as $name => $value) (new \ReflectionProperty(CampaignService::class, $name))->setValue($this->service, $value);
	}

	private function counter(): void {
		$this->campaign['counter'] = json_encode(['name' => 'Counter', 'email' => 'counter@example.invalid', 'uid' => null, 'recipient_key' => 'counter@example.invalid']);
		$fields = json_decode($this->campaign['fields'], true);
		$fields[] = $fields[0]; $fields[1]['role'] = 'counter';
		$this->campaign['fields'] = json_encode($fields);
	}

	public function testBlockedPublicationLeavesDraftAndQueueUntouchedAndRestoresAdministrator(): void {
		$this->counter();
		$this->planner->method('overrides')->willReturnCallback(function ($owner, $counter) { $this->assertSame($this->owner, $owner); $this->assertSame($owner, $this->active); $this->assertTrue($counter); throw new LibresignException('requires sequential signing', 422); });
		$this->db->expects($this->never())->method('beginTransaction');
		$this->store->expects($this->never())->method('update');
		$this->jobs->expects($this->never())->method('add');
		$this->requests->expects($this->never())->method('validateNewRequestToFile');
		try { $this->service->publish($this->administrator, 17, 1); $this->fail('Expected policy rejection'); } catch (LibresignException $e) { $this->assertSame(422, $e->getCode()); }
		$this->assertSame($this->administrator, $this->active);
	}

	public function testPublicationPreflightsOwnerPolicyAndPayloadWithoutUnnecessaryOverride(): void {
		$this->planner->expects($this->once())->method('overrides')->with($this->owner, false)->willReturn([]);
		$this->requests->expects($this->once())->method('validateNewRequestToFile')->willReturnCallback(function ($data): void { $this->assertSame($this->owner, $this->active); $this->assertSame($this->owner, $data['userManager']); $this->assertArrayNotHasKey('policyOverrides', $data); $this->assertSame('%PDF-synthetic', base64_decode($data['file']['base64'])); });
		$this->store->method('update')->willReturn(true);
		$this->db->expects($this->once())->method('commit');
		$this->jobs->expects($this->once())->method('add');
		$this->assertSame(['published' => true], $this->service->publish($this->administrator, 17, 1));
		$this->assertSame($this->administrator, $this->active);
	}

	public function testDelegatedCountersigningIsIncludedInPreflightBeforePublication(): void {
		$this->counter();
		$this->planner->expects($this->once())->method('overrides')->with($this->owner, true)->willReturn(['signature_flow' => 'ordered_numeric']);
		$this->requests->expects($this->once())->method('validateNewRequestToFile')->willReturnCallback(function ($data): void { $this->assertSame(['signature_flow' => 'ordered_numeric'], $data['policyOverrides']); $this->assertSame([1, 2], array_column($data['signers'], 'signingOrder')); });
		$this->store->method('update')->willReturn(true);
		$this->service->publish($this->administrator, 17, 1);
	}

	public function testBlockedRetryCannotQueueRequestsOrNotifications(): void {
		$this->counter(); $this->campaign['status'] = 'active';
		$this->planner->method('overrides')->willThrowException(new LibresignException('requires sequential signing', 422));
		$this->store->expects($this->never())->method('update');
		$this->store->expects($this->never())->method('event');
		$this->jobs->expects($this->never())->method('add');
		$this->expectException(LibresignException::class);
		$this->service->queueNotifications($this->administrator, 17, [], true);
	}

	public function testRetryingNotificationOfAnExistingRequestDoesNotRecreateItsPolicy(): void {
		$this->campaign['status'] = 'active'; $this->rows[0]['file_id'] = 100; $this->rows[0]['file_status'] = 1;
		$this->planner->expects($this->never())->method('overrides');
		$this->store->expects($this->once())->method('update')->willReturn(true);
		$this->assertSame(['queued' => 1], $this->service->queueNotifications($this->administrator, 17, [], true));
	}
}
