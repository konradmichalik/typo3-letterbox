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

namespace KonradMichalik\Typo3Letterbox\Http;

use KonradMichalik\Typo3Letterbox\Domain\Model\{Attachment, Mail};
use KonradMichalik\Typo3Letterbox\Mbox\MboxFormat;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};

use function sprintf;

/**
 * DownloadResponseFactory.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DownloadResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private MboxFormat $mboxFormat,
    ) {}

    public function source(Mail $mail): ResponseInterface
    {
        return $this->download($mail->id.'.eml', $this->mboxFormat->toCrlf($mail->source));
    }

    public function attachment(Attachment $attachment): ResponseInterface
    {
        return $this->download('' === $attachment->filename ? 'attachment.bin' : $attachment->filename, $attachment->content);
    }

    private function download(string $filename, string $content): ResponseInterface
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename) ?? preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? '_';

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($filename)))
            ->withBody($this->streamFactory->createStream($content));
    }
}
