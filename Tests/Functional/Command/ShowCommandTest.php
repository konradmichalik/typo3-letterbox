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

use KonradMichalik\Typo3Letterbox\Command\ShowCommand;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Tester\CommandTester;

use function strlen;

/**
 * ShowCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ShowCommandTest extends MailboxCommandTestCase
{
    private const END_OF_OUTPUT = '<end of output>';

    #[Test]
    public function printsTheTextPartByDefault(): void
    {
        $this->addMail('Hello', 'r@example.org', '2026-10-27 10:00:00', 'Plain <b>body</b> text');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId()]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Plain <b>body</b> text', $tester->getDisplay());
    }

    #[Test]
    public function printsTheSource(): void
    {
        $this->addMail('Hello');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId(), '--part' => 'source']);

        self::assertStringContainsString('Subject: Hello', $tester->getDisplay());
    }

    #[Test]
    public function printsHeadersAsNameValueLines(): void
    {
        $this->addMail('Hello', 'r@example.org');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId(), '--part' => 'headers']);

        self::assertMatchesRegularExpression('/^Subject: Hello$/mi', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^To: r@example\.org$/mi', $tester->getDisplay());
    }

    #[Test]
    public function printsTheHtmlPartEmptyWhenTheMailHasNone(): void
    {
        $this->addMail('Hello');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId(), '--part' => 'html']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', trim($tester->getDisplay()));
    }

    #[Test]
    #[DataProvider('unknownIds')]
    public function rejectsUnknownAndMalformedIds(string $id): void
    {
        $this->addMail('Hello');

        $tester = $this->tester();
        $tester->execute(['id' => $id]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Mail not found.', $tester->getDisplay());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownIds(): array
    {
        return ['unknown' => [self::UNKNOWN_ID], 'malformed' => ['../etc/passwd'], 'uppercase' => [strtoupper(self::UNKNOWN_ID)]];
    }

    #[Test]
    public function rejectsAnUnknownPart(): void
    {
        $this->addMail('Hello');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId(), '--part' => 'pdf']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--part', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenTheMailboxIsNotConfigured(): void
    {
        $this->useMailbox('');

        $tester = $this->tester();
        $tester->execute(['id' => self::UNKNOWN_ID]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Letterbox is not configured: MAIL/transport_mbox_file is empty.', $tester->getDisplay());
    }

    #[Test]
    public function pipedOutputStaysRawEvenWhenDecorated(): void
    {
        $this->addMail("Hello\e]0;pwned\x07", 'r@example.org', '2026-10-27 10:00:00', "Plain \e[31mred\e[0m text\x7F2J");
        $tester = $this->tester();

        foreach (['text', 'headers', 'source'] as $part) {
            $tester->execute(['id' => $this->firstId(), '--part' => $part], ['decorated' => true]);

            self::assertStringContainsString("\e", $tester->getDisplay(), $part.': a pipe gets the bytes, whatever the decoration says');
        }
    }

    #[Test]
    public function terminalGetsNoControlCharactersInAnyPartUnlessRaw(): void
    {
        $this->addMail("Hello\e]0;pwned\x07", 'r@example.org', '2026-10-27 10:00:00', "Plain \e[31mred\e[0m text\x7F2J");

        self::assertSame("Plain [31mred[0m text2J\n", $this->onTerminal(['--part' => 'text']));
        foreach (['headers', 'source', 'html'] as $part) {
            $printed = $this->onTerminal(['--part' => $part]);
            self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', $printed, $part);
        }
        self::assertStringContainsString('Subject: Hello]0;pwned', $this->onTerminal(['--part' => 'source']));
        self::assertStringContainsString("\e]0;pwned\x07", $this->onTerminal(['--part' => 'source', '--raw' => true]));
        self::assertStringContainsString("\e[31mred", $this->onTerminal(['--raw' => true]));
    }

    /**
     * Runs the command with a pseudo terminal as output and returns the bytes that reached it. The reading side is
     * switched to raw mode first, so the terminal passes every byte through unchanged.
     *
     * @param array<string, bool|string> $options
     */
    private function onTerminal(array $options): string
    {
        $capture = $this->path.'.tty';
        if (is_file($capture)) {
            unlink($capture);
        }
        $process = proc_open(['sh', '-c', 'stty raw -echo && printf R && exec cat > "$0"', $capture], [['pty'], ['pty'], ['pty']], $pipes);
        if (false === $process || !isset($pipes[1]) || 'R' !== fread($pipes[1], 1)) {
            self::markTestSkipped('No pseudo terminal support.');
        }
        $terminal = $pipes[1];
        self::assertTrue(stream_isatty($terminal));

        $exitCode = $this->get(ShowCommand::class)->run(new ArrayInput(['id' => $this->firstId(), ...$options]), new StreamOutput($terminal));
        fwrite($terminal, self::END_OF_OUTPUT);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && !str_ends_with(is_file($capture) ? (string) file_get_contents($capture) : '', self::END_OF_OUTPUT)) {
            usleep(10000);
        }
        proc_terminate($process);
        array_map(fclose(...), $pipes);
        proc_close($process);
        $printed = (string) file_get_contents($capture);
        unlink($capture);

        self::assertSame(0, $exitCode);
        self::assertStringEndsWith(self::END_OF_OUTPUT, $printed, 'the terminal output was captured completely');

        return substr($printed, 0, -strlen(self::END_OF_OUTPUT));
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->get(ShowCommand::class));
    }
}
