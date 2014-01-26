-- Show unprocessed tweets
SELECT COUNT(t.tweet_id)
FROM twitter_tweet t JOIN twitter_url u ON (t.tweet_id = u.fk_tweet_id)
WHERE NOT t.tweet_processed;

-- Show unprocessed "TYPO3 servers"
SELECT s.server_id,INET_NTOA(s.server_ip) AS server_ip,count(h.host_id) AS typo3hosts
FROM server s RIGHT JOIN host h ON (s.server_id = h.fk_server_id)
WHERE s.updated IS NULL AND h.typo3_installed=1
GROUP BY s.server_id
HAVING typo3hosts >= 1
ORDER BY typo3hosts DESC

-- Show stored CIDRs
SELECT cidr_id,INET_NTOA(cidr_ip),mask_to_cidr(INET_NTOA(cidr_mask)) AS cidr,created,cidr_description FROM cidr;

-- Show all discovered mapped IPs to CIDRs
SELECT INET_NTOA(s.server_ip) ip, INET_NTOA(c.cidr_mask & s.server_ip) network, INET_NTOA(c.cidr_mask) mask, c.cidr_description
FROM server s INNER JOIN cidr c ON (c.cidr_mask & s.server_ip) = c.cidr_ip;

-- Show top CIDR maintainers with TYPO3 installations
SELECT COUNT(h.host_id) AS num_hosts,c.cidr_description
FROM server s INNER JOIN cidr c ON ((c.cidr_mask & s.server_ip) = c.cidr_ip) LEFT JOIN host h ON (s.server_id = h.fk_server_id)
WHERE h.typo3_installed=1
GROUP BY c.cidr_description;
-- OR
SELECT COUNT(h.host_id) AS num_hosts,c.cidr_description
FROM server s RIGHT JOIN host h ON (s.server_id = h.fk_server_id) INNER JOIN cidr c ON ((c.cidr_mask & s.server_ip) = c.cidr_ip)
WHERE h.typo3_installed=1
GROUP BY c.cidr_description;

-- Show IP addresses with high number of TYPO3 installations which are not yet mapped to CIDRs
SELECT s.server_id,INET_NTOA(server_ip),COUNT(h.host_id) AS num_hosts
FROM server s LEFT JOIN host h ON (s.server_id = h.fk_server_id) LEFT JOIN cidr c ON ((c.cidr_mask & s.server_ip) = c.cidr_ip)
WHERE h.typo3_installed=1 AND c.cidr_id IS NULL
GROUP BY s.server_id
HAVING num_hosts > 100
ORDER BY num_hosts DESC;


-- Process CIDR one-by-one starting from smallest subnet (hosts)
SELECT
	cidr_id,
	INET_NTOA(cidr_ip),
	mask_to_cidr(INET_NTOA(cidr_mask)) AS cidr,
	created,
	cidr_description
FROM cidr
WHERE updated IS NULL
ORDER BY cidr DESC
LIMIT 1;


-- Export TYPO3 servers with geocoding
SELECT INET_NTOA(s.server_ip) AS server_ip, s.latitude, s.longitude, COUNT(h.host_id) AS numhosts
FROM server s RIGHT JOIN host h ON (s.server_id = h.fk_server_id)
WHERE s.latitude IS NOT NULL AND s.longitude IS NOT NULL AND h.typo3_installed=1
GROUP BY s.server_id
HAVING numhosts >= 1
ORDER BY numhosts DESC;


-- Aggregate distinct host names
INSERT INTO aggregated_host(host_name,host_path,host_domain,host_suffix,typo3_versionstring)
SELECT DISTINCT CONCAT(host_scheme, '://', IF(host_subdomain IS NULL, '', CONCAT(host_subdomain, '.')), host_domain) AS host_name,host_path,host_domain,host_suffix,typo3_versionstring
FROM host
WHERE typo3_installed AND typo3_versionstring IS NOT NULL AND host_suffix IS NOT NULL;

-- Export .de hosts
SELECT
CONCAT(IF(host_subdomain IS NULL, '', CONCAT(host_subdomain, '.')), host_domain) AS fqdn,
CONCAT(host_scheme, '://', IF(host_subdomain IS NULL, '', CONCAT(host_subdomain, '.')), host_domain, IF(host_path IS NULL, '', CONCAT('/', host_path))) AS url,
typo3_versionstring
FROM host
WHERE typo3_installed AND typo3_versionstring IS NOT NULL AND host_suffix LIKE 'de' ORDER BY fqdn;