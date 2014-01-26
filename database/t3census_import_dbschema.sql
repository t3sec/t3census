CREATE TABLE host (
	id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	url            VARCHAR(255)    NULL,
	processed      BOOL NOT NULL DEFAULT 0,
	PRIMARY KEY (id),
	UNIQUE KEY unique_host_fqdn (fqdn)
) ENGINE =InnoDB;

CREATE TABLE server (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	ip INT UNSIGNED    NOT NULL,
	processed          BOOL NOT NULL DEFAULT 0,
	PRIMARY KEY (id),
	UNIQUE KEY unique_server_ip (ip)
) ENGINE =InnoDB;