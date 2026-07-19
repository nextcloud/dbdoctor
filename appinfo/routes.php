<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

return [
	'ocs' => [
		// Dashboard data
		['name' => 'Check#latest',          'url' => '/api/v1/check/latest',           'verb' => 'GET'],
		['name' => 'Check#run',             'url' => '/api/v1/check/run',              'verb' => 'POST'],
		['name' => 'Check#history',         'url' => '/api/v1/check/history',          'verb' => 'GET'],
		['name' => 'Check#scoreHistory',    'url' => '/api/v1/check/score-history',    'verb' => 'GET'],
		['name' => 'Check#revertedFixes',   'url' => '/api/v1/check/reverted-fixes',   'verb' => 'GET'],
		['name' => 'Check#ping',            'url' => '/api/v1/check/ping',             'verb' => 'GET'],
		// Apply runtime tunables
		['name' => 'Apply#apply',           'url' => '/api/v1/apply',                  'verb' => 'POST'],
		// Live metrics for the dashboard tiles (read-only)
		['name' => 'Insights#metrics',      'url' => '/api/v1/insights/metrics',       'verb' => 'GET'],
		// Settings + audit
		['name' => 'Settings#index',        'url' => '/api/v1/settings',               'verb' => 'GET'],
		['name' => 'Settings#update',       'url' => '/api/v1/settings',               'verb' => 'PUT'],
		['name' => 'Settings#testConnection','url' => '/api/v1/settings/test-connection','verb' => 'POST'],
		['name' => 'Settings#audit',        'url' => '/api/v1/audit',                  'verb' => 'GET'],
	],
];
