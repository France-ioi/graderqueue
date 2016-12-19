<?php

    require("../www/config.inc.php");

    echo 'Altering tables...'.PHP_EOL;
    $sql = "
        ALTER TABLE `done`
            CHANGE `sent_time` `grading_start_time` datetime NOT NULL AFTER `sent_to`,
            CHANGE `done_time` `grading_end_time` datetime NOT NULL AFTER `jobdata`
    ";
    $db->query($sql);


    $sql = "
        ALTER TABLE `queue`
        CHANGE `sent_time` `grading_start_time` datetime NULL AFTER `sent_to`
    ";
    $db->query($sql);

    echo 'Done.'.PHP_EOL;