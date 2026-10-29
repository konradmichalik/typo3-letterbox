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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Command;

use KonradMichalik\Typo3Letterbox\Command\SendTestMailsCommand;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * SendTestMailsCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SendTestMailsCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['typo3_letterbox' => ['debugMode' => '1']],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $path = Environment::getVarPath().'/letterbox-test/testmails.mbox';
        GeneralUtility::mkdir_deep(dirname($path));
        if (is_file($path)) {
            unlink($path);
        }
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
            'transport' => LetterboxTransport::class,
            'transport_mbox_file' => $path,
        ];
    }

    #[Test]
    public function sendsRequestedNumberOfMailsIntoTheMbox(): void
    {
        $tester = new CommandTester(new SendTestMailsCommand(GeneralUtility::makeInstance(Mailer::class)));

        $tester->execute(['--count' => '3']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(3, $this->get(MailRepository::class)->count(MailFilter::all()));
    }

    #[Test]
    #[DataProvider('invalidCounts')]
    public function rejectsInvalidCountWithoutSending(string $count): void
    {
        $tester = new CommandTester(new SendTestMailsCommand(GeneralUtility::makeInstance(Mailer::class)));

        $tester->execute(['--count' => $count]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('between 1 and 100', $tester->getDisplay());
        self::assertSame(0, $this->get(MailRepository::class)->count(MailFilter::all()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCounts(): array
    {
        return ['zero' => ['0'], 'negative' => ['-1'], 'text' => ['abc'], 'above cap' => ['101']];
    }

    #[Test]
    public function acceptsTheCap(): void
    {
        $tester = new CommandTester(new SendTestMailsCommand(GeneralUtility::makeInstance(Mailer::class)));

        $tester->execute(['--count' => '100']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function sendsFiveMailsByDefaultCoveringAllTypes(): void
    {
        $tester = new CommandTester(new SendTestMailsCommand(GeneralUtility::makeInstance(Mailer::class)));

        $tester->execute([]);

        $mails = $this->get(MailRepository::class)->find(MailFilter::all(), 0, 10);
        self::assertCount(5, $mails);
        $mbox = (string) file_get_contents($GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file']);
        self::assertStringContainsString('filename=letterbox-inline', $mbox);
        self::assertStringContainsString('filename=letterbox-notes.txt', $mbox);
        self::assertStringContainsString('Cc: ', $mbox);
        self::assertMatchesRegularExpression('/^>From /m', $mbox);
    }

    #[Test]
    public function commandIsRegisteredWithDebugMode(): void
    {
        self::assertTrue($this->get(CommandRegistry::class)->has('letterbox:testmails:send'));
    }
}
