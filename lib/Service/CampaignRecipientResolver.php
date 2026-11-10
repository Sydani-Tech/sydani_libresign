<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Service;

use OCP\IUserManager;

class CampaignRecipientResolver {
	public function __construct(private IUserManager $users) {
	}

	public function resolve(array $input): array {
		$email = strtolower(CampaignDefinition::text($input['email'] ?? '', 254, 'recipient email'));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid recipient email.');
		$name = CampaignDefinition::text($input['name'] ?? '', 64, 'recipient name');
		$accounts = array_values(array_filter($this->users->getByEmail($email), static fn ($user) => strcasecmp($user->getEMailAddress() ?? '', $email) === 0));
		if (count($accounts) > 1) throw new \InvalidArgumentException('This email matches multiple accounts. Correct the account email addresses before sending.');
		$user = $accounts[0] ?? null;
		if (!empty($input['uid'])) {
			$selected = is_string($input['uid']) ? $this->users->get($input['uid']) : null;
			if (!$selected || strcasecmp($selected->getEMailAddress() ?? '', $email) !== 0 || ($user && $selected->getUID() !== $user->getUID())) {
				throw new \InvalidArgumentException('The selected account does not match the recipient email.');
			}
			$user = $selected;
		}
		if ($user && !$user->isEnabled()) throw new \InvalidArgumentException('The recipient account is disabled. It cannot be sent an external invitation instead.');
		$uid = $user?->getUID();
		return ['name' => $name, 'email' => $email, 'uid' => $uid, 'kind' => $uid !== null ? 'internal' : 'external', 'recipient_key' => hash('sha256', $uid !== null ? 'account:' . $uid : 'email:' . $email)];
	}

	public function resolveMany(array $input): array {
		if (!$input || count($input) > CampaignDefinition::MAX_RECIPIENTS) throw new \InvalidArgumentException('Choose between 1 and ' . CampaignDefinition::MAX_RECIPIENTS . ' recipients.');
		$result = [];
		foreach ($input as $row) {
			if (!is_array($row)) throw new \InvalidArgumentException('Invalid recipient.');
			$recipient = $this->resolve($row);
			if (isset($result[$recipient['recipient_key']])) throw new \InvalidArgumentException('The recipient list contains a duplicate account or email.');
			$result[$recipient['recipient_key']] = $recipient;
		}
		return array_values($result);
	}
}
