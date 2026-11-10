<?php

/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$requirements = [
	'apiVersion' => '{apiVersion}',
	'path' => '.+',
];

return [
	'routes' => [
		['name' => 'shortValidation#open', 'url' => '/v/{code}', 'verb' => 'GET', 'requirements' => ['code' => '(?:[A-Za-z0-9]{4}-[A-Za-z0-9]{4}|[A-Za-z0-9]{8}|[A-Za-z0-9_-]{22})']],
		// API
		['name' => 'api#preflighted_cors',            'url' => '/api/{apiVersion}/{path}', 'verb' => 'OPTIONS', 'requirements' => $requirements],
	],
];
