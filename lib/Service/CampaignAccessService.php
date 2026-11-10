<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;

class CampaignAccessService {
	public function __construct(private IAppConfig $config, private IGroupManager $groups) {
	}

	public function isAdmin(?IUser $user): bool {
		return $user !== null && $user->isEnabled() && $this->groups->isInGroup($user->getUID(), 'admin');
	}

	public function canCreate(?IUser $user): bool {
		if ($user === null || !$user->isEnabled()) return false;
		if ($this->isAdmin($user)) return true;
		foreach ($this->config->getValueArray(Application::APP_ID, 'campaign_groups', ['admin']) as $id) {
			if (is_string($id) && $this->groups->isInGroup($user->getUID(), $id)) return true;
		}
		return false;
	}

	public function assertCreator(?IUser $user): void {
		if (!$this->canCreate($user)) throw new \OCP\Files\NotPermittedException('Campaign access requires membership of an authorised campaign group.');
	}

	public function assertOwner(?IUser $user, array $campaign): void {
		$this->assertCreator($user);
		if (!$this->isAdmin($user) && $campaign['owner_uid'] !== $user->getUID()) throw new \OCP\Files\NotPermittedException('Campaign not available to this account.');
	}

	public function settings(): array {
		$result = [];
		foreach ($this->config->getValueArray(Application::APP_ID, 'campaign_groups', ['admin']) as $id) {
			$group = is_string($id) ? $this->groups->get($id) : null;
			if ($group) $result[] = ['id' => $id, 'displayname' => $group->getDisplayName()];
		}
		return $result;
	}

	public function saveGroups(array $ids): array {
		if (count($ids) > 100) throw new \InvalidArgumentException('Select up to 100 campaign groups.');
		foreach ($ids as $id) {
			if (!is_string($id) || !$this->groups->get($id)) throw new \InvalidArgumentException('Unknown campaign group.');
		}
		$this->config->setValueArray(Application::APP_ID, 'campaign_groups', array_values(array_unique($ids)));
		return $this->settings();
	}
}
