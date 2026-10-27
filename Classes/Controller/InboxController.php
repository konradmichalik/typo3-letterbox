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

use KonradMichalik\Typo3Letterbox\Domain\Model\{Mail, MailFilter};
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Html\MailHtmlDocument;
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
use KonradMichalik\Typo3Letterbox\Pagination\MailPaginator;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\{InputButton, LinkButton};
use TYPO3\CMS\Backend\Template\{ModuleTemplate, ModuleTemplateFactory};
use TYPO3\CMS\Core\Configuration\Exception\{ExtensionConfigurationExtensionNotConfiguredException, ExtensionConfigurationPathDoesNotExistException};
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_array;
use function is_string;

/**
 * InboxController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsController]
final readonly class InboxController
{
    private const MODULE = 'system_letterbox';
    private const TOKEN_FORM = 'letterbox';
    // TYPO3 v13 reads a body field named "token" as the backend route token, so the form token needs its own name.
    private const TOKEN_FIELD = 'letterboxToken';
    private const CLEAR_FORM_ID = 'letterbox-clear-form';
    private const LANGUAGE_FILE = 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang.xlf:';
    private const DEFAULT_MAILS_PER_PAGE = 10;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private MailRepository $repository,
        private MailHtmlDocument $htmlDocument,
        private DownloadResponseFactory $downloadResponseFactory,
        private FormProtectionFactory $formProtectionFactory,
        private ExtensionConfiguration $extensionConfiguration,
        private UriBuilder $uriBuilder,
        private ResponseFactoryInterface $responseFactory,
        private IconFactory $iconFactory,
        private PageRenderer $pageRenderer,
        private Typo3Version $typo3Version,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->createView($request)->renderResponse('Inbox/Index');
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $mail = $this->mailFromQuery($request);
        if (null === $mail) {
            return $this->error(404, 'Mail not found.');
        }

        $htmlDocument = $this->htmlDocument->build($mail);
        $view = $this->createView($request, $mail->id);
        $view->assignMultiple([
            'mail' => $mail,
            'htmlDocument' => $htmlDocument,
            'defaultTab' => '' === $htmlDocument ? 'text' : 'html',
        ]);

        return $view->renderResponse('Inbox/Show');
    }

    public function sourceAction(ServerRequestInterface $request): ResponseInterface
    {
        $mail = $this->mailFromQuery($request);

        return null === $mail ? $this->error(404, 'Mail not found.') : $this->downloadResponseFactory->source($mail);
    }

    public function attachmentAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $id = $this->validId($query['id'] ?? null);
        $index = $query['index'] ?? null;
        if (null === $id || !is_string($index) || 1 !== preg_match('/^\d{1,9}$/D', $index)) {
            return $this->error(404, 'Attachment not found.');
        }

        $attachment = $this->repository->attachment($id, (int) $index, MailFilter::all());

        return null === $attachment ? $this->error(404, 'Attachment not found.') : $this->downloadResponseFactory->attachment($attachment);
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $rejected = $this->rejectUnsafeMutation($request);
        if (null !== $rejected) {
            return $rejected;
        }

        $body = $request->getParsedBody();
        $id = $this->validId(is_array($body) ? ($body['id'] ?? null) : null);
        if (null === $id || !$this->repository->delete($id)) {
            return $this->error(404, 'Mail not found.');
        }

        return $this->redirectToIndex();
    }

    public function clearAction(ServerRequestInterface $request): ResponseInterface
    {
        $rejected = $this->rejectUnsafeMutation($request);
        if (null !== $rejected) {
            return $rejected;
        }

        $this->repository->clear();

        return $this->redirectToIndex();
    }

    private function createView(ServerRequestInterface $request, string $selectedId = ''): ModuleTemplate
    {
        $filter = MailFilter::all();
        $page = $this->pageFromQuery($request);
        $paginator = new MailPaginator($this->repository, $filter, $page, $this->mailsPerPage());
        $total = $paginator->getTotalNumberOfItems();
        $token = $this->formProtectionFactory->createFromRequest($request)->generateToken(self::TOKEN_FORM);

        $this->pageRenderer->addCssFile('EXT:typo3_letterbox/Resources/Public/Css/inbox.css');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/modal.js');
        $this->pageRenderer->loadJavaScriptModule($this->typo3Version->getMajorVersion() >= 14 ? '@typo3/backend/tab.js' : '@typo3/backend/tabs.js');

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('module.title'));
        $this->addDocHeaderButtons($view, $request, $total > 0);
        $view->assignMultiple([
            'configured' => $this->repository->isConfigured(),
            'path' => $this->repository->path(),
            'size' => $this->repository->sizeInBytes(),
            'total' => $total,
            'mails' => $paginator->getPaginatedItems(),
            'paginator' => $paginator,
            'pagination' => new SimplePagination($paginator),
            'page' => $paginator->getCurrentPageNumber(),
            'selectedId' => $selectedId,
            'token' => $token,
            'clearFormId' => self::CLEAR_FORM_ID,
            'debugMode' => '1' === (string) $this->extensionSetting('debugMode'),
        ]);

        return $view;
    }

    private function addDocHeaderButtons(ModuleTemplate $view, ServerRequestInterface $request, bool $hasMails): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();

        // TYPO3 v14 adds a reload button to every module on its own.
        if ($this->typo3Version->getMajorVersion() < 14) {
            $reload = GeneralUtility::makeInstance(LinkButton::class)
                ->setHref((string) $request->getUri())
                ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
                ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL));
            $buttonBar->addButton($reload, 'right');
        }

        if (!$hasMails) {
            return;
        }

        $clear = GeneralUtility::makeInstance(InputButton::class)
            ->setName('clear')
            ->setValue('1')
            ->setForm(self::CLEAR_FORM_ID)
            ->setTitle($this->translate('inbox.clearAll'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-delete', IconSize::SMALL))
            ->setClasses('btn-danger letterbox-clear-all t3js-modal-trigger')
            ->setDataAttributes([
                'severity' => 'error',
                'target-form' => self::CLEAR_FORM_ID,
                'title' => $this->translate('inbox.clearAll.confirm.title'),
                'content' => $this->translate('inbox.clearAll.confirm.content'),
                'button-close-text' => $this->translate('inbox.cancel'),
                'button-ok-text' => $this->translate('inbox.clearAll'),
            ]);
        $buttonBar->addButton($clear);
    }

    private function rejectUnsafeMutation(ServerRequestInterface $request): ?ResponseInterface
    {
        if ('POST' !== $request->getMethod()) {
            return $this->error(405, 'Method not allowed.')->withHeader('Allow', 'POST');
        }

        $body = $request->getParsedBody();
        $token = is_array($body) ? ($body[self::TOKEN_FIELD] ?? '') : '';
        if (!is_string($token) || !$this->formProtectionFactory->createFromRequest($request)->validateToken($token, self::TOKEN_FORM)) {
            return $this->error(403, 'Invalid or missing form token. Reload the module and try again.');
        }

        return null;
    }

    private function mailFromQuery(ServerRequestInterface $request): ?Mail
    {
        $id = $this->validId($request->getQueryParams()['id'] ?? null);

        return null === $id ? null : $this->repository->findById($id, MailFilter::all());
    }

    private function validId(mixed $id): ?string
    {
        return is_string($id) && 1 === preg_match('/^[0-9a-f]{32}$/D', $id) ? $id : null;
    }

    private function pageFromQuery(ServerRequestInterface $request): int
    {
        $page = $request->getQueryParams()['page'] ?? '1';

        return is_string($page) && 1 === preg_match('/^[1-9]\d{0,8}$/D', $page) ? (int) $page : 1;
    }

    private function mailsPerPage(): int
    {
        $value = (int) $this->extensionSetting('mailsPerPage');

        return $value > 0 ? $value : self::DEFAULT_MAILS_PER_PAGE;
    }

    private function extensionSetting(string $name): mixed
    {
        try {
            return $this->extensionConfiguration->get('typo3_letterbox', $name);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return null;
        }
    }

    private function redirectToIndex(): RedirectResponse
    {
        return new RedirectResponse((string) $this->uriBuilder->buildUriFromRoute(self::MODULE), 303);
    }

    private function error(int $status, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->getBody()->write($message);

        return $response;
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
