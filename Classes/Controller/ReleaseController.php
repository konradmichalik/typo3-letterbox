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

use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Backend\{InboxQuery, MailListView};
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\MailRelease;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use RuntimeException;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\{FlashMessage, FlashMessageService};
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

use function is_array;
use function is_string;
use function sprintf;

/**
 * ReleaseController.
 *
 * Sends a caught mail on to one address of the release allowlist and returns to it. The stored copy stays.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsController]
final readonly class ReleaseController
{
    private const TOKEN_FORM = 'letterbox';
    private const TOKEN_FIELD = 'letterboxToken';
    private const LANGUAGE_FILE = 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private MailRepository $repository,
        private MailRelease $mailRelease,
        private MailListView $mailListView,
        private FormProtectionFactory $formProtectionFactory,
        private Context $context,
        private FlashMessageService $flashMessageService,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function releaseAction(ServerRequestInterface $request): ResponseInterface
    {
        $rejected = $this->rejectUnsafeMutation($request);
        if (null !== $rejected) {
            return $rejected;
        }
        // The route is admin only already. Sending mail out of the catcher is checked here once more.
        if (true !== $this->context->getPropertyFromAspect('backend.user', 'isAdmin', false)) {
            return $this->error(403, 'Only administrators may release mails.');
        }

        $body = (array) $request->getParsedBody();
        $id = $body['id'] ?? null;
        $mail = InboxQuery::isMailId($id) ? $this->repository->findById($id, MailFilter::all()) : null;
        if (null === $mail) {
            return $this->error(404, 'Mail not found.');
        }

        $mailUrl = $this->mailListView->url(InboxQuery::fromRequest($request), ['id' => $mail->id]);
        $refusal = $this->mailRelease->refusal($mail);
        if (null !== $refusal) {
            return $this->redirectWithFlash($this->translate('inbox.release.refusal.'.$refusal), ContextualFeedbackSeverity::ERROR, $mailUrl);
        }

        $recipient = is_string($body['recipient'] ?? null) ? $body['recipient'] : '';
        try {
            $this->mailRelease->release($mail, $recipient, (int) $this->context->getPropertyFromAspect('backend.user', 'id', 0));
        } catch (InvalidArgumentException) {
            return $this->redirectWithFlash($this->translate('flash.releaseNotAllowed'), ContextualFeedbackSeverity::ERROR, $mailUrl);
        } catch (RuntimeException) {
            return $this->redirectWithFlash($this->translate('flash.releaseFailed'), ContextualFeedbackSeverity::ERROR, $mailUrl);
        }

        return $this->redirectWithFlash(sprintf($this->translate('flash.released'), $recipient), ContextualFeedbackSeverity::OK, $mailUrl);
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

    private function redirectWithFlash(string $message, ContextualFeedbackSeverity $severity, string $url): RedirectResponse
    {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(new FlashMessage($message, '', $severity, true));

        return new RedirectResponse($url, 303);
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
