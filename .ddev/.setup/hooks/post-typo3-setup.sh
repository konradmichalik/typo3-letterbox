# Sourced by the add-on's utils.sh after the TYPO3 setup, with $BASE_PATH set to the instance.
# debugMode enables the letterbox:testmails:send command and externalAccess the tester inbox and API (token and context come from .ddev/config.yaml) in every local instance.
cat > "$BASE_PATH/config/system/additional.php" <<'PHP'
<?php

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['debugMode'] = '1';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['externalAccess'] = '1';
PHP
