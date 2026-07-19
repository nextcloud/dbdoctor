<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Notification;

use OCA\DBDoctor\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the "database health declined" notification produced by the
 * weekly {@see \OCA\DBDoctor\BackgroundJob\ScheduledCheck} job.
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('DB Doctor');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID
			|| $notification->getSubject() !== 'health_declined') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$oldGrade = (string)($params['oldGrade'] ?? '?');
		$newGrade = (string)($params['newGrade'] ?? '?');
		$score = (int)($params['score'] ?? 0);
		$alerts = (int)($params['alerts'] ?? 0);

		$notification->setParsedSubject(
			$l->t('Database health declined from grade %1$s to %2$s', [$oldGrade, $newGrade]),
		);
		$notification->setParsedMessage(
			$l->n(
				'The weekly check-up scored %1$d/100 with %n alert. Open DB Doctor to review the findings.',
				'The weekly check-up scored %1$d/100 with %n alerts. Open DB Doctor to review the findings.',
				$alerts,
				[$score],
			),
		);
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
			'settings.AdminSettings.index',
			['section' => Application::APP_ID],
		));
		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
		));

		return $notification;
	}
}
