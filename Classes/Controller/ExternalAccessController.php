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

namespace KonradMichalik\Typo3Letterbox\Controller;

use KonradMichalik\Typo3Letterbox\External\Api\ApiMiddleware;
use KonradMichalik\Typo3Letterbox\External\ExternalAccessGuard;
use KonradMichalik\Typo3Letterbox\External\Tester\TesterInboxMiddleware;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function sprintf;

/**
 * ExternalAccessController.
 *
 * The status page of external access: whether it is on, what to change, and where testers and E2E tests connect.
 * The access token itself is never shown.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsController]
final readonly class ExternalAccessController
{
    private const INBOX_ROUTE = 'system_letterbox';
    private const LANGUAGE_FILE = 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang.xlf:';
    private const CSS_FILE = 'EXT:typo3_letterbox/Resources/Public/Css/inbox.css';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ExternalAccessGuard $guard,
        private UriBuilder $uriBuilder,
        private PageRenderer $pageRenderer,
        private IconFactory $iconFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->addCssFile(self::CSS_FILE);

        $params = $request->getAttribute('normalizedParams');
        $siteUrl = $params instanceof NormalizedParams ? $params->getSiteUrl() : '/';
        $context = (string) Environment::getContext();
        $state = $this->guard->evaluate($request);

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('module.title'), $this->translate('access.heading'));
        $back = GeneralUtility::makeInstance(LinkButton::class)
            ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::INBOX_ROUTE))
            ->setTitle($this->translate('access.backToInbox'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $view->getDocHeaderComponent()->getButtonBar()->addButton($back);
        $view->assignMultiple([
            'active' => $state->active,
            'reasons' => array_map(fn (string $reason): string => sprintf($this->translate('access.reason.'.$reason), $context), $state->reasons),
            'context' => $context,
            'tokenConfigured' => '' !== $this->guard->token(),
            'inboxUrl' => $siteUrl.TesterInboxMiddleware::PATH_PREFIX,
            'apiUrl' => $siteUrl.ApiMiddleware::PATH_PREFIX,
        ]);

        return $view->renderResponse('ExternalAccess/Index');
    }

    private function translate(string $key): string
    {
        return $this->getLanguageService()->sL(self::LANGUAGE_FILE.$key);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
