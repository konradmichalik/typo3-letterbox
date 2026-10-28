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

namespace KonradMichalik\Typo3Letterbox\External\Tester;

use DateTimeImmutable;
use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Mail, MailFilter};
use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface, StreamFactoryInterface};
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Localization\{LanguageService, LanguageServiceFactory};
use TYPO3\CMS\Core\View\{ViewFactoryData, ViewFactoryInterface};

use function count;
use function sprintf;

/**
 * TesterInboxView.
 *
 * Renders the tester inbox pages. They run before site resolution, so labels come from a fixed
 * language and never from a site. Masked recipients are counted, never handed to the template.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TesterInboxView
{
    private const LANGUAGE_FILE = 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang_tester.xlf:';
    private const PATHS = 'EXT:typo3_letterbox/Resources/Private/Tester/';
    private const LABELS = [
        'brand' => 'page.brand',
        'notDelivered' => 'page.notDelivered',
        'skip' => 'page.skip',
        'searchLabel' => 'search.label',
        'searchSubmit' => 'search.submit',
        'searchClear' => 'search.clear',
        'refresh' => 'list.refresh',
        'attachments' => 'row.attachments',
        'paginationLabel' => 'pagination.label',
        'previous' => 'pagination.previous',
        'next' => 'pagination.next',
        'notFoundHeading' => 'notFound.heading',
        'notFoundText' => 'notFound.text',
    ];

    public function __construct(
        private ViewFactoryInterface $viewFactory,
        private LanguageServiceFactory $languageServiceFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param list<Mail> $mails
     * @param MailFilter $filter the link filter, it decides which recipients are visible
     */
    public function list(ServerRequestInterface $request, TesterLink $link, MailFilter $filter, string $basePath, string $query, int $page, int $pages, int $total, array $mails): ResponseInterface
    {
        $language = $this->languageServiceFactory->create('default');
        $heading = '' !== trim($link->label) ? $link->label : $this->label($language, 'page.fallbackHeading');

        return $this->render($request, 'List', 200, $language, [
            'title' => $heading,
            'heading' => $heading,
            'scope' => sprintf($this->label($language, 'scope.mailsFor'), $this->scopeSummary($language, $link)),
            'validUntil' => sprintf($this->label($language, 'scope.validUntil'), $this->localTime($link->expires)),
            'basePath' => $basePath,
            'query' => $query,
            'listHeading' => sprintf($this->label($language, 'list.heading'), $total),
            'emptyText' => $this->label($language, '' === $query ? 'list.empty' : 'list.emptySearch'),
            'refreshUrl' => $this->url($basePath, $query, $page),
            'rows' => array_map(fn (Mail $mail): array => $this->row($language, $mail, $filter, $basePath), $mails),
            'pagination' => $pages > 1 ? [
                'status' => sprintf($this->label($language, 'pagination.status'), $page, $pages),
                'previous' => $page > 1 ? $this->url($basePath, $query, $page - 1) : '',
                'next' => $page < $pages ? $this->url($basePath, $query, $page + 1) : '',
            ] : null,
        ]);
    }

    /**
     * The one page for every refusal, so its content never tells why.
     */
    public function notFound(ServerRequestInterface $request): ResponseInterface
    {
        $language = $this->languageServiceFactory->create('default');

        return $this->render($request, 'NotFound', 404, $language, [
            'title' => $this->label($language, 'notFound.heading'),
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(ServerRequestInterface $request, string $template, int $status, LanguageService $language, array $variables): ResponseInterface
    {
        $params = $request->getAttribute('normalizedParams');
        $view = $this->viewFactory->create(new ViewFactoryData([self::PATHS.'Templates'], null, [self::PATHS.'Layouts'], null, $request));
        $view->assignMultiple([
            ...$variables,
            'labels' => array_map(fn (string $key): string => $this->label($language, $key), self::LABELS),
            'host' => $params instanceof NormalizedParams ? $params->getHttpHost() : '',
        ]);

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streamFactory->createStream($view->render($template)));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LanguageService $language, Mail $mail, MailFilter $filter, string $basePath): array
    {
        $visible = $filter->visibleRecipients($mail);
        $hidden = count($mail->to) + count($mail->cc) - count($visible);
        $recipients = implode(', ', array_map(static fn (Address $address): string => $address->address, $visible));
        if ([] === $visible) {
            $recipients = $hidden > 0 ? sprintf($this->label($language, 'row.hiddenOnly'), $hidden) : '';
        } elseif ($hidden > 0) {
            $recipients .= ' '.sprintf($this->label($language, 'row.more'), $hidden);
        }
        $sender = match (true) {
            null === $mail->from => $this->label($language, 'row.unknownSender'),
            '' !== $mail->from->name => $mail->from->name,
            default => $mail->from->address,
        };
        $meta = sprintf($this->label($language, 'row.from'), $sender);
        $date = $mail->date?->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return [
            'url' => $basePath.'/'.$mail->id,
            'subject' => '' !== trim($mail->subject) ? $mail->subject : $this->label($language, 'row.noSubject'),
            'meta' => '' === $recipients ? $meta : $meta.' '.sprintf($this->label($language, 'row.to'), $recipients),
            'date' => $date?->format('Y-m-d H:i') ?? '',
            'isoDate' => $date?->format(\DATE_ATOM) ?? '',
            'hasAttachments' => [] !== $mail->attachments,
        ];
    }

    private function url(string $basePath, string $query, int $page): string
    {
        $parameters = array_filter(['q' => $query, 'p' => $page > 1 ? (string) $page : ''], static fn (string $value): bool => '' !== $value);

        return [] === $parameters ? $basePath : $basePath.'?'.http_build_query($parameters);
    }

    private function scopeSummary(LanguageService $language, TesterLink $link): string
    {
        if ([] === $link->scope) {
            return $this->label($language, 'scope.all');
        }

        return implode(', ', array_map(static fn (RecipientPattern $pattern): string => $pattern->value(), $link->scope));
    }

    private function localTime(int $timestamp): string
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
    }

    private function label(LanguageService $language, string $key): string
    {
        return $language->sL(self::LANGUAGE_FILE.$key);
    }
}
