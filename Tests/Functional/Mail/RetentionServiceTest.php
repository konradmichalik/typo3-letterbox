<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_letterbox" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Mail;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepositoryFactory;
use KonradMichalik\Typo3Letterbox\Mail\RetentionService;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * RetentionServiceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RetentionServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/retention.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['maxMessages'] = '3';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['maxAgeDays'] = '30';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function mailsCaughtInOneRequestReadTheCachedIndexOnce(): void
    {
        /** @var array<string, mixed> $stored */
        $stored = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('get')->willReturnCallback(static function (string $key) use (&$stored): mixed {
            return $stored[$key] ?? false;
        });
        $cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$stored): void {
            $stored[$key] = $value;
        });
        $factory = new MailRepositoryFactory(new MailboxLock($this->get(LockFactory::class)), $cache);
        $service = new RetentionService($factory, $this->get(ExtensionSettings::class), new NullLogger());
        $writer = new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class)));
        $writer->append('s@example.org', new DateTimeImmutable('-40 days'), "Subject: Expired\n\nbody\n");

        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $subject) {
            $writer->append('s@example.org', new DateTimeImmutable(), "Subject: {$subject}\n\nbody\n");
            $service->afterAppend($this->path);
        }

        $subjects = array_map(static fn ($entry): string => $entry->subject, $factory->forPath($this->path)->findEntries(MailFilter::all(), 0, 10));
        // The expired mail goes with the first append, the limit of 3 has a slack of 1, so the fifth mail cuts back to 3.
        self::assertSame(['Five', 'Four', 'Three'], $subjects);
    }
}
