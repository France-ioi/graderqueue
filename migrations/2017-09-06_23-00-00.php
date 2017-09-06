<?php

    require("../www/config.inc.php");

    echo 'Altering tables...'.PHP_EOL;
    $sql = "
        ALTER TABLE `done`
            ADD `received_time_php` bigint unsigned NOT NULL DEFAULT '0' AFTER `received_time`,
            ADD `grading_start_time_php` bigint unsigned NOT NULL DEFAULT '0' AFTER `grading_start_time`,
            ADD `grading_end_time_php` bigint unsigned NOT NULL DEFAULT '0' AFTER `grading_end_time`
    ";
    $db->query($sql);


    $sql = "
        ALTER TABLE `queue`
            ADD `received_time_php` bigint unsigned NOT NULL DEFAULT '0' AFTER `received_time`,
            ADD `grading_start_time_php` bigint unsigned NOT NULL DEFAULT '0' AFTER `grading_start_time`;
    ";
    $db->query($sql);

    echo 'Done.'.PHP_EOL;