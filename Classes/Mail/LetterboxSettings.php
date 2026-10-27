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

use function is_array;
use function sprintf;

/**
 * LetterboxSettings.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class LetterboxSettings
{
    /**
     * @param list<RecipientPattern> $catch
     */
    public function __construct(
        public string $mboxFile,
        public DeliveryMode $mode,
        public array $catch,
        public string $delivery,
    ) {}

    /**
     * @param array<string, mixed> $mail the MAIL section of TYPO3_CONF_VARS
     */
    public static function fromMailSettings(array $mail, string $context): self
    {
        $mboxFile = (string) ($mail['transport_mbox_file'] ?? '');
        if (!(new FileNameValidator())->isValid($mboxFile)) {
            throw new InvalidArgumentException(sprintf('The mailbox file "%s" is not allowed by the file deny pattern.', $mboxFile), 1761561610);
        }

        $delivery = (string) ($mail['transport_letterbox_delivery'] ?? '');
        if (LetterboxTransport::class === ltrim($delivery, '\\')) {
            throw new InvalidArgumentException('transport_letterbox_delivery must not be the Letterbox transport itself.', 1761561611);
        }

        return new self($mboxFile, self::resolveMode($mail['transport_letterbox_mode'] ?? null, $context), self::parseCatch($mail['transport_letterbox_catch'] ?? []), $delivery);
    }

    private static function resolveMode(mixed $value, string $context): DeliveryMode
    {
        if (null === $value || '' === $value) {
            return 'Production' === $context ? DeliveryMode::Listed : DeliveryMode::All;
        }

        $mode = DeliveryMode::tryFrom((string) $value);
        if (null === $mode) {
            throw new InvalidArgumentException(sprintf('Unknown Letterbox mode "%s", expected all, listed or off.', (string) $value), 1761561612);
        }

        return $mode;
    }

    /**
     * @return list<RecipientPattern>
     */
    private static function parseCatch(mixed $value): array
    {
        $entries = is_array($value) ? $value : explode(',', (string) $value);
        $patterns = [];
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ('' !== $entry) {
                $patterns[] = RecipientPattern::fromString($entry);
            }
        }

        return $patterns;
    }
}
