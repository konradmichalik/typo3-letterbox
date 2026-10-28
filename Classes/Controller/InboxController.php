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

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Backend\{InboxQuery, MailListView, ReadState};
use KonradMichalik\Typo3Letterbox\Domain\Model\{Mail, MailFilter, QuickFilter};
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Html\MailHtmlDocument;
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
use KonradMichalik\Typo3Letterbox\Mbox\{MessageIndexEntry, TextSegments};
use KonradMichalik\Typo3Letterbox\Pagination\MailPaginator;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use RuntimeException;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\{InputButton, LinkButton};
use TYPO3\CMS\Backend\Template\{ModuleTemplate, ModuleTemplateFactory};
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Configuration\Exception\{ExtensionConfigurationExtensionNotConfiguredException, ExtensionConfigurationPathDoesNotExistException};
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\{FlashMessage, FlashMessageService};
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;
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
    // TYPO3 reads a body field named "token" as the backend route token, so the form token needs its own name.
    private const TOKEN_FIELD = 'letterboxToken';
    private const CLEAR_FORM_ID = 'letterbox-clear-form';
    private const LANGUAGE_FILE = 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang.xlf:';
    private const DEFAULT_MAILS_PER_PAGE = 10;
    private const PAGE_LINKS = 5;
    // inbox.js asks for one region of the page with this header and swaps it in place.
    private const FRAGMENT_HEADER = 'X-Letterbox-Fragment';
    private const PREVIEW_POLICY = "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'; sandbox allow-popups allow-popups-to-escape-sandbox";

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
        private Context $context,
        private FlashMessageService $flashMessageService,
        private ReadState $readState,
        private MailListView $mailListView,
        private BackendViewFactory $backendViewFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = InboxQuery::fromRequest($request);

        return match ($this->requestedFragment($request)) {
            'list' => $this->fragment($request, 'list', $this->listVariables($request, $query)),
            'detail' => $this->fragment($request, 'placeholder', []),
            default => $this->page($request, $query, 'Inbox/Index', []),
        };
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = InboxQuery::fromRequest($request);
        $fragment = $this->requestedFragment($request);
        if ('list' === $fragment) {
            return $this->fragment($request, 'list', $this->listVariables($request, $query));
        }

        // The selected mail opens even when it does not match the current search or quick filter.
        $mail = null === $query->id ? null : $this->repository->findById($query->id, MailFilter::all());
        if (null === $mail) {
            return $this->mailNotFound($query, $fragment);
        }

        $this->readState->markRead($mail->id);
        $detail = $this->detailVariables($request, $query, $mail);

        return 'detail' === $fragment ? $this->fragment($request, 'detail', $detail) : $this->page($request, $query, 'Inbox/Show', $detail);
    }

    /**
     * Frames the mail with remote images. The backend CSP is inherited by srcdoc frames and would block them, so
     * this response carries its own policy (the middleware leaves a response with a policy alone). The sandbox
     * directive keeps the document sandboxed even when the URL is opened directly.
     */
    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        $mail = $this->mailFromQuery($request);
        $document = null === $mail ? '' : $this->htmlDocument->build($mail, true);
        if ('' === $document) {
            return $this->error(404, 'Mail not found.');
        }

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', self::PREVIEW_POLICY)
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($document);

        return $response;
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
        if (null === $id) {
            return $this->error(404, 'Mail not found.');
        }

        $listUrl = $this->mailListView->url(InboxQuery::fromRequest($request), ['id' => null, 'tab' => null]);
        try {
            $deleted = $this->repository->delete($id, MailFilter::all());
        } catch (RuntimeException) {
            return $this->redirectWithFlash('flash.deleteFailed', ContextualFeedbackSeverity::ERROR, $listUrl);
        }

        return $deleted ? $this->redirectWithFlash('flash.deleted', ContextualFeedbackSeverity::OK, $listUrl) : $this->error(404, 'Mail not found.');
    }

    public function clearAction(ServerRequestInterface $request): ResponseInterface
    {
        $rejected = $this->rejectUnsafeMutation($request);
        if (null !== $rejected) {
            return $rejected;
        }

        try {
            $this->repository->clear();
        } catch (RuntimeException) {
            return $this->redirectWithFlash('flash.clearFailed', ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirectWithFlash('flash.cleared', ContextualFeedbackSeverity::OK);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function page(ServerRequestInterface $request, InboxQuery $query, string $template, array $variables): ResponseInterface
    {
        $this->pageRenderer->addCssFile('EXT:typo3_letterbox/Resources/Public/Css/inbox.css');
        $this->pageRenderer->loadJavaScriptModule('@konradmichalik/typo3-letterbox/inbox.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/modal.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/copy-to-clipboard.js');
        $this->pageRenderer->loadJavaScriptModule($this->typo3Version->getMajorVersion() >= 14 ? '@typo3/backend/tab.js' : '@typo3/backend/tabs.js');

        $list = $this->listVariables($request, $query);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('module.title'));
        $this->addDocHeaderButtons($view, $request, $list['total'] > 0);
        $view->assignMultiple($list + $variables + [
            'configured' => $this->repository->isConfigured(),
            'path' => $this->repository->path(),
            'size' => $this->repository->sizeInBytes(),
            'debugMode' => '1' === (string) $this->extensionSetting('debugMode'),
        ]);

        // The same URL answers with a fragment or the full page, depending on the request header.
        return $view->renderResponse($template)->withAddedHeader('Vary', self::FRAGMENT_HEADER);
    }

    /**
     * Everything the mail list partial renders, for the full page and the list fragment alike.
     *
     * @return array<string, mixed>&array{total: int}
     */
    private function listVariables(ServerRequestInterface $request, InboxQuery $query): array
    {
        $readIds = $this->readState->readIds();
        $filter = MailFilter::create($query->query, $query->quick, $readIds);
        $counts = $this->repository->quickFilterCounts($filter);
        $total = '' === $filter->query() ? $counts[QuickFilter::All->value] : $this->repository->count(MailFilter::all());
        $paginator = new MailPaginator($this->repository, $filter, $query->page, $this->mailsPerPage(), $counts[$filter->quick()->value]);
        $pagination = new SlidingWindowPagination($paginator, self::PAGE_LINKS);

        return $this->mailListView->build($query, $paginator->getPaginatedItems(), $pagination, $counts, array_flip($readIds)) + [
            'total' => $total,
            'mails' => $paginator->getPaginatedItems(),
            'listDates' => $this->listDates($paginator->getPaginatedItems()),
            'paginator' => $paginator,
            'pagination' => $pagination,
            'selectedId' => $query->id ?? '',
            'token' => $this->token($request),
            'clearFormId' => self::CLEAR_FORM_ID,
            'listCollapsed' => $this->readState->isListCollapsed(),
        ];
    }

    /**
     * Everything the mail detail partial renders, for the full page and the detail fragment alike.
     *
     * @return array<string, mixed>
     */
    private function detailVariables(ServerRequestInterface $request, InboxQuery $query, Mail $mail): array
    {
        // Remote mode frames the preview route, which builds the document itself.
        $htmlDocument = $query->remote ? '' : $this->htmlDocument->build($mail);
        $hasPreview = $query->remote ? $this->htmlDocument->canBuild($mail) : '' !== $htmlDocument;
        $hasHtml = '' !== trim($mail->html);

        return $this->mailListView->previewControls($query) + [
            'mail' => $mail,
            'mailDate' => $mail->date?->setTimezone($this->now()->getTimezone()),
            'hasHtml' => $hasHtml,
            'htmlDocument' => $htmlDocument,
            'hasPreview' => $hasPreview,
            'unresolvedContentIds' => $this->htmlDocument->unresolvedContentIds($mail),
            'previewWidth' => $query->width,
            'remote' => $query->remote,
            'textSegments' => TextSegments::split($mail->text),
            'headersUrl' => $this->mailListView->url($query, ['id' => $mail->id, 'tab' => 'headers']),
            'defaultTab' => $this->defaultTab($query->tab, $hasHtml, $hasPreview),
            'deleteUrl' => $this->mailListView->deleteUrl($query),
            'token' => $this->token($request),
        ];
    }

    private function requestedFragment(ServerRequestInterface $request): ?string
    {
        $value = $request->getHeaderLine(self::FRAGMENT_HEADER);

        return in_array($value, ['list', 'detail'], true) ? $value : null;
    }

    /**
     * Renders one region with the same partial the full page uses, without module layout and doc header.
     *
     * @param array<string, mixed> $variables
     */
    private function fragment(ServerRequestInterface $request, string $fragment, array $variables): ResponseInterface
    {
        $view = $this->backendViewFactory->create($request);
        $view->assignMultiple($variables + ['fragment' => $fragment]);

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Vary', self::FRAGMENT_HEADER);
        $response->getBody()->write($view->render('Inbox/Fragment'));

        return $response;
    }

    private function token(ServerRequestInterface $request): string
    {
        return $this->formProtectionFactory->createFromRequest($request)->generateToken(self::TOKEN_FORM);
    }

    private function defaultTab(?string $requested, bool $hasHtml, bool $hasPreview): string
    {
        $available = $hasHtml ? ['html', 'text', 'headers', 'source', 'links', 'attachments'] : ['text', 'headers', 'source', 'links', 'attachments'];
        if (null !== $requested && in_array($requested, $available, true)) {
            return $requested;
        }

        return $hasPreview ? 'html' : 'text';
    }

    private function addDocHeaderButtons(ModuleTemplate $view, ServerRequestInterface $request, bool $hasMails): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();

        // inbox.js keeps the reload link on the current URL after it switched mails, so the module brings its own
        // instead of the automatic one TYPO3 v14 adds.
        if ($this->typo3Version->getMajorVersion() >= 14) {
            $view->getDocHeaderComponent()->disableAutomaticReloadButton();
        }
        $reload = GeneralUtility::makeInstance(LinkButton::class)
            ->setHref((string) $request->getUri())
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL))
            ->setDataAttributes(['letterbox-reload' => '1']);
        $buttonBar->addButton($reload, 'right');

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
        $id = InboxQuery::fromRequest($request)->id;

        return null === $id ? null : $this->repository->findById($id, MailFilter::all());
    }

    /**
     * A fragment request answers 404 so inbox.js falls back to a full page load. That one, e.g. going back to a
     * deleted mail, returns to the list.
     */
    private function mailNotFound(InboxQuery $query, ?string $fragment): ResponseInterface
    {
        if (null !== $fragment) {
            return $this->error(404, 'Mail not found.');
        }

        return $this->redirectWithFlash('flash.notFound', ContextualFeedbackSeverity::WARNING, $this->mailListView->url($query, ['id' => null, 'tab' => null]));
    }

    private function validId(mixed $id): ?string
    {
        return InboxQuery::isMailId($id) ? $id : null;
    }

    /**
     * List rows show the time for today's mails, "Yesterday", or the date, in the backend's timezone.
     *
     * @param iterable<MessageIndexEntry> $entries
     *
     * @return array<string, string>
     */
    private function listDates(iterable $entries): array
    {
        $now = $this->now();
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $labels = [];
        foreach ($entries as $entry) {
            if (null === $entry->date) {
                continue;
            }
            $local = $entry->date->setTimezone($now->getTimezone());
            $labels[$entry->id] = match ($local->format('Y-m-d')) {
                $today => $local->format('H:i'),
                $yesterday => $this->translate('inbox.yesterday'),
                default => $local->format('Y-m-d'),
            };
        }

        return $labels;
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->context->getPropertyFromAspect('date', 'full');

        return $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable();
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

    private function redirectWithFlash(string $messageKey, ContextualFeedbackSeverity $severity, ?string $url = null): RedirectResponse
    {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($this->translate($messageKey), '', $severity, true),
        );

        return new RedirectResponse($url ?? (string) $this->uriBuilder->buildUriFromRoute(self::MODULE), 303);
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
