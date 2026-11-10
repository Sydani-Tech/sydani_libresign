<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;

use OCA\Libresign\Enum\SignatureFlow;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Policy\PolicyService;
use OCA\Libresign\Service\Policy\Provider\Signature\SignatureFlowPolicy;
use OCP\IUser;

/** A countersigner requires native sequential signing; campaign creation never bypasses staff policy. */
class CampaignSigningPolicy {
	public function __construct(private PolicyService $policies) {
	}

	public function overrides(IUser $owner, bool $hasCountersigner): array {
		if (!$hasCountersigner) {
			return [];
		}
		$policy = $this->policies->resolveForUser(SignatureFlowPolicy::KEY, $owner);
		$ordered = SignatureFlow::ORDERED_NUMERIC->value;
		if ($policy->getEffectiveValue() === $ordered) {
			return [];
		}
		$allowed = $policy->getAllowedValues();
		if (!$policy->canUseAsRequestOverride() || ($allowed !== [] && !in_array($ordered, $allowed, true))) {
			throw new LibresignException('This campaign has a countersigner and requires sequential signing. An administrator must enable sequential signing for the campaign creator or allow that creator to select it before the campaign can send requests.', 422);
		}
		return [SignatureFlowPolicy::KEY => $ordered];
	}
}
