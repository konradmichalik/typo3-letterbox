# Repo-owned customizations for `ddev install`, sourced by the add-on's utils.sh.
# Overrides the add-on's Mailpit transport: Letterbox catches the mail in the
# mbox file. MAIL/transport_letterbox_delivery is written by hooks/post-typo3-setup.sh
# because configuration:set rejects paths unknown to the core default configuration.
TYPO3_SETTINGS=(
    "MAIL/transport 'KonradMichalik\\Typo3Letterbox\\Mail\\LetterboxTransport'"
    "MAIL/transport_mbox_file /var/www/html/.Build/mails.mbox"
)
