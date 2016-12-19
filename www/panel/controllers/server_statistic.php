<?php

    $queue_size = $db->query('SELECT COUNT(*) FROM queue')->fetch()[0];

    $interval_idx = isset($_GET['interval']) ? intval($_GET['interval']) : 0;
    if(!$interval_idx) $interval_idx = 0;
    $interval = $CFG_stat_intervals[$interval_idx];

    $interval_end = time();
    $interval_begin = $interval_end - $interval['duration'];


    // interval overview
    $overview = array();
    $where = ' WHERE grading_end_time <= FROM_UNIXTIME('.$interval_end.') AND grading_end_time >= FROM_UNIXTIME('.$interval_begin.')';
    $row = $db->query('
        SELECT
            COUNT(*) as tasks_processed,
            SUM(nb_fails) as errors_count,
            AVG(TIMESTAMPDIFF(SECOND, received_time, grading_end_time)) as avg_time_per_task,
            AVG(TIMESTAMPDIFF(SECOND, grading_start_time, grading_end_time)) as avg_time_per_send_back
        FROM done '.$where
    )->fetch();
    $overview['tasks_processed'] = $row['tasks_processed'];
    $overview['errors_count'] = $row['errors_count'];
    $overview['avg_time_per_task'] = $row['avg_time_per_task'];
    $overview['avg_time_per_send_back'] = $row['avg_time_per_send_back'];

    $where = ' WHERE received_time <= FROM_UNIXTIME('.$interval_end.') AND grading_start_time >= FROM_UNIXTIME('.$interval_begin.')';
    $row = $db->query('
        SELECT
            SUM(
                LEAST('.$interval_end.', UNIX_TIMESTAMP(grading_end_time)) -
                GREATEST('.$interval_begin.', UNIX_TIMESTAMP(grading_start_time))
            ) as sum_server_time,
            SUM(
                LEAST('.$interval_end.', UNIX_TIMESTAMP(grading_start_time)) -
                GREATEST('.$interval_begin.', UNIX_TIMESTAMP(received_time))
            ) as sum_queue_time
        FROM done '.$where
    )->fetch();
    $overview['avg_queue_size'] = $row['sum_queue_time']  / $interval['duration'];
    $overview['avg_server_time'] = $row['sum_server_time'] / $interval['duration'];


    // charts
    $chart_data = array(
        'avg_waiting_time' => array(),
        'sum_cpu_time_ms' => array(),
        'sum_real_time_ms' => array(),
        'avg_queue_size' => array(),
        'avg_server_time' => array(),
        'labels' => array()
    );
    $interval_tick_begin = $interval_begin;

    while($interval_tick_begin < $interval_end) {
        $interval_tick_end = $interval_tick_begin + $interval['tick'];
        $chart_data['labels'][] = date("Y-m-d H:i:s", $interval_tick_begin);

        $where = ' WHERE grading_end_time <= FROM_UNIXTIME('.$interval_tick_end.') AND grading_end_time >= FROM_UNIXTIME('.$interval_tick_begin.')';
        $row = $db->query('
            SELECT
                SUM(cpu_time_ms) as sum_cpu_time_ms,
                SUM(real_time_ms) as sum_real_time_ms,
                AVG(TIMESTAMPDIFF(SECOND, received_time, grading_start_time)) as avg_waiting_time
            FROM done '.$where
        )->fetch();
        $chart_data['avg_waiting_time'][] = (float) $row['avg_waiting_time'];
        $chart_data['sum_cpu_time_ms'][] = (float) $row['sum_cpu_time_ms'];
        $chart_data['sum_real_time_ms'][] = (float) $row['sum_real_time_ms'];

        $where = ' WHERE received_time <= FROM_UNIXTIME('.$interval_tick_end.') AND grading_end_time >= FROM_UNIXTIME('.$interval_tick_begin.')';
        $row = $db->query('
            SELECT
                SUM(
                    GREATEST(0,
                        LEAST('.$interval_tick_end.', UNIX_TIMESTAMP(grading_end_time)) -
                        GREATEST('.$interval_tick_begin.', UNIX_TIMESTAMP(grading_start_time)))
                ) as sum_server_time,
                SUM(
                    GREATEST(0,
                        LEAST('.$interval_tick_end.', UNIX_TIMESTAMP(grading_start_time)) -
                        GREATEST('.$interval_tick_begin.', UNIX_TIMESTAMP(received_time)))
                ) as sum_queue_time
            FROM done '.$where
        )->fetch();
        $chart_data['avg_queue_size'][] = (float) $row['sum_queue_time'] / $interval['tick'];
        $chart_data['avg_server_time'][] = (float) $row['sum_server_time'] / $interval['tick'];

        $interval_tick_begin = $interval_tick_end;
    }


    include('views/server_statistic/content.php');
