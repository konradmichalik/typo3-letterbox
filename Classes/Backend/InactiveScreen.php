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

namespace KonradMichalik\Typo3Letterbox\Backend;

use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use KonradMichalik\Typo3Letterbox\External\EnvironmentVariables;
use KonradMichalik\Typo3Letterbox\Mail\{LetterboxTransport, TransportSummary, TransportTakeover};
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * InactiveScreen.
 *
 * What the module shows while Letterbox is not the mail transport. The transport is passed as a summary only, so
 * no part of MAIL/dsn but scheme, host and port reaches the template.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class InactiveScreen
{
    public const SNIPPET = <<<'PHP'
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = \KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport::class;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = '/var/www/mbox/mails.mbox';
        PHP;

    // The core Settings module, "tools_toolssettings" in v13 and an alias of "system_settings" in v14.
    private const SETTINGS_ROUTE = 'tools_toolssettings';

    public function __construct(
        private ExtensionSettings $extensionSettings,
        private EnvironmentVariables $environment,
        private UriBuilder $uriBuilder,
        private Typo3Version $typo3Version,
    ) {}

    /**
     * Whether Letterbox is the transport, the variables of the inactive screen, and whether "Clear all" is offered:
     * an earlier mailbox stays readable while Letterbox is not the transport, but is never cleared from the module.
     *
     * @param array<string, mixed> $mail the MAIL section of TYPO3_CONF_VARS
     *
     * @return array{active: bool, inactive: array<string, mixed>, clearable: bool}
     */
    public function moduleState(array $mail, int $total): array
    {
        $active = LetterboxTransport::isSelectedIn($mail);

        return [
            'active' => $active,
            'inactive' => $active ? [] : $this->variables($mail),
            'clearable' => $active && $total > 0,
        ];
    }

    /**
     * @param array<string, mixed> $mail the MAIL section of TYPO3_CONF_VARS
     *
     * @return array{transport: string, setting: bool, environment: string, settingsUrl: string, settingsGroup: string, snippet: string}
     */
    private function variables(array $mail): array
    {
        return [
            'transport' => TransportSummary::of($mail),
            'setting' => TransportTakeover::BY_SETTING === TransportTakeover::source('', $this->extensionSettings->raw('enable')),
            'environment' => $this->environment->get(TransportTakeover::ENVIRONMENT_VARIABLE),
            'settingsUrl' => $this->settingsUrl(),
            'settingsGroup' => $this->typo3Version->getMajorVersion() >= 14 ? 'system' : 'tools',
            'snippet' => self::SNIPPET,
        ];
    }

    /**
     * Empty without EXT:install, the template then names the path only.
     */
    private function settingsUrl(): string
    {
        try {
            return (string) $this->uriBuilder->buildUriFromRoute(self::SETTINGS_ROUTE);
        } catch (RouteNotFoundException) {
            return '';
        }
    }
}
