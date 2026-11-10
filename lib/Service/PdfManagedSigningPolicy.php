<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\Exception\LibresignException;

/** Timestamp-only intermediates may be extended, but never saved as completed signatures. */
final class PdfManagedSigningPolicy {
	public static function appended(array $existing, array $report, string $certificateId, bool $requireLt): array {
		if (empty($report['checked']) || ($report['trustScope'] ?? '') !== 'organisation'
			|| ($report['managedFormRevisions'] ?? '') !== 'verified') {
			throw new LibresignException('The prepared PDF revisions could not be verified. No signed document was saved.');
		}
		$all = PdfCountersigningPolicy::existing($report);
		foreach ($existing as $id => $before) {
			if (!isset($all[$id]) || (PdfCountersigningPolicy::isLt($before) && !PdfCountersigningPolicy::isLt($all[$id]))) {
				throw new LibresignException('A previous long-term signature changed. No signed document was saved.');
			}
		}
		$new = array_diff_key($all, $existing);
		if (!$new) {
			throw new LibresignException('No new signature could be verified.');
		}
		foreach ($all as $id => $signature) {
			$revision = $signature['revision'] ?? [];
			// A redundant DSS append can leave a new timestamp-only revision unclassified.
			// The managed proof checks its entire post-signature catalog (except DSS), Info, fields and pages.
			// This private intermediate must be extended and pass the final LT checks before persistence.
			$extendableIntermediate = !$requireLt && isset($new[$id]) && ($signature['format'] ?? '') === 'PAdES_BASELINE_T';
			if (($signature['cryptographicIntegrity'] ?? false) !== true
				|| ($signature['issuerTrusted'] ?? false) !== true
				|| ($signature['timestampAnchored'] ?? false) !== true
				|| ($signature['timestampVerified'] ?? false) !== true
				|| ($signature['indication'] ?? '') !== 'TOTAL_PASSED'
				|| ($revision['byteRangeValid'] ?? false) !== true
				|| ($revision['dictionaryConsistent'] ?? false) !== true
				|| (($revision['checked'] ?? false) !== true && ($signature['coversFile'] ?? false) !== true && !$extendableIntermediate)) {
				throw new LibresignException('A PDF signature could not be verified safely. No signed document was saved.');
			}
			foreach (['undefinedChanges', 'annotationChanges', 'pageDifferences', 'annotationOverlaps'] as $key) {
				if (($revision[$key] ?? null) !== 0) {
					throw new LibresignException('The PDF contains changes beyond its assigned fields. No signed document was saved.');
				}
			}
			if (isset($new[$id])) {
				$allowedFormats = $requireLt
					? ['PAdES_BASELINE_LT', 'PAdES_BASELINE_LTA']
					: ['PAdES_BASELINE_T', 'PAdES_BASELINE_LT', 'PAdES_BASELINE_LTA'];
				if (($signature['certificateId'] ?? '') !== $certificateId || !in_array($signature['format'] ?? '', $allowedFormats, true)) {
					throw new LibresignException('The new signature lacks verified signing evidence. No signed document was saved.');
				}
			}
		}
		return array_keys($new);
	}
}
