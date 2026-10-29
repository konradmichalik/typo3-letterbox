# Repo-owned customizations for `ddev install`, sourced by the add-on's utils.sh.
# Overrides the add-on's Mailpit transport: Letterbox catches the mail in the
# mbox file.
TYPO3_SETTINGS=(
    "MAIL/transport 'KonradMichalik\\Typo3Letterbox\\Mail\\LetterboxTransport'"
    "MAIL/transport_mbox_file /var/www/html/.Build/mails.mbox"
    "MAIL/defaultMailFromAddress no-reply@\${DDEV_SITENAME}.ddev.site"
    'MAIL/defaultMailFromName "Letterbox"'
)
