<?php

    require("../www/config.inc.php");

    echo 'Altering table...'.PHP_EOL;
    $sql = "
        ALTER TABLE `done`
        ADD `cpu_time_ms` int unsigned NOT NULL DEFAULT '0',
        ADD `real_time_ms` int unsigned NOT NULL DEFAULT '0' AFTER `cpu_time_ms`,
        ADD `max_real_time_ms` int unsigned NOT NULL DEFAULT '0' AFTER `real_time_ms`,
        ADD `is_success` tinyint unsigned NOT NULL DEFAULT '0' AFTER `max_real_time_ms`,
        ADD `task_path` varchar(255) COLLATE 'utf8_unicode_ci' NOT NULL DEFAULT '' AFTER `is_success`,
        ADD `task_name` varchar(32) COLLATE 'utf8_unicode_ci' NOT NULL DEFAULT '' AFTER `task_path`,
        ADD `language` varchar(32) COLLATE 'utf8_unicode_ci' NOT NULL DEFAULT '' AFTER `task_name`;
    ";
    $db->query($sql);
    echo 'Done.'.PHP_EOL;


    echo 'Updating rows...'.PHP_EOL;
    $stmt = $db->prepare('
        UPDATE `done`
        SET
            cpu_time_ms = :cpu_time_ms,
            real_time_ms = :real_time_ms,
            max_real_time_ms = :max_real_time_ms,
            is_success = :is_success,
            task_path = :task_path,
            task_name = :task_name,
            language = :language
        WHERE
            id=:id LIMIT 1
    ');
    $query = $db->query('SELECT * FROM `done` ORDER BY id');
    while($row = $query->fetch()) {
        try {
            $jobdata = json_decode($row['jobdata'], true);
            $resultdata = json_decode($row['resultdata'], true);
            if(!isset($resultdata['jobdata'])) continue; //old-format resultdata
            $p = extractTaskStat($jobdata, $resultdata);
            $p[':id'] = $row['id'];
            $stmt->execute($p);
        } catch (Exception $e) {
            echo 'Error: '.$e->getMessage().PHP_EOL;
        }
    }
    echo 'Done.'.PHP_EOL;