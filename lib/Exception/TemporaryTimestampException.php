<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Libresign\Exception;

class TemporaryTimestampException extends LibresignException {
	public function __construct(\Throwable $previous) {
		parent::__construct('The timestamp service is temporarily unavailable. Automatic retries were unsuccessful. No signed document was saved. Please wait a moment and try again.', 503, $previous);
	}
}
