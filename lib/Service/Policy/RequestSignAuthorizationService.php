<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Policy;

use OCA\Libresign\Service\Policy\Provider\RequestSignGroups\RequestSignGroupsPolicy;
use OCA\Libresign\Service\Policy\Provider\RequestSignGroups\RequestSignGroupsPolicyValue;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IAppConfig;
use OCA\Libresign\AppInfo\Application;

class RequestSignAuthorizationService {
	public function __construct(
		private PolicyService $policyService,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Check if a user is authorized to create signature requests.
	 *
	 * This evaluates the delegated RBAC policy 'groups_request_sign' which controls
	 * which groups are authorized to create signature requests within the current scope.
	 * Administrators can only authorize groups they themselves belong to.
	 *
	 * @param IUser|null $user The user to check (typically current user)
	 * @return bool True if user belongs to at least one authorized requester group
	 */
	public function canRequestSign(?IUser $user = null): bool {
		if (!$user instanceof IUser) {
			return false;
		}

		if ($this->appConfig->getValueBool(Application::APP_ID, 'allow_all_signed_in_request_sign', true)) {
			return true;
		}

		$resolvedPolicy = $this->policyService->resolveForUser(RequestSignGroupsPolicy::KEY, $user);
		$userGroups = $this->groupManager->getUserGroupIds($user);

		return RequestSignGroupsPolicyValue::canUserGroupsRequestSign(
			$resolvedPolicy->getEffectiveValue(),
			$userGroups,
		);
	}
}
