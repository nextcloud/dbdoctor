<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

// Auto-loader for app classes during testing.  We use a tiny PSR-4
// resolver instead of pulling in Composer's full autoloader so the
// tests can run against a checkout that hasn't done `composer install`
// yet — handy for CI matrices where dependencies are cached separately.
// When tests run inside a server checkout, pull in Doctrine and the
// rest of the third-party tree so OCP-interface mocks (IDBConnection,
// etc.) can resolve their own type-references during reflection.
// Optional — guarded so that a CI matrix without 3rdparty installed
// can still exercise tests that don't touch OCP types.
$thirdparty = __DIR__ . '/../../../3rdparty/autoload.php';
if (is_file($thirdparty)) {
	require_once $thirdparty;
}

spl_autoload_register(static function (string $class): void {
	$prefixes = [
		'OCA\\DBDoctor\\Tests\\' => __DIR__ . DIRECTORY_SEPARATOR,
		'OCA\\DBDoctor\\' => __DIR__ . '/../lib/',
		// OCP / OC types are needed when tests construct mocks of
		// Nextcloud public API interfaces (e.g. IDBConnection in the
		// ApplyService tests).  These directories always exist when
		// the app is checked out inside a server tree, which is the
		// only place these tests run.
		'OCP\\' => __DIR__ . '/../../../lib/public/',
	];
	foreach ($prefixes as $prefix => $base) {
		if (str_starts_with($class, $prefix)) {
			$rel = substr($class, strlen($prefix));
			$path = $base . str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
			if (is_file($path)) {
				require_once $path;
				return;
			}
		}
	}
});
