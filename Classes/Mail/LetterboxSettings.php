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

namespace KonradMichalik\Typo3Letterbox\Mail;

use InvalidArgumentException;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;

use function dirname;
use function sprintf;

/**
 * LetterboxSettings.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class LetterboxSettings
{
    public function __construct(
        public string $mboxFile,
    ) {}

    /**
     * @param array<string, mixed> $mail the MAIL section of TYPO3_CONF_VARS
     */
    public static function fromMailSettings(array $mail): self
    {
        $mboxFile = (string) ($mail['transport_mbox_file'] ?? '');
        if (!(new FileNameValidator())->isValid($mboxFile)) {
            throw new InvalidArgumentException(sprintf('The mailbox file "%s" is not allowed by the file deny pattern.', $mboxFile), 1761561610);
        }

        return new self($mboxFile);
    }

    /**
     * Whether the web server would serve the mailbox. The var path is excluded: in classic mode it sits below the web
     * root, and TYPO3 denies access to it.
     */
    public function isMailboxPublic(string $publicPath, string $varPath): bool
    {
        $directory = self::normalize(dirname($this->mboxFile));

        return self::isInside($directory, self::normalize($publicPath)) && !self::isInside($directory, self::normalize($varPath));
    }

    private static function isInside(string $path, string $directory): bool
    {
        return str_starts_with(rtrim($path, '/').'/', rtrim($directory, '/').'/');
    }

    /**
     * The real path when it exists, so symlinks cannot hide the web root, else the path with `.` and `..` resolved.
     */
    private static function normalize(string $path): string
    {
        $real = realpath($path);
        if (false !== $real) {
            return $real;
        }
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('' !== $part && '.' !== $part) {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }
}
