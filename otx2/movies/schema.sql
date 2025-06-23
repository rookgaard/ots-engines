CREATE TABLE IF NOT EXISTS `cams` (
	`account_id` int(11) NOT NULL,
	`player_id` int(11) NOT NULL,
	`player_name` varchar(64) NOT NULL,
	`duration` int(11) NOT NULL DEFAULT 0,
	`hash` varchar(32) NOT NULL,
	`directory` varchar(16) NOT NULL DEFAULT '',
	`filename` varchar(64) NOT NULL,
	`visible` tinyint(1) NOT NULL DEFAULT 0,
	`access` tinyint(1) NOT NULL DEFAULT 0,
	`started` datetime DEFAULT NULL,
	`ended` datetime DEFAULT NULL,
	`parsed` datetime DEFAULT NULL,
	UNIQUE KEY `hash` (`hash`),
	KEY `account_id` (`account_id`),
	KEY `player_id` (`player_id`),
	CONSTRAINT `cams_accounts` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
