<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Exception\LibresignException;

/** Validation shared by the request boundary and the background signing worker. */
class SigningFieldService {
	public const TYPES = ['text', 'date', 'checkbox'];

	public static function isField(string $type): bool {
		return in_array($type, self::TYPES, true);
	}

	public const DEFAULT_SIGNING_DATE_TIMEZONE = 'Africa/Lagos';

	public static function definition(array $metadata, ?string $type = null): array {
		$label = $metadata['label'] ?? '';
		$required = $metadata['required'] ?? true;
		if (!is_string($label) || mb_strlen($label) > 100 || !is_bool($required)) {
			throw new LibresignException('Invalid field label or required setting.');
		}
		$useSigningDate = $metadata['useSigningDate'] ?? false;
		if (!is_bool($useSigningDate) || ($useSigningDate && $type !== null && $type !== 'date')) {
			throw new LibresignException('Use signing date is only available for date fields.');
		}
		$result = ['label' => trim($label), 'required' => $required];
		if ($useSigningDate) {
			$timezone = $metadata['signingDateTimezone'] ?? self::DEFAULT_SIGNING_DATE_TIMEZONE;
			if (!is_string($timezone) || !in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
				throw new LibresignException('Invalid signing date timezone.');
			}
			$result += ['useSigningDate' => true, 'signingDateTimezone' => $timezone];
		}
		return $result;
	}

	public static function usesSigningDate(FileElement $field): bool {
		return $field->getType() === 'date' && (self::definition($field->getMetadata() ?? [], 'date')['useSigningDate'] ?? false);
	}

	public static function signingDate(FileElement $field, \DateTimeInterface $signedAt): string {
		$definition = self::definition($field->getMetadata() ?? [], $field->getType());
		return \DateTimeImmutable::createFromInterface($signedAt)
			->setTimezone(new \DateTimeZone($definition['signingDateTimezone'] ?? self::DEFAULT_SIGNING_DATE_TIMEZONE))->format('Y-m-d');
	}

	public static function validateValue(string $type, array $metadata, mixed $value): string|bool {
		$definition = self::definition($metadata);
		$label = $definition['label'] ?: $type;
		if ($type === 'checkbox') {
			if (!is_bool($value) || ($definition['required'] && !$value)) {
				throw new LibresignException('Please complete checkbox: ' . $label);
			}
			return $value;
		}
		if (!in_array($type, ['text', 'date'], true) || !is_string($value)
			|| !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 500
			|| preg_match('/[\x00-\x1F\x7F]/u', $value)) {
			throw new LibresignException('Invalid field value: ' . $label);
		}
		$value = trim($value);
		if ($value === '') {
			if ($definition['required']) {
				throw new LibresignException('Please complete field: ' . $label);
			}
			return '';
		}
		if ($type === 'date') {
			$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
			if (!$date || $date->format('Y-m-d') !== $value) {
				throw new LibresignException('Use a valid date (YYYY-MM-DD): ' . $label);
			}
		}
		return $value;
	}

	/** Missing optional fields have an explicit empty value; required fields fail closed. */
	public static function submittedValue(FileElement $field, array $elements, ?\DateTimeInterface $signingTime = null): string|bool {
		$matches = array_values(array_filter($elements, fn (array $item) => ($item['documentElementId'] ?? null) === $field->getId()));
		if (count($matches) > 1) {
			throw new LibresignException('Duplicate field submission.');
		}
		if (self::usesSigningDate($field)) {
			// Request validation never requires or trusts a client-provided date.
			// The worker supplies a fresh server instant only when signing begins.
			return $signingTime ? self::signingDate($field, $signingTime) : '';
		}
		$value = $matches[0]['value'] ?? ($field->getType() === 'checkbox' ? false : '');
		return self::validateValue($field->getType(), $field->getMetadata() ?? [], $value);
	}
}
