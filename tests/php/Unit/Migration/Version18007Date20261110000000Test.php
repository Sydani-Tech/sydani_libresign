<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\Libresign\Migration\Version18007Date20261110000000;
use OCA\Libresign\Service\ShortValidationCodeStore;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version18007Date20261110000000Test extends TestCase {
	public function testCreatesUniqueCodeAndUuidKeysWithoutTouchingDocumentTables(): void {
		$schema=$this->createMock(ISchemaWrapper::class);$table=new Table(ShortValidationCodeStore::TABLE);
		$schema->method('hasTable')->with(ShortValidationCodeStore::TABLE)->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with(ShortValidationCodeStore::TABLE)->willReturn(new \OC\DB\Schema\Table($table));
		$this->assertSame($schema,(new Version18007Date20261110000000())->changeSchema($this->createMock(IOutput::class),static fn()=>$schema,[]));
		$this->assertSame(['code_id'],$table->getPrimaryKey()->getColumns());
		$this->assertSame(['uuid'],$table->getIndex('ls_vlink_uuid')->getColumns());
		$this->assertTrue($table->getIndex('ls_vlink_uuid')->isUnique());
		$this->assertSame(36,$table->getColumn('uuid')->getLength());
	}
	public function testExistingAliasesAreNeverRecreatedOrRemoved(): void {
		$schema=$this->createMock(ISchemaWrapper::class);$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');
		$this->assertSame($schema,(new Version18007Date20261110000000())->changeSchema($this->createMock(IOutput::class),static fn()=>$schema,[]));
	}
}
