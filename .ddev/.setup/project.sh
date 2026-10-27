# Repo-owned customizations for `ddev install`, sourced by the add-on's utils.sh.
# Overrides the add-on's Mailpit transport: Letterbox catches the mail in the
# mbox file and still delivers it to Mailpit in mode "smtp".
TYPO3_SETTINGS=(
    "MAIL/transport 'KonradMichalik\\Typo3Letterbox\\Mail\\LetterboxTransport'"
    "MAIL/transport_mbox_file /var/www/html/.Build/mails.mbox"
    "MAIL/transport_letterbox_delivery smtp"
)
