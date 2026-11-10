<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Service\ScheduledReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

final class ScheduledReminderDispatch extends TimedJob {
	public function __construct(ITimeFactory $time, private ScheduledReminderService $reminders) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	public function run($argument): void {
		$this->reminders->send();
	}
}
