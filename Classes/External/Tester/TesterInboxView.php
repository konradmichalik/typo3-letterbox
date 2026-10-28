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

use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Attachment, Mail, MailLink};
use KonradMichalik\Typo3Letterbox\Html\MailHtmlDocument;
use KonradMichalik\Typo3Letterbox\Mbox\TextSegments;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface, StreamFactoryInterface};
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Localization\{LanguageService, LanguageServiceFactory};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\{ViewFactoryData, ViewFactoryInterface};

use function count;
use function sprintf;

/**
 * TesterInboxView.
 *
 * Renders the tester inbox pages. They run before site resolution, so labels come from a fixed
 * language and never from a site.
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
        'back' => 'detail.back',
        'from' => 'detail.from',
        'to' => 'detail.to',
        'cc' => 'detail.cc',
        'date' => 'detail.date',
        'resetFound' => 'reset.found',
        'resetText' => 'reset.text',
        'resetOpen' => 'reset.open',
        'views' => 'detail.views',
        'viewHtml' => 'detail.viewHtml',
        'viewText' => 'detail.viewText',
        'noText' => 'detail.noText',
        'resetLink' => 'links.reset',
    ];

    public function __construct(
        private ViewFactoryInterface $viewFactory,
        private LanguageServiceFactory $languageServiceFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private MailHtmlDocument $htmlDocument,
    ) {}

    /**
     * @param list<Mail> $mails
     */
    public function list(ServerRequestInterface $request, string $basePath, string $query, int $page, int $pages, int $total, array $mails): ResponseInterface
    {
        $language = $this->languageServiceFactory->create('default');
        $heading = $this->label($language, 'page.heading');

        return $this->render($request, 'List', 200, $language, [
            'title' => $heading,
            'heading' => $heading,
            'basePath' => $basePath,
            'query' => $query,
            'listHeading' => sprintf($this->label($language, 'list.heading'), $total),
            'emptyText' => $this->label($language, '' === $query ? 'list.empty' : 'list.emptySearch'),
            'refreshUrl' => $this->url($basePath, $query, $page),
            'rows' => array_map(fn (Mail $mail): array => $this->row($language, $mail, $basePath), $mails),
            'pagination' => $pages > 1 ? [
                'status' => sprintf($this->label($language, 'pagination.status'), $page, $pages),
                'previous' => $page > 1 ? $this->url($basePath, $query, $page - 1) : '',
                'next' => $page < $pages ? $this->url($basePath, $query, $page + 1) : '',
            ] : null,
        ]);
    }

    /**
     * The detail page shows no headers and no source. The HTML part is only ever a sandboxed srcdoc document
     * without remote content.
     */
    public function show(ServerRequestInterface $request, Mail $mail, string $basePath, bool $textView): ResponseInterface
    {
        $language = $this->languageServiceFactory->create('default');
        $subject = '' !== trim($mail->subject) ? $mail->subject : $this->label($language, 'row.noSubject');
        $detailUrl = $basePath.'/'.$mail->id;
        $html = $textView ? '' : $this->htmlDocument->build($mail);
        $hasHtml = '' !== $html || ($textView && $this->htmlDocument->canBuild($mail));
        $date = $mail->date?->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $reset = array_values(array_filter($mail->links, static fn (MailLink $mailLink): bool => $mailLink->isPasswordReset))[0] ?? null;

        return $this->render($request, 'Show', 200, $language, [
            'title' => $subject,
            'subject' => $subject,
            'backUrl' => $basePath,
            'from' => $this->sender($language, $mail->from, true),
            'to' => self::recipients($mail->to),
            'cc' => self::recipients($mail->cc),
            'date' => $date?->format('Y-m-d H:i') ?? '',
            'isoDate' => $date?->format(\DATE_ATOM) ?? '',
            'resetUrl' => $reset->url ?? '',
            'hasHtml' => $hasHtml,
            'showText' => '' === $html,
            'htmlUrl' => $detailUrl,
            'textUrl' => $detailUrl.'?view=text',
            'htmlDocument' => $html,
            'previewTitle' => sprintf($this->label($language, 'detail.preview'), $subject),
            'textSegments' => '' !== trim($mail->text) ? TextSegments::split($mail->text) : [],
            'links' => $mail->links,
            'linksHeading' => sprintf($this->label($language, 'links.heading'), count($mail->links)),
            'attachments' => array_map(fn (Attachment $attachment): array => $this->attachment($attachment, $detailUrl), $mail->attachments),
            'attachmentsHeading' => sprintf($this->label($language, 'attachments.heading'), count($mail->attachments)),
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
    private function row(LanguageService $language, Mail $mail, string $basePath): array
    {
        $recipients = self::recipients([...$mail->to, ...$mail->cc]);
        $meta = sprintf($this->label($language, 'row.from'), $this->sender($language, $mail->from, false));
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

    /**
     * @param list<Address> $addresses
     */
    private static function recipients(array $addresses): string
    {
        return implode(', ', array_map(static fn (Address $address): string => $address->address, $addresses));
    }

    private function sender(LanguageService $language, ?Address $from, bool $withAddress): string
    {
        return match (true) {
            null === $from => $this->label($language, 'row.unknownSender'),
            '' === $from->name => $from->address,
            $withAddress => $from->name.' <'.$from->address.'>',
            default => $from->name,
        };
    }

    /**
     * @return array{name: string, meta: string, url: string}
     */
    private function attachment(Attachment $attachment, string $detailUrl): array
    {
        return [
            'name' => '' !== $attachment->filename ? $attachment->filename : 'attachment.bin',
            'meta' => $attachment->mimeType.' · '.GeneralUtility::formatSize($attachment->size, ' B| KB| MB| GB'),
            'url' => $detailUrl.'/attachments/'.$attachment->index,
        ];
    }

    private function url(string $basePath, string $query, int $page): string
    {
        $parameters = array_filter(['q' => $query, 'p' => $page > 1 ? (string) $page : ''], static fn (string $value): bool => '' !== $value);

        return [] === $parameters ? $basePath : $basePath.'?'.http_build_query($parameters);
    }

    private function label(LanguageService $language, string $key): string
    {
        return $language->sL(self::LANGUAGE_FILE.$key);
    }
}
