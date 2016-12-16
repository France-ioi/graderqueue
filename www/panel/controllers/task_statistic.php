<?php

    // interval
    $interval_idx = intval($_GET['interval']);
    if(!$interval_idx) $interval_idx = 0;
    $interval = $CFG_stat_intervals[$interval_idx];
    $interval_end = time();
    $interval_begin = $interval_end - $interval['duration'];


    // group by
    $group_by_options = [
        array(
            'field' => 'task_name',
            'caption' => 'Task name'
        ),
        array(
            'field' => 'language',
            'caption' => 'Language'
        )
    ];

    $group_by_idx = intval($_GET['group_by']);
    if(!$group_by_idx) $group_by_idx = 0;
    $group_by = $group_by_options[$group_by_idx];



    $where = ' WHERE done_time BETWEEN FROM_UNIXTIME('.$interval_begin.') AND FROM_UNIXTIME('.$interval_end.')';

    // common stat
    $result = $db->query('
        SELECT
            '.$group_by['field'].',
            COUNT(*) as total,
            SUM(is_success) as total_success,
            SUM(cpu_time_ms) as sum_cpu_time_ms,
            SUM(real_time_ms) as sum_real_time_ms,
            AVG(TIMESTAMPDIFF(SECOND, received_time, sent_time)) as avg_waiting_time,
            AVG(TIMESTAMPDIFF(SECOND, received_time, done_time)) as avg_time_per_task,
            MAX(IF(is_success=1,max_real_time_ms,0)) as max_cpu_time
        FROM done '.$where.' GROUP BY '.$group_by['field'].' ORDER BY '.$group_by['field']
    )->fetchAll();


    include('views/task_statistic/content.php');