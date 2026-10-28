# Sourced by the add-on's utils.sh after the TYPO3 setup, with $BASE_PATH set to the instance.
# configuration:set rejects keys unknown to core, so the Letterbox delivery setting goes into
# additional.php: debugMode enables the letterbox:testmails:send command in every local instance, and caught mails are also delivered to Mailpit through the add-on's SMTP transport.
# Release is allowed to *@example.org, so a released mail shows up in Mailpit.
cat > "$BASE_PATH/config/system/additional.php" <<'PHP'
<?php

$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_delivery'] = 'smtp';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_release'] = ['*@example.org'];
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['debugMode'] = '1';
PHP
