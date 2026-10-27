# Sourced by the add-on's utils.sh after the TYPO3 setup, with $BASE_PATH set to the instance.
# configuration:set rejects keys unknown to core, so the Letterbox delivery setting goes into
# additional.php: caught mails are also delivered to Mailpit through the add-on's SMTP transport.
cat > "$BASE_PATH/config/system/additional.php" <<'PHP'
<?php

$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_delivery'] = 'smtp';
PHP
