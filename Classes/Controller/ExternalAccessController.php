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
use Doctrine\DBAL\ArrayParameterType;
use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\External\Api\ApiMiddleware;
use KonradMichalik\Typo3Letterbox\External\{EnvironmentVariables, ExternalAccessGuard, ExternalAccessState};
use KonradMichalik\Typo3Letterbox\External\Tester\{TesterInboxMiddleware, TesterLink, TesterLinkRepository};
use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\{NormalizedParams, RedirectResponse};
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\{FlashMessage, FlashMessageService};
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;
use function strlen;

/**
 * ExternalAccessController.
 *
 * The page where admins see whether external access is on and issue or revoke tester links. The URL of a new
 * link is rendered in the create response only, never stored, flashed or redirected to.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsController]
final readonly class ExternalAccessController
{
    private const INBOX_ROUTE = 'system_letterbox';
    private const ACCESS_ROUTE = 'system_letterbox.access';
    private const TOKEN_FORM = 'letterbox';
    // TYPO3 reads a body field named "token" as the backend route token, so the form token needs its own name.
    private const TOKEN_FIELD = 'letterboxToken';
    private const LANGUAGE_FILE = 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang.xlf:';
    private const MAX_LABEL_LENGTH = 255;
    private const DAYS = [1, 7, 30];
    private const DEFAULT_DAYS = 7;
    private const CSS_FILE = 'EXT:typo3_letterbox/Resources/Public/Css/inbox.css';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ExternalAccessGuard $guard,
        private TesterLinkRepository $repository,
        private EnvironmentVariables $environment,
        private FormProtectionFactory $formProtectionFactory,
        private UriBuilder $uriBuilder,
        private ResponseFactoryInterface $responseFactory,
        private PageRenderer $pageRenderer,
        private Context $context,
        private FlashMessageService $flashMessageService,
        private ConnectionPool $connectionPool,
        private IconFactory $iconFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->emptyForm(), [], null);
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $rejected = $this->rejectUnsafeMutation($request);
        if (null !== $rejected) {
            return $rejected;
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $form = $this->formFrom($body);
        [$scope, $errors] = $this->validate($form);
        if ([] !== $errors) {
            return $this->noStore($this->page($request, $form, $errors, null));
        }

        $issued = $this->repository->issue(
            $form['label'],
            $scope,
            $form['unrestricted'],
            $form['canDownload'],
            $form['canDelete'],
            $form['days'],
            $this->backendUserId(),
            $this->now()->getTimestamp(),
        );
        $params = $request->getAttribute('normalizedParams');
        $siteUrl = $params instanceof NormalizedParams ? $params->getSiteUrl() : '/';
        $created = [
            'url' => $siteUrl.TesterInboxMiddleware::PATH_PREFIX.'?t='.$issued->token,
            'label' => $issued->link->label,
            'expires' => $this->formatTime($issued->link->expires),
        ];

        return $this->noStore($this->page($request, $this->emptyForm(), [], $created));
    }

    public function revokeAction(ServerRequestInterface $request): ResponseInterface
    {
        $rejected = $this->rejectUnsafeMutation($request);
        if (null !== $rejected) {
            return $rejected;
        }

        $body = $request->getParsedBody();
        $uid = is_array($body) ? ($body['uid'] ?? null) : null;
        $link = is_string($uid) && 1 === preg_match('/^\d{1,9}$/D', $uid) ? $this->findLink((int) $uid) : null;
        if (null === $link) {
            return $this->error(404, 'Tester link not found.');
        }

        $this->repository->revoke($link->uid, $this->now()->getTimestamp());
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($this->translate('flash.access.revoked'), '', ContextualFeedbackSeverity::OK, true),
        );

        return new RedirectResponse((string) $this->uriBuilder->buildUriFromRoute(self::ACCESS_ROUTE), 303);
    }

    /**
     * @param array{label: string, scope: string, unrestricted: bool, canDownload: bool, canDelete: bool, days: int} $form
     * @param list<array{field: string, text: string}>                                                               $errors
     * @param array{url: string, label: string, expires: string}|null                                                $created
     */
    private function page(ServerRequestInterface $request, array $form, array $errors, ?array $created): ResponseInterface
    {
        $this->pageRenderer->addCssFile(self::CSS_FILE);
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/modal.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/copy-to-clipboard.js');

        $params = $request->getAttribute('normalizedParams');
        $siteUrl = $params instanceof NormalizedParams ? $params->getSiteUrl() : '/';
        $state = $this->guard->status();
        $links = $this->repository->findAll();

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
            'reasons' => $this->reasonTexts($state),
            'context' => (string) Environment::getContext(),
            'siteUrl' => $siteUrl,
            'inboxUrl' => $siteUrl.TesterInboxMiddleware::PATH_PREFIX,
            'apiUrl' => $siteUrl.ApiMiddleware::PATH_PREFIX,
            'apiTokenConfigured' => strlen($this->environment->get(ApiMiddleware::ENV_TOKEN)) >= ApiMiddleware::MIN_TOKEN_LENGTH,
            'links' => $this->linkRows($links),
            'linkCount' => count($links),
            'form' => $form,
            'errors' => $errors,
            'errorFields' => array_fill_keys(array_column($errors, 'field'), true),
            'created' => $created,
            'days' => self::DAYS,
            'token' => $this->formProtectionFactory->createFromRequest($request)->generateToken(self::TOKEN_FORM),
        ]);

        return $view->renderResponse('ExternalAccess/Index');
    }

    /**
     * @return list<string>
     */
    private function reasonTexts(ExternalAccessState $state): array
    {
        $context = (string) Environment::getContext();

        return array_map(fn (string $reason): string => sprintf($this->translate('access.reason.'.$reason), $context), $state->reasons);
    }

    /**
     * @param list<TesterLink> $links
     *
     * @return list<array<string, mixed>>
     */
    private function linkRows(array $links): array
    {
        $now = $this->now()->getTimestamp();
        $users = $this->usernames(array_map(static fn (TesterLink $link): int => $link->createdBy, $links));
        $rows = [];
        foreach ($links as $link) {
            $state = match (true) {
                0 !== $link->revoked => 'revoked',
                $now >= $link->expires => 'expired',
                !$link->isUsable($now) => 'invalid',
                default => 'active',
            };
            $rows[] = [
                'uid' => $link->uid,
                'label' => $link->label,
                'unrestricted' => $link->unrestricted && $link->scopeValid,
                'scope' => array_map(static fn (RecipientPattern $pattern): string => $pattern->value(), $link->scope),
                'scopeValid' => $link->scopeValid,
                'canDownload' => $link->canDownload,
                'canDelete' => $link->canDelete,
                'expires' => $this->formatTime($link->expires),
                'lastUsed' => 0 === $link->lastUsed ? '' : $this->formatTime($link->lastUsed),
                'createdBy' => $users[$link->createdBy] ?? '',
                'state' => $state,
                'revocable' => 'revoked' !== $state,
            ];
        }

        return $rows;
    }

    /**
     * Only the username, no other personal field of the backend user.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function usernames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => 0 !== $id)));
        if ([] === $ids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($ids, ArrayParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['uid']] = (string) $row['username'];
        }

        return $names;
    }

    /**
     * @return array{label: string, scope: string, unrestricted: bool, canDownload: bool, canDelete: bool, days: int}
     */
    private function emptyForm(): array
    {
        return ['label' => '', 'scope' => '', 'unrestricted' => false, 'canDownload' => false, 'canDelete' => false, 'days' => self::DEFAULT_DAYS];
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array{label: string, scope: string, unrestricted: bool, canDownload: bool, canDelete: bool, days: int}
     */
    private function formFrom(array $body): array
    {
        $text = static fn (string $name): string => is_string($body[$name] ?? null) ? $body[$name] : '';
        $days = $text('days');

        return [
            'label' => trim($text('label')),
            'scope' => $text('scope'),
            'unrestricted' => '' !== $text('unrestricted'),
            'canDownload' => '' !== $text('canDownload'),
            'canDelete' => '' !== $text('canDelete'),
            'days' => 1 === preg_match('/^\d{1,3}$/D', $days) ? (int) $days : 0,
        ];
    }

    /**
     * @param array{label: string, scope: string, unrestricted: bool, canDownload: bool, canDelete: bool, days: int} $form
     *
     * @return array{list<RecipientPattern>, list<array{field: string, text: string}>}
     */
    private function validate(array $form): array
    {
        $errors = [];
        if ('' === $form['label']) {
            $errors[] = ['field' => 'label', 'text' => $this->translate('access.error.label')];
        } elseif (mb_strlen($form['label']) > self::MAX_LABEL_LENGTH) {
            $errors[] = ['field' => 'label', 'text' => $this->translate('access.error.labelLength')];
        }
        if (!in_array($form['days'], self::DAYS, true)) {
            $errors[] = ['field' => 'days', 'text' => $this->translate('access.error.days')];
        }

        [$scope, $lineErrors] = $this->parseScope($form['scope']);
        $errors = [...$errors, ...$lineErrors];

        if ([] === $scope && [] === $lineErrors && !$form['unrestricted']) {
            $errors[] = ['field' => 'scope', 'text' => $this->translate('access.error.scopeEmpty')];
        }
        if ([] !== $scope && $form['unrestricted']) {
            $errors[] = ['field' => 'scope', 'text' => $this->translate('access.error.unrestrictedWithScope')];
        }

        return [$scope, $errors];
    }

    /**
     * @return array{list<RecipientPattern>, list<array{field: string, text: string}>}
     */
    private function parseScope(string $input): array
    {
        $patterns = [];
        $errors = [];
        foreach (preg_split('/\R/u', $input) ?: [] as $index => $line) {
            if ('' === trim($line)) {
                continue;
            }
            try {
                $pattern = RecipientPattern::fromString($line);
                $patterns[$pattern->value()] = $pattern;
            } catch (InvalidArgumentException) {
                $errors[] = ['field' => 'scope', 'text' => sprintf($this->translate('access.error.scopeLine'), $index + 1)];
            }
        }

        return [array_values($patterns), $errors];
    }

    private function findLink(int $uid): ?TesterLink
    {
        foreach ($this->repository->findAll() as $link) {
            if ($link->uid === $uid) {
                return $link;
            }
        }

        return null;
    }

    private function backendUserId(): int
    {
        $id = $this->context->getPropertyFromAspect('backend.user', 'id');

        return is_int($id) ? $id : 0;
    }

    private function formatTime(int $timestamp): string
    {
        return $this->now()->setTimestamp($timestamp)->format('Y-m-d H:i');
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->context->getPropertyFromAspect('date', 'full');

        return $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable();
    }

    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store');
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
