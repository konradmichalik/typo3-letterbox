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

namespace KonradMichalik\Typo3Letterbox\Widgets;

use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Dashboard\Widgets\{RequestAwareWidgetInterface, WidgetConfigurationInterface, WidgetInterface};

use function is_array;

/**
 * RecentMailsWidget.
 *
 * Lists the latest caught mails for admins. EXT:dashboard is optional, so this class is registered in Services.php only when it is installed.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class RecentMailsWidget implements WidgetInterface, RequestAwareWidgetInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const PACKAGE = 'konradmichalik/typo3-letterbox';
    private const DASHBOARD_PACKAGE = 'typo3/cms-dashboard';
    private const LATEST_MAILS = 6;

    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly MailRepository $mailRepository,
        private readonly ExtensionSettings $extensionSettings,
        private readonly UriBuilder $uriBuilder,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || !$user->isAdmin()) {
            return '';
        }

        try {
            return $this->backendViewFactory->create($this->request, [self::PACKAGE, self::DASHBOARD_PACKAGE])
                ->assignMultiple($this->variables())
                ->render('Widget/RecentMails');
        } catch (Throwable $exception) {
            // The message of an exception can hold a path or a subject, so only its class is logged.
            $this->logger?->warning('The Letterbox recent mails widget could not be rendered.', ['exception' => $exception::class]);

            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(): array
    {
        $mail = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        $moduleEnabled = '0' !== $this->extensionSettings->get('enableBackendModule', '1');
        $variables = [
            'configuration' => $this->configuration,
            'active' => LetterboxTransport::isSelectedIn(is_array($mail) ? $mail : []),
            'configured' => $this->mailRepository->isConfigured(),
            'mails' => [],
            'openUrl' => $moduleEnabled ? (string) $this->uriBuilder->buildUriFromRoute('system_letterbox') : '',
        ];
        if (!$variables['configured']) {
            return $variables;
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        foreach ($this->mailRepository->findEntries(MailFilter::all(), 0, self::LATEST_MAILS) as $entry) {
            $variables['mails'][] = [
                'subject' => $entry->subject,
                'from' => '' !== $entry->fromName ? $entry->fromName : $entry->sender,
                'received' => $entry->received?->setTimezone($timezone),
                'url' => $moduleEnabled ? (string) $this->uriBuilder->buildUriFromRoute('system_letterbox.show', ['id' => $entry->id]) : '',
            ];
        }

        return $variables;
    }
}
