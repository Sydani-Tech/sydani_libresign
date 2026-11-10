<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;
use OCA\Libresign\Service\LetterheadApprovalDashboardService;
use OCA\Libresign\Service\LetterheadService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
class LetterheadApprovalDashboardServiceTest extends TestCase {
 public function testNonApproverIncludingAdministratorGetsNoCount(): void {
  $db=$this->createMock(IDBConnection::class);$db->expects($this->never())->method('getQueryBuilder');
  $groups=$this->createMock(IGroupManager::class);$groups->expects($this->once())->method('isInGroup')->with('administrator',LetterheadService::APPROVER_GROUP)->willReturn(false);
  $user=$this->createMock(IUser::class);$user->method('isEnabled')->willReturn(true);$user->method('getUID')->willReturn('administrator');
  $this->assertSame(['canApprove'=>false,'pending'=>0],(new LetterheadApprovalDashboardService($db,$groups))->summary($user));
 }
 public function testDisabledApproverGetsNoCount(): void {
  $db=$this->createMock(IDBConnection::class);$db->expects($this->never())->method('getQueryBuilder');
  $groups=$this->createMock(IGroupManager::class);$groups->expects($this->never())->method('isInGroup');
  $user=$this->createMock(IUser::class);$user->method('isEnabled')->willReturn(false);
  $this->assertSame(['canApprove'=>false,'pending'=>0],(new LetterheadApprovalDashboardService($db,$groups))->summary($user));
 }
 public function testOnlyPendingAssignedRequestsFromAnotherCreatorAreCounted(): void {
  $db=$this->createMock(IDBConnection::class);$q=$this->createMock(IQueryBuilder::class);$db->method('getQueryBuilder')->willReturn($q);
  $functions=$this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);$functions->method('count')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class));$q->method('func')->willReturn($functions);
  $q->method('select')->willReturnSelf();$q->expects($this->once())->method('from')->with('libresign_letter_doc')->willReturnSelf();$q->method('where')->willReturnSelf();$q->method('andWhere')->willReturnSelf();
  $expr=$this->createMock(IExpressionBuilder::class);$q->method('expr')->willReturn($expr);
  $columns=[];$expr->expects($this->exactly(2))->method('eq')->willReturnCallback(function($column)use(&$columns){$columns[]=$column;return 'condition';});
  $expr->expects($this->once())->method('neq')->with('owner_uid',$this->anything())->willReturn('condition');
  $values=[];$q->expects($this->exactly(3))->method('createNamedParameter')->willReturnCallback(function($value)use(&$values){$values[]=$value;return ':p'.count($values);});
  $result=$this->createMock(IResult::class);$result->method('fetchOne')->willReturn('2');$result->expects($this->once())->method('closeCursor');$q->method('executeQuery')->willReturn($result);
  $groups=$this->createMock(IGroupManager::class);$groups->expects($this->once())->method('isInGroup')->with('approver',LetterheadService::APPROVER_GROUP)->willReturn(true);
  $user=$this->createMock(IUser::class);$user->method('isEnabled')->willReturn(true);$user->method('getUID')->willReturn('approver');
  $this->assertSame(['canApprove'=>true,'pending'=>2],(new LetterheadApprovalDashboardService($db,$groups))->summary($user));
  $this->assertSame(['approver_uid','state'],$columns);$this->assertSame(['approver','approver','pending'],$values);
 }
}
