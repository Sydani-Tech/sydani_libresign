<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

/** Reserve visible signature slots and an invisible slot for participants without a signature marker. */
final class PdfSignatureFieldPlan {
	public static function build(array $elements, array $requests, int $currentRequestId): array {
		$plan = [];
		$visible = [];
		foreach ($elements as $element) {
			if (!in_array($element->getType(), ['signature', 'initial', 'datetime'], true)) {
				continue;
			}
			$owner = $element->getSignRequestId();
			$visible[$owner] = true;
			$metadata = $element->getMetadata() ?? [];
			$entry = [
				'id' => (string)$element->getId(),
				'owner' => $owner,
				'type' => 'signature',
				'page' => $element->getPage(),
				'llx' => $element->getLlx(), 'lly' => $element->getLly(),
				'urx' => $element->getUrx(), 'ury' => $element->getUry(),
				'metadata' => [
					'label' => (string)($metadata['label'] ?? ''),
					'required' => (bool)($metadata['required'] ?? true),
				],
			];
			if ($owner === $currentRequestId) {
				$entry['value'] = true;
			}
			$plan[] = $entry;
		}
		foreach ($requests as $request) {
			if (isset($visible[$request->getId()])) {
				continue;
			}
			$entry = [
				'id' => 's' . $request->getId(),
				'owner' => $request->getId(),
				'type' => 'signature', 'page' => 1,
				'llx' => 0, 'lly' => 0, 'urx' => 0, 'ury' => 0,
				'metadata' => ['label' => '', 'required' => true],
			];
			if ($request->getId() === $currentRequestId) {
				$entry['value'] = true;
			}
			$plan[] = $entry;
		}
		return $plan;
	}
}
