CREATE TABLE port (
	port_id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
	port_number SMALLINT UNSIGNED NOT NULL,
	PRIMARY KEY (port_id)
) ENGINE =InnoDB;

CREATE TABLE server (
	server_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	server_ip INT UNSIGNED    NOT NULL,
	latitude  DECIMAL(10,7) NULL,
	longitude DECIMAL(10,7) NULL,
	created   DATETIME        NOT NULL,
	updated   DATETIME,
	locked    BOOL            NOT NULL DEFAULT 0,
	PRIMARY KEY (server_id),
	UNIQUE KEY unique_server_ip (server_ip),
	INDEX idx_processing (updated,locked)
) ENGINE =InnoDB;

CREATE TABLE server_port (
	fk_server_id BIGINT UNSIGNED   NOT NULL,
	fk_port_id   SMALLINT UNSIGNED NOT NULL,
	FOREIGN KEY fk_server_id (fk_server_id),
	REFERENCES server (server_id)
		ON DELETE CASCADE
		ON UPDATE CASCADE,
	FOREIGN KEY fk_port_id (fk_port_id)
	REFERENCES port (port_id)
		ON DELETE CASCADE
		ON UPDATE CASCADE
) ENGINE =InnoDB;

CREATE TABLE cidr_maintainer (
	maintainer_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	maintainer_description VARCHAR(255) NOT NULL,
	own_infrastructure BOOL NOT NULL DEFAULT 0,
	created DATETIME NOT NULL,
	updated DATETIME,
	PRIMARY KEY (maintainer_id),
	UNIQUE KEY unique_maintainer (maintainer_description)
) ENGINE =InnoDB;

CREATE TABLE cidr (
	cidr_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	cidr_ip INT UNSIGNED NOT NULL,
	cidr_mask INT UNSIGNED NOT NULL,
	fk_maintainer_id INT UNSIGNED NOT NULL,
	created DATETIME        NOT NULL,
	updated DATETIME,
	FOREIGN KEY fk_maintainer_id (fk_maintainer_id)
	REFERENCES cidr_maintainer (maintainer_id)
		ON DELETE CASCADE
		ON UPDATE CASCADE,
	PRIMARY KEY (cidr_id),
	UNIQUE KEY unique_cidr (cidr_ip,cidr_mask)
) ENGINE =InnoDB;

CREATE TABLE host (
	host_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	host_scheme         VARCHAR(10)     NULL,
	host_subdomain      VARCHAR(128)    NULL,
	host_domain         VARCHAR(128)    NOT NULL,
	host_suffix         VARCHAR(28)     NULL,
	host_name           VARCHAR(255)    NULL,
	host_path           VARCHAR(255)    NULL,
	fk_server_id        BIGINT UNSIGNED NOT NULL,
	created             DATETIME        NOT NULL,
	updated             DATETIME,
	typo3_installed     BOOL            NULL,
	typo3_versionstring VARCHAR(64)     NULL,
	FOREIGN KEY fk_server_id (fk_server_id)
	REFERENCES server (server_id)
		ON DELETE CASCADE
		ON UPDATE CASCADE,
	PRIMARY KEY (host_id),
	INDEX idx_server_identifier (fk_server_id)
	INDEX idx_statistic (typo3_installed),
	INDEX idx_processing (created,host_scheme,host_subdomain,host_domain)
) ENGINE =InnoDB;

CREATE TABLE twitter_user (
	user_id    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
	user_name  VARCHAR(255)    NOT NULL,
	twitter_id BIGINT UNSIGNED NULL,
	subscribed  BOOL            NOT NULL DEFAULT 0,
	PRIMARY KEY (user_id),
	UNIQUE KEY unique_user_id (user_name),
	UNIQUE KEY unique_user_name (twitter_id)
) ENGINE =InnoDB;

CREATE TABLE twitter_tweet (
	tweet_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	tweet_text      VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
	twitter_id      BIGINT UNSIGNED NOT NULL,
	tweet_processed BOOL            NOT NULL,
	created         DATETIME        NOT NULL,
	fk_user_id      INT UNSIGNED    NOT NULL,
	FOREIGN KEY fk_user_id (fk_user_id)
	REFERENCES twitter_user (user_id)
		ON DELETE CASCADE
		ON UPDATE CASCADE,
	PRIMARY KEY (tweet_id),
	UNIQUE KEY unique_tweet_id (fk_user_id, twitter_id)
) ENGINE =InnoDB;

CREATE TABLE twitter_url (
	url_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	url_text    VARCHAR(510)    NOT NULL,
	fk_tweet_id BIGINT UNSIGNED NOT NULL,
	FOREIGN KEY fk_tweet_id (fk_tweet_id)
	REFERENCES twitter_tweet (tweet_id)
		ON DELETE CASCADE
		ON UPDATE CASCADE,
	PRIMARY KEY (url_id)
) ENGINE =InnoDB;

CREATE TABLE reg_domain (
	domain_id           MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
	domain_name         VARCHAR(128) NOT NULL,
	domain_suffix       VARCHAR(28) NOT NULL,
	location            VARCHAR(900) NULL,
	latitude            DECIMAL(10,7) NULL,
	longitude           DECIMAL(10,7) NULL,
	skipped             BOOL NOT NULL DEFAULT 0,
	processed          BOOL NOT NULL DEFAULT 0,
	extractedText       TEXT NULL,
	PRIMARY KEY (domain_id),
	UNIQUE KEY unique_domain (domain_name)
) ENGINE =InnoDB;

CREATE TABLE aggregated_host (
	host_id             MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
	host_name           VARCHAR(128) NULL,
	host_path           VARCHAR(255) NULL,
	typo3_versionstring VARCHAR(16) NULL,
	sfk_domain_id       MEDIUMINT UNSIGNED NULL,
	PRIMARY KEY (host_id),
	UNIQUE KEY unique_host (host_name, host_path)
) ENGINE =InnoDB;

CREATE FUNCTION mask_to_cidr(mask CHAR(15))
	RETURNS INT(2) DETERMINISTIC RETURN BIT_COUNT(INET_ATON(mask));
CREATE FUNCTION cidr_to_mask(cidr INT(2))
	RETURNS CHAR(15) DETERMINISTIC RETURN INET_NTOA(CONV(CONCAT(REPEAT(1, cidr), REPEAT(0, 32 - cidr)), 2, 10));