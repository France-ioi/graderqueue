CREATE TABLE IF NOT EXISTS `done` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT '0',
  `timeout_sec` int(11) NOT NULL DEFAULT '300',
  `nb_fails` int(11) NOT NULL DEFAULT '0',
  `received_from` int(11) NOT NULL DEFAULT '-1',
  `received_time` datetime NOT NULL,
  `sent_to` int(11) NOT NULL DEFAULT '-1',
  `sent_time` datetime NOT NULL,
  `tags` text NOT NULL,
  `taskdata` longtext NOT NULL,
  `done_time` datetime NOT NULL,
  `resultdata` longtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL,
  `log_type` varchar(255) NOT NULL,
  `task_id` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `platforms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `ssl_serial` varchar(255) NOT NULL,
  `ssl_dn` varchar(255) NOT NULL,
  `restrict_paths` text NOT NULL,
  `force_tag` int(11) NOT NULL DEFAULT '-1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `status` enum('queued','sent','error') NOT NULL DEFAULT 'queued',
  `priority` int(11) NOT NULL DEFAULT '0',
  `timeout_sec` int(11) NOT NULL DEFAULT '300',
  `nb_fails` int(11) NOT NULL DEFAULT '0',
  `received_from` int(11) NOT NULL DEFAULT '-1',
  `received_time` datetime NOT NULL,
  `sent_to` int(11) NOT NULL DEFAULT '-1',
  `sent_time` datetime DEFAULT NULL,
  `timeout_time` datetime DEFAULT NULL,
  `tags` text NOT NULL,
  `taskdata` longtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `server_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `ssl_serial` varchar(255) NOT NULL,
  `ssl_dn` varchar(255) NOT NULL,
  `wakeup_url` text NOT NULL,
  `type` int(11) NOT NULL,
  `max_concurrent_tasks` int(11) NOT NULL DEFAULT '1',
  `last_poll_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  FOREIGN KEY (`type`) REFERENCES `server_types` (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `task_types` (
  `taskid` int(11) NOT NULL,
  `typeid` int(11) NOT NULL,
  PRIMARY KEY (`taskid`, `typeid`),
  KEY `taskid` (`taskid`),
  KEY `typeid` (`typeid`),
  FOREIGN KEY (`typeid`) REFERENCES `server_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tokens` (
  `token` varchar(32) NOT NULL,
  `expiration_time` datetime NOT NULL,
  PRIMARY KEY (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `type_tags` (
  `typeid` int(11) NOT NULL,
  `tagid` int(11) NOT NULL,
  PRIMARY KEY (`typeid`, `tagid`),
  KEY `typeid` (`typeid`),
  KEY `tagid` (`tagid`),
  FOREIGN KEY (`typeid`) REFERENCES `server_types` (`id`),
  FOREIGN KEY (`tagid`) REFERENCES `tags` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
