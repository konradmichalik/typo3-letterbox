# Migration from EXT:mbox

Back to [documentation overview](README.md).

1. Swap the packages (TER: extension key `typo3_letterbox`):

   ```bash
   composer remove t3/mbox && composer require konradmichalik/typo3-letterbox
   ```
2. Replace the transport class:

   ```php
   // before
   $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = \T3\Mbox\MboxTransport::class;
   // after
   $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = \KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport::class;
   ```

3. Keep `MAIL/transport_mbox_file`. Existing mbox files stay readable.
4. Open the module at System › Letterbox. It replaces the former Tools module "mbox Mail Client".
5. Flush the caches.

## Behaviour differences

* EXT:mbox caught every mail. Letterbox does the same outside the `Production` context, but defaults to mode `listed` in `Production`, where an empty catch list delivers everything. Set `transport_letterbox_mode` explicitly if you rely on catching there.
* Letterbox needs TYPO3 13.4 or 14 and PHP 8.2 or newer. TYPO3 12 stays on EXT:mbox.
* The extension settings `debugMode`, `enableBackendModule` and `mailsPerPage` keep their meaning.
