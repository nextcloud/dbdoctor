<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Notification;

use OCA\DBDoctor\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
	private Notifier $notifier;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $p = []) => vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $text), $p),
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $p = []) => $count === 1 ? $singular : $plural,
		);

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/settings');
		$url->method('imagePath')->willReturn('/img/app.svg');
		$url->method('getAbsoluteURL')->willReturnArgument(0);

		$this->notifier = new Notifier($factory, $url);
	}

	public function test_getID_and_getName(): void {
		$this->assertSame('dbdoctor', $this->notifier->getID());
		$this->assertNotSame('', $this->notifier->getName());
	}

	public function test_rejects_foreign_app_notification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('some_other_app');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}

	public function test_rejects_unknown_subject(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('dbdoctor');
		$notification->method('getSubject')->willReturn('something_else');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}

	public function test_prepares_health_declined_notification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('dbdoctor');
		$notification->method('getSubject')->willReturn('health_declined');
		$notification->method('getSubjectParameters')->willReturn([
			'oldGrade' => 'A', 'newGrade' => 'C', 'score' => 71, 'alerts' => 2,
		]);

		// The parsed subject must mention both grades; a link + icon must be set.
		$notification->expects($this->once())->method('setParsedSubject')
			->with($this->stringContains('C'))
			->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->expects($this->once())->method('setLink')->willReturnSelf();
		$notification->expects($this->once())->method('setIcon')->willReturnSelf();

		$this->assertSame($notification, $this->notifier->prepare($notification, 'en'));
	}
}
