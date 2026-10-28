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

namespace KonradMichalik\Typo3Letterbox\Backend\ToolbarItem;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\{DeliveryMode, LetterboxSettings, LetterboxTransport};
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Toolbar\{RequestAwareToolbarItemInterface, ToolbarItemInterface};
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\Exception\{ExtensionConfigurationExtensionNotConfiguredException, ExtensionConfigurationPathDoesNotExistException};
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

use function count;
use function is_array;

/**
 * LetterboxToolbarItem.
 *
 * Makes an active mail catcher visible to admins: mails that go missing are otherwise hard to explain.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LetterboxToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const PACKAGE = 'konradmichalik/typo3-letterbox';
    private const LATEST_MAILS = 5;

    private ServerRequestInterface $request;

    public function __construct(
        private readonly BackendViewFactory $backendViewFactory,
        private readonly MailRepository $mailRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly UriBuilder $uriBuilder,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || !$user->isAdmin() || '0' === (string) $this->setting('showToolbarItem', '1')) {
            return false;
        }

        $mail = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        $mail = is_array($mail) ? $mail : [];
        if (!LetterboxTransport::isSelectedIn($mail)) {
            return false;
        }

        // Invalid settings keep the item: it is where the admin learns that Letterbox is not working.
        return DeliveryMode::Off !== $this->settings($mail)?->mode;
    }

    public function getItem(): string
    {
        return $this->render('ToolbarItems/LetterboxToolbarItem');
    }

    public function hasDropDown(): bool
    {
        return true;
    }

    public function getDropDown(): string
    {
        return $this->render('ToolbarItems/LetterboxToolbarItemDropDown');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdditionalAttributes(): array
    {
        return [];
    }

    public function getIndex(): int
    {
        return 50;
    }

    private function render(string $template): string
    {
        try {
            return $this->backendViewFactory->create($this->request, [self::PACKAGE])->assignMultiple($this->variables())->render($template);
        } catch (Throwable $exception) {
            $this->warn($exception);

            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(): array
    {
        $mail = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        $settings = $this->settings(is_array($mail) ? $mail : []);
        $variables = [
            'configured' => false,
            'listed' => DeliveryMode::Listed === $settings?->mode,
            'catchCount' => null === $settings ? 0 : count($settings->catch),
            'count' => 0,
            'mails' => [],
            'path' => '',
            'moduleEnabled' => '0' !== (string) $this->setting('enableBackendModule', '1'),
            'openUrl' => '',
        ];
        if (null === $settings || !$this->mailRepository->isConfigured()) {
            return $variables;
        }

        try {
            $variables['count'] = $this->mailRepository->count(MailFilter::all());
            $variables['mails'] = $this->latestMails($variables['moduleEnabled']);
            $variables['path'] = $this->mailRepository->path();
            $variables['openUrl'] = $variables['moduleEnabled'] ? (string) $this->uriBuilder->buildUriFromRoute('system_letterbox') : '';
            $variables['configured'] = true;
        } catch (Throwable $exception) {
            $this->warn($exception);

            return array_merge($variables, ['configured' => false, 'count' => 0, 'mails' => []]);
        }

        return $variables;
    }

    /**
     * @return list<array{subject: string, time: ?DateTimeImmutable, url: string}>
     */
    private function latestMails(bool $withLinks): array
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $mails = [];
        foreach ($this->mailRepository->findEntries(MailFilter::all(), 0, self::LATEST_MAILS) as $entry) {
            $mails[] = [
                'subject' => $entry->subject,
                'time' => $entry->received?->setTimezone($timezone),
                'url' => $withLinks ? (string) $this->uriBuilder->buildUriFromRoute('system_letterbox.show', ['id' => $entry->id]) : '',
            ];
        }

        return $mails;
    }

    /**
     * @param array<string, mixed> $mail
     */
    private function settings(array $mail): ?LetterboxSettings
    {
        try {
            return LetterboxSettings::fromMailSettings($mail, (string) Environment::getContext());
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function setting(string $name, string $default): mixed
    {
        try {
            return $this->extensionConfiguration->get('typo3_letterbox', $name);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return $default;
        }
    }

    private function warn(Throwable $exception): void
    {
        // The message of an exception can hold a path or a subject, so only its class is logged.
        $this->logger?->warning('The Letterbox toolbar item could not be rendered.', ['exception' => $exception::class]);
    }
}
