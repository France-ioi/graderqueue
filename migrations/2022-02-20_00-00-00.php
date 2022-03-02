<?php

    require("../www/config.inc.php");

    echo 'Altering tables...'.PHP_EOL;
    $sql = "
CREATE TABLE IF NOT EXISTS `server_tokens` (
`id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(16) NOT NULL,
  `type` int(11) NOT NULL,
  `max_concurrent_jobs` int(11) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE `server_tokens`
 ADD PRIMARY KEY (`id`);

ALTER TABLE `server_tokens`
MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `servers` ADD `token_id` INT NOT NULL AFTER `name`, ADD `ip` VARCHAR(255) NOT NULL AFTER `token_id`;
    ";
    $db->query($sql);

    echo 'Done.'.PHP_EOL;
