CREATE TABLE IF NOT EXISTS `done` (
    -- Tasks done
  `id` int(11) NOT NULL AUTO_INCREMENT,
    -- ID for the done table
  `jobid` int(11) NOT NULL,
    -- ID of the job (from the queue table)
  `name` varchar(255) NOT NULL,
    -- Name identifying the job internally
  `job_repeats` int(11) NOT NULL DEFAULT '0',
    -- Number of times a job with the same usertaskid was sent
  `priority` int(11) NOT NULL DEFAULT '0',
    -- Higher number = higher priority
  `timeout_sec` int(11) NOT NULL DEFAULT '300',
    -- Time waiting for a server to send the results back
  `nb_fails` int(11) NOT NULL DEFAULT '0',
    -- Total number of servers which tried to execute the job and never answered
  `received_from` int(11) NOT NULL DEFAULT '-1',
    -- ID of the platform which sent the job
  `received_time` datetime NOT NULL,
    -- Date/time of the job
  `sent_to` int(11) NOT NULL DEFAULT '-1',
    -- ID of the (last) server which executed the job
  `sent_time` datetime NOT NULL,
    -- Date/time the job was sent to the server
  `tags` text NOT NULL,
    -- Tags of the job
  `jobdata` longtext NOT NULL,
    -- JSON data for the job
  `done_time` datetime NOT NULL,
    -- Date/time the results were sent back
  `resultdata` longtext NOT NULL,
    -- JSON data for the results
  `returnUrl` varchar(255) NOT NULL,
    -- return Url given by the platform
  `returnState` enum('notSent','sent','error') NOT NULL DEFAULT 'notSent',
    -- if return Url worked as expected
  PRIMARY KEY (`id`),
  KEY `jobid` (`jobid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `log` (
    -- Logs of some actions
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL,
  `log_type` varchar(255) NOT NULL,
  `job_id` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `message` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `platforms` (
    -- Platforms which can send jobs to the queue
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
    -- also used as key name in tokens
  `public_key` varchar(1000) NOT NULL,
    -- private key to decode signed tokens
  `restrict_paths` text NOT NULL,
    -- Paths to restrict execution of the jobs to, when they're sent by
    -- this platform
  `force_tag` int(11) NOT NULL DEFAULT '-1',
    -- Add a tag to all jobs sent by this platform
  `return_url` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
    -- Name identifying the job internally
  `jobusertaskid` varchar(255) NOT NULL,
    -- Name uniquely identifying a user-task combination
  `job_repeats` int(11) NOT NULL DEFAULT '0',
    -- Number of times a job with the same usertaskid was sent
  `status` enum('queued','sent','error') NOT NULL DEFAULT 'queued',
    -- Status of the job
  `priority` int(11) NOT NULL DEFAULT '0',
    -- Higher number = higher priority
  `timeout_sec` int(11) NOT NULL DEFAULT '300',
    -- Time waiting for a server to send the results back
  `nb_fails` int(11) NOT NULL DEFAULT '0',
    -- Total number of servers which tried to execute the job and never answered
  `received_from` int(11) NOT NULL DEFAULT '-1',
    -- ID of the platform which sent the job
  `received_time` datetime NOT NULL,
    -- Date/time of the job
  `sent_to` int(11) NOT NULL DEFAULT '-1',
    -- ID of the (last) server which executed the job
  `sent_time` datetime NOT NULL,
    -- Date/time the job was sent to the server
  `timeout_time` datetime DEFAULT NULL,
  `tags` text NOT NULL,
    -- Tags of the job
  `taskrevision` VARCHAR(40) NOT NULL DEFAULT '',
    -- Revision / commit hash the task folder needs to be updated to
  `jobdata` longtext NOT NULL,
    -- JSON data for the job
  PRIMARY KEY (`id`),
  UNIQUE KEY `jobusertaskid` (`jobusertaskid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `remote_users` (
    -- Remote users for taskgrader remotetest
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password` varchar(32) NOT NULL,
    -- Hashed password, hash('md5', 'username@password')
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `server_types` (
    -- Each server type corresponds to a set of tags that can be handled.
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `servers` (
    -- Servers executing the jobs
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
    -- Internal name for the server
  `ssl_serial` varchar(255) NOT NULL,
  `ssl_dn` varchar(255) NOT NULL,
    -- Client SSL certificate information: certificate serial + issuer DN
  `wakeup_url` text NOT NULL,
    -- URL to access to wakeup the server
  `wakeup_fails` int(11) NOT NULL DEFAULT '0',
    -- Number of wakeup failures since last poll
  `type` int(11) NOT NULL,
    -- Type of the server (check `server_types` table)
  `max_concurrent_jobs` int(11) NOT NULL DEFAULT '1',
    -- Maximum concurrent jobs the server can be sent
  `last_poll_time` datetime NOT NULL,
    -- Date/time of the last poll by the server
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  FOREIGN KEY (`type`) REFERENCES `server_types` (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tags` (
    -- Tags represent constraints of the job / capabilities of the server.
    -- Tasks will only be executed by servers that can handle all their tags.
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `job_types` (
    -- Types of servers which can execute a given job.
  `jobid` int(11) NOT NULL,
  `typeid` int(11) NOT NULL,
  PRIMARY KEY (`jobid`, `typeid`),
  KEY `jobid` (`jobid`),
  KEY `typeid` (`typeid`),
  FOREIGN KEY (`typeid`) REFERENCES `server_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tokens` (
    -- Temporary tokens for interface clients to identify against the API.
  `token` varchar(32) NOT NULL,
  `expiration_time` datetime NOT NULL,
  PRIMARY KEY (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `type_tags` (
    -- Tags corresponding to each type: tags that can be handled by servers
    -- of that type.
  `typeid` int(11) NOT NULL,
  `tagid` int(11) NOT NULL,
  PRIMARY KEY (`typeid`, `tagid`),
  KEY `typeid` (`typeid`),
  KEY `tagid` (`tagid`),
  FOREIGN KEY (`typeid`) REFERENCES `server_types` (`id`),
  FOREIGN KEY (`tagid`) REFERENCES `tags` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
