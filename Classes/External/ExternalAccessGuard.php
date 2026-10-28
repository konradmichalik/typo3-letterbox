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

use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Mail\{DeliveryMode, LetterboxSettings, LetterboxTransport, RecipientPattern};
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\Exception\{ExtensionConfigurationExtensionNotConfiguredException, ExtensionConfigurationPathDoesNotExistException};
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;
use function is_array;
use function is_string;

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
    public const ENV_ALLOWED_IPS = 'LETTERBOX_EXTERNAL_ALLOWED_IPS';
    public const EXCLUSIONS_SETTING = 'externalExcludedRecipients';
    private const ROOT_PRODUCTION = 'Production';

    public function __construct(
        private EnvironmentVariables $environment,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function evaluate(ServerRequestInterface $request): ExternalAccessState
    {
        $state = $this->status();
        $params = $request->getAttribute('normalizedParams');
        if (!$params instanceof NormalizedParams) {
            return $state->withReason(ExternalAccessState::HTTPS_REQUIRED);
        }

        if (!in_array(ExternalAccessState::IP_NOT_ALLOWED, $state->reasons, true) && !$this->ipAllowed($params->getRemoteAddress())) {
            $state = $state->withReason(ExternalAccessState::IP_NOT_ALLOWED);
        }

        return $params->isHttps() ? $state : $state->withReason(ExternalAccessState::HTTPS_REQUIRED);
    }

    /**
     * The request independent checks, for the backend status page.
     */
    public function status(): ExternalAccessState
    {
        $context = (string) Environment::getContext();
        $reasons = [$this->accessReason($context)];

        $mail = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        $mail = is_array($mail) ? $mail : [];
        if (0 !== strcasecmp(ltrim((string) ($mail['transport'] ?? ''), '\\'), LetterboxTransport::class)) {
            $reasons[] = ExternalAccessState::TRANSPORT_NOT_LETTERBOX;
        }

        $scope = [];
        try {
            $settings = LetterboxSettings::fromMailSettings($mail, $context);
            if (self::ROOT_PRODUCTION === $context) {
                $reasons[] = $this->rootProductionReason($settings);
                $scope = $settings->catch;
            }
        } catch (InvalidArgumentException) {
            $reasons[] = ExternalAccessState::MAIL_SETTINGS_INVALID;
        }

        $exclusions = $this->exclusions();
        if (null === $exclusions) {
            $reasons[] = ExternalAccessState::EXCLUSIONS_INVALID;
        }

        // A wildcard reads as "every address" in cmpIP(), so any `*` refuses every request instead of opening the surface.
        if (str_contains($this->environment->get(self::ENV_ALLOWED_IPS), '*')) {
            $reasons[] = ExternalAccessState::IP_NOT_ALLOWED;
        }

        return new ExternalAccessState(MailFilter::all()->withExclusions($exclusions ?? [])->withScope($scope), array_values(array_filter($reasons, static fn (?string $reason): bool => null !== $reason)));
    }

    private function accessReason(string $context): ?string
    {
        $access = $this->environment->get(self::ENV_ACCESS);
        if ('' === $access) {
            return ExternalAccessState::ENV_MISSING;
        }

        return $access === $context ? null : ExternalAccessState::ENV_CONTEXT_MISMATCH;
    }

    /**
     * Live mails must stay out of reach, so root Production shows nothing but the catch list.
     */
    private function rootProductionReason(LetterboxSettings $settings): ?string
    {
        if (DeliveryMode::Listed !== $settings->mode) {
            return ExternalAccessState::PRODUCTION_REQUIRES_LISTED;
        }

        return [] === $settings->catch ? ExternalAccessState::CATCH_LIST_EMPTY : null;
    }

    /**
     * An unset allowlist allows every address. A list with a `*` never gets here, status() refuses it.
     */
    private function ipAllowed(string $remoteAddress): bool
    {
        $allowed = $this->environment->get(self::ENV_ALLOWED_IPS);
        if ('' === $allowed) {
            return true;
        }

        $list = implode(',', array_filter(array_map(trim(...), explode(',', $allowed)), static fn (string $entry): bool => '' !== $entry));

        return '' !== $remoteAddress && '' !== $list && GeneralUtility::cmpIP($remoteAddress, $list);
    }

    /**
     * @return list<RecipientPattern>|null null when the setting holds anything that is not a valid pattern
     */
    private function exclusions(): ?array
    {
        try {
            $value = $this->extensionConfiguration->get('typo3_letterbox', self::EXCLUSIONS_SETTING);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return [];
        }
        if (null === $value) {
            return [];
        }
        if (!is_string($value)) {
            return null;
        }

        $patterns = [];
        foreach (explode(',', $value) as $entry) {
            if ('' === trim($entry)) {
                continue;
            }
            try {
                $patterns[] = RecipientPattern::fromString($entry);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return $patterns;
    }
}
