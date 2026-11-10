<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Service\CampaignService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class CampaignDispatch extends TimedJob {
	public function __construct(ITimeFactory $time, private CampaignService $campaigns) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	public function run($argument): void {
		$deadline = microtime(true) + 40;
		$count = 0;
		do {
			$result = $this->campaigns->dispatchBatch(5);
			$processed = $result['processed'] + $result['failed'];
			$count += $processed;
		} while ($processed > 0 && $count < 100 && microtime(true) < $deadline);
	}
}
