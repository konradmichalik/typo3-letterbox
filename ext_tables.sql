CREATE TABLE tx_typo3letterbox_tester_link (
	uid int(11) unsigned NOT NULL auto_increment,
	token_hash char(64) DEFAULT '' NOT NULL,
	label varchar(255) DEFAULT '' NOT NULL,
	scope text,
	unrestricted int(1) unsigned DEFAULT 0 NOT NULL,
	permissions int(11) unsigned DEFAULT 0 NOT NULL,
	expires int(11) unsigned DEFAULT 0 NOT NULL,
	created int(11) unsigned DEFAULT 0 NOT NULL,
	created_by int(11) unsigned DEFAULT 0 NOT NULL,
	last_used int(11) unsigned DEFAULT 0 NOT NULL,
	revoked int(11) unsigned DEFAULT 0 NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY token_hash (token_hash)
);
