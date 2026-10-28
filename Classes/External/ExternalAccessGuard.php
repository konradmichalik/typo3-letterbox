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

namespace KonradMichalik\Typo3Letterbox\External;

use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;

use function is_array;
use function strlen;

/**
 * ExternalAccessGuard.
 *
 * Decides whether the surfaces outside the backend may answer at all. Every doubt is a reason, every reason deactivates.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ExternalAccessGuard
{
    public const ENV_ACCESS = 'LETTERBOX_EXTERNAL_ACCESS';
    public const ENV_TOKEN = 'LETTERBOX_ACCESS_TOKEN';
    public const MIN_TOKEN_LENGTH = 32;
    private const ROOT_PRODUCTION = 'Production';

    public function __construct(
        private EnvironmentVariables $environment,
    ) {}

    public function evaluate(ServerRequestInterface $request): ExternalAccessState
    {
        $state = $this->status();
        $params = $request->getAttribute('normalizedParams');

        return $params instanceof NormalizedParams && $params->isHttps() ? $state : $state->withReason(ExternalAccessState::HTTPS_REQUIRED);
    }

    /**
     * The request independent checks, for the backend status page.
     */
    public function status(): ExternalAccessState
    {
        $context = (string) Environment::getContext();
        $access = $this->environment->get(self::ENV_ACCESS);
        $mail = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];

        $reasons = [
            '' === $access ? ExternalAccessState::ENV_MISSING : null,
            '' !== $access && $access !== $context ? ExternalAccessState::ENV_CONTEXT_MISMATCH : null,
            // Live mails must stay out of reach, whatever the environment says.
            self::ROOT_PRODUCTION === $context ? ExternalAccessState::PRODUCTION : null,
            LetterboxTransport::isSelectedIn(is_array($mail) ? $mail : []) ? null : ExternalAccessState::TRANSPORT_NOT_LETTERBOX,
            '' === $this->token() ? ExternalAccessState::TOKEN_MISSING : null,
        ];

        return new ExternalAccessState(array_values(array_filter($reasons, static fn (?string $reason): bool => null !== $reason)));
    }

    /**
     * The shared secret of the API and the tester inbox, empty when unset or shorter than the minimum.
     */
    public function token(): string
    {
        $token = $this->environment->get(self::ENV_TOKEN);

        return strlen($token) >= self::MIN_TOKEN_LENGTH ? $token : '';
    }

    /**
     * The secret of the tester link. It is derived from the token, so a leaked link opens the read-only inbox but never the API.
     */
    public function testerLinkSecret(): string
    {
        $token = $this->token();

        return '' === $token ? '' : hash_hmac('sha256', 'tester-link', $token);
    }
}
