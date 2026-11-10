<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\Libresign\Migration\Version18004Date20261015000000;
use OCA\Libresign\Migration\Version19005Date20261110000000;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Exceptions\AppConfigTypeConflictException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Version19005Date20261110000000Test extends TestCase {
	public static function indexes(): array {
		return [[false, false], [true, false], [false, true], [true, true]];
	}

	#[DataProvider('indexes')]
	public function testIndependentlyRepairsEitherMissingIndexAndCanRepeat(bool $completed, bool $requested): void {
		foreach ([new Version18004Date20261015000000(), new Version19005Date20261110000000($this->createMock(IAppConfig::class))] as $migration) {
			$table = new Table('libresign_file');
			$table->addColumn('id', 'bigint');
			$table->addColumn('completed_at', 'datetime');
			$table->addColumn('requested_at', 'datetime');
			if ($completed) $table->addIndex(['completed_at', 'id'], 'ls_file_completed');
			if ($requested) $table->addIndex(['requested_at', 'id'], 'ls_file_requested');
			$schema = $this->createMock(ISchemaWrapper::class);
			$schema->method('getTable')->with('libresign_file')->willReturn(new \OC\DB\Schema\Table($table));
			for ($i = 0; $i < 2; $i++) $migration->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
			$this->assertSame(['completed_at', 'id'], $table->getIndex('ls_file_completed')->getColumns());
			$this->assertSame(['requested_at', 'id'], $table->getIndex('ls_file_requested')->getColumns());
		}
	}

	public static function footers(): array {
		return [
			'blank customized migrated template' => [['customizeFooterTemplate' => true, 'footerTemplate' => '', 'validationSite' => '', 'unknownFutureField' => 'retain'], '0', 'legacy custom footer', true],
			'new explicit template' => [['customizeFooterTemplate' => true, 'footerTemplate' => 'new footer'], '0', 'old footer', false],
			'customization disabled' => [['customizeFooterTemplate' => false, 'footerTemplate' => ''], '0', 'old footer', false],
			'legacy default' => [['customizeFooterTemplate' => true, 'footerTemplate' => ''], '1', 'old footer', false],
			'no legacy source' => [['customizeFooterTemplate' => true, 'footerTemplate' => ''], '0', '', false],
			'unstructured legacy config' => [true, '0', 'old footer', false],
		];
	}

	#[DataProvider('footers')]
	public function testRecoversOnlyTheMissingTemplateAndPreservesCurrentSettings(mixed $policy, string $isDefault, string $template, bool $writes): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn ($app, $key, $default) => ['add_footer' => json_encode($policy), 'footer_template_is_default' => $isDefault, 'footer_template' => $template][$key] ?? $default);
		$config->expects($writes ? $this->once() : $this->never())->method('setValueString')->willReturnCallback(function ($app, $key, $value) use ($policy, $template): bool {
			$this->assertSame('libresign', $app); $this->assertSame('add_footer', $key);
			$this->assertSame(array_replace($policy, ['footerTemplate' => $template]), json_decode($value, true));
			return true;
		});
		(new Version19005Date20261110000000($config))->postSchemaChange($this->createMock(IOutput::class), fn () => $this->fail('No schema or document access during footer recovery'), []);
	}

	public function testHandlesTypedLegacyBooleanFlag(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static function ($app, $key, $default) {
			if ($key === 'footer_template_is_default') throw new AppConfigTypeConflictException();
			return ['add_footer' => '{"customizeFooterTemplate":true}', 'footer_template' => 'custom footer'][$key] ?? $default;
		});
		$config->expects($this->once())->method('getValueBool')->with('libresign', 'footer_template_is_default', true)->willReturn(false);
		$config->expects($this->once())->method('setValueString');
		(new Version19005Date20261110000000($config))->postSchemaChange($this->createMock(IOutput::class), fn () => $this->fail('No document access'), []);
	}
}
