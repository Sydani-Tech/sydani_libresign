<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\Policy\Provider\Footer\FooterPolicy;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Forward repair for installations that already recorded the earlier migrations. */
class Version19005Date20261110000000 extends SimpleMigrationStep {
	public function __construct(private IAppConfig $appConfig) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('libresign_file');
		foreach (['ls_file_completed' => 'completed_at', 'ls_file_requested' => 'requested_at'] as $name => $column) {
			if (!$table->hasIndex($name)) {
				$table->addIndex([$column, 'id'], $name);
			}
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$raw = $this->appConfig->getValueString(Application::APP_ID, FooterPolicy::KEY, '');
		$policy = json_decode($raw, true);
		// Preserve every current field and an explicit replacement or disabled template.
		if (!is_array($policy) || ($policy['customizeFooterTemplate'] ?? false) !== true
			|| trim((string)($policy['footerTemplate'] ?? '')) !== '') {
			return;
		}
		try {
			$isDefault = $this->appConfig->getValueString(Application::APP_ID, 'footer_template_is_default', '1');
			$customLegacyTemplate = in_array(strtolower(trim($isDefault)), ['0', 'false', 'no', 'off'], true);
		} catch (AppConfigTypeConflictException) {
			$customLegacyTemplate = !$this->appConfig->getValueBool(Application::APP_ID, 'footer_template_is_default', true);
		}
		if (!$customLegacyTemplate) {
			return;
		}
		$template = $this->appConfig->getValueString(Application::APP_ID, 'footer_template', '');
		if (trim($template) === '') {
			return;
		}
		$policy['footerTemplate'] = $template;
		$this->appConfig->setValueString(Application::APP_ID, FooterPolicy::KEY, json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
	}
}
