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

namespace KonradMichalik\Typo3Letterbox\Report;

use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Backend\RetentionText;
use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\External\ExternalAccessGuard;
use KonradMichalik\Typo3Letterbox\Mail\{LetterboxSettings, LetterboxTransport, RetentionSettings, TransportSummary, TransportTakeover};
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\{Status, StatusProviderInterface};

use function dirname;
use function is_array;
use function sprintf;

/**
 * LetterboxStatus.
 *
 * Names what the mail catcher does in Admin Tools > Reports > Status. Core renders the message of a
 * status as raw HTML, so every dynamic value in it is escaped here.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class LetterboxStatus implements StatusProviderInterface
{
    private const LANGUAGE_FILE = 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private MailRepository $mailRepository,
        private ExternalAccessGuard $externalAccessGuard,
        private ExtensionSettings $extensionSettings,
    ) {}

    public function getLabel(): string
    {
        return $this->translate('status.label');
    }

    /**
     * @return array<string, Status>
     */
    public function getStatus(): array
    {
        $mail = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        $mail = is_array($mail) ? $mail : [];
        if (!LetterboxTransport::isSelectedIn($mail)) {
            return ['transport' => $this->status('transport', 'status.transport.other', 'status.transport.otherMessage', ContextualFeedbackSeverity::INFO)];
        }

        $statuses = ['transport' => $this->transport($mail)];
        $context = Environment::getContext();
        try {
            $settings = LetterboxSettings::fromMailSettings($mail);
        } catch (InvalidArgumentException) {
            $statuses['settings'] = $this->status('settings', 'status.settings.invalid', 'status.settings.invalidMessage', ContextualFeedbackSeverity::ERROR);

            return $statuses;
        }

        $statuses['catching'] = $this->catching($context);
        $statuses['file'] = $this->file($settings);
        $statuses['retention'] = $this->retention();
        $statuses['external'] = $this->external();

        return $statuses;
    }

    /**
     * A takeover is announced, the original transport is named by type, scheme, host and port only.
     *
     * @param array<string, mixed> $mail
     */
    private function transport(array $mail): Status
    {
        $takeover = TransportTakeover::of($mail);
        if (null === $takeover) {
            return $this->status('transport', 'status.transport.ok', 'status.transport.okMessage');
        }

        return new Status(
            $this->translate('status.transport.title'),
            $this->translate('status.transport.ok'),
            sprintf(
                $this->escape($this->translate('status.transport.takeoverMessage')),
                $this->escape($this->translate('takeover.by.'.$takeover['by'])),
                $this->escape(TransportSummary::of(TransportTakeover::original($mail) ?? [])),
            ),
            ContextualFeedbackSeverity::INFO,
        );
    }

    /**
     * Any Production context warns, a live system may well run as Production/Live and would swallow every mail.
     */
    private function catching(ApplicationContext $context): Status
    {
        $title = $this->translate('status.catching.title');
        $value = $this->translate('status.catching.value');

        return $context->isProduction()
            ? new Status($title, $value, sprintf($this->escape($this->translate('status.catching.warning')), $this->escape((string) $context)), ContextualFeedbackSeverity::WARNING)
            : new Status($title, $value, $this->escape($this->translate('status.catching.message')));
    }

    private function file(LetterboxSettings $settings): Status
    {
        $title = $this->translate('status.file.title');
        if (!$this->mailRepository->isConfigured()) {
            return new Status($title, '-', $this->escape($this->translate('status.file.notConfigured')), ContextualFeedbackSeverity::WARNING);
        }

        $path = $this->mailRepository->path();
        $escapedPath = $this->escape($path);
        if ($settings->isMailboxPublic(Environment::getPublicPath(), Environment::getVarPath())) {
            return new Status($title, $this->translate('status.file.public'), sprintf($this->escape($this->translate('status.file.publicMessage')), $escapedPath), ContextualFeedbackSeverity::ERROR);
        }
        $exists = is_file($path);
        if ($exists && !is_writable($path)) {
            return new Status($title, '-', sprintf($this->escape($this->translate('status.file.notWritable')), $escapedPath), ContextualFeedbackSeverity::WARNING);
        }
        if (!$exists && !(is_dir(dirname($path)) && is_writable(dirname($path)))) {
            return new Status($title, '-', sprintf($this->escape($this->translate('status.file.directoryNotWritable')), $escapedPath), ContextualFeedbackSeverity::WARNING);
        }

        $count = $exists ? $this->mailRepository->count(MailFilter::all()) : 0;
        $value = match (true) {
            0 === $count => $this->translate('status.file.empty'),
            1 === $count => sprintf($this->translate('status.file.value.one'), GeneralUtility::formatSize($this->mailRepository->sizeInBytes())),
            default => sprintf($this->translate('status.file.value.other'), $count, GeneralUtility::formatSize($this->mailRepository->sizeInBytes())),
        };

        return new Status($title, $value, sprintf($this->escape($this->translate('status.file.message')), $escapedPath));
    }

    private function retention(): Status
    {
        $retention = RetentionSettings::fromExtensionSettings($this->extensionSettings);
        if (!$retention->isActive()) {
            return $this->status('retention', 'status.retention.none', 'status.retention.noneMessage');
        }

        return new Status(
            $this->translate('status.retention.title'),
            RetentionText::of($retention, $this->translate(...)),
            $this->escape($this->translate('status.retention.message')),
        );
    }

    /**
     * The guard keeps root Production off, so an active state is always a test system.
     */
    private function external(): Status
    {
        return $this->externalAccessGuard->status()->active
            ? $this->status('external', 'status.external.on', 'status.external.onMessage', ContextualFeedbackSeverity::INFO)
            : $this->status('external', 'status.external.off', 'status.external.offMessage');
    }

    private function status(string $aspect, string $value, string $message, ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK): Status
    {
        return new Status($this->translate('status.'.$aspect.'.title'), $this->translate($value), $this->escape($this->translate($message)), $severity);
    }

    private function translate(string $key): string
    {
        return $this->languageService()->sL(self::LANGUAGE_FILE.$key);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE);
    }

    private function languageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
