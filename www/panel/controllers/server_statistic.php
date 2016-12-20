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

    $db->query('CREATE TEMPORARY TABLE chart_intervals (interval_tick_begin INT, interval_tick_end INT) ENGINE=MEMORY;');

    $intervals = array();

    while($interval_tick_begin < $interval_end) {
        $interval_tick_end = $interval_tick_begin + $interval['tick'];
        $chart_data['labels'][] = date("Y-m-d H:i:s", $interval_tick_begin);
        $intervals[] = '('.$interval_tick_begin.','.$interval_tick_end.')';
        $interval_tick_begin = $interval_tick_end;
    }

    $db->query('INSERT INTO chart_intervals VALUES '.implode(',', $intervals));

    $stmt = $db->query('
        SELECT
            SUM(done.cpu_time_ms) as sum_cpu_time_ms,
            SUM(done.real_time_ms) as sum_real_time_ms,
            AVG(TIMESTAMPDIFF(SECOND, done.received_time, done.grading_start_time)) as avg_waiting_time
        FROM chart_intervals
        LEFT JOIN done
        ON grading_end_time <= FROM_UNIXTIME(interval_tick_end) AND grading_end_time >= FROM_UNIXTIME(interval_tick_begin)
        GROUP BY interval_tick_begin');
    while($row = $stmt->fetch()) {
        $chart_data['avg_waiting_time'][] = (float) $row['avg_waiting_time'];
        $chart_data['sum_cpu_time_ms'][] = (float) $row['sum_cpu_time_ms'];
        $chart_data['sum_real_time_ms'][] = (float) $row['sum_real_time_ms'];
    }

    $stmt = $db->query('
        SELECT
            SUM(
                GREATEST(0,
                    LEAST(chart_intervals.interval_tick_end, UNIX_TIMESTAMP(done.grading_end_time)) -
                    GREATEST(chart_intervals.interval_tick_begin, UNIX_TIMESTAMP(done.grading_start_time)))
            ) as sum_server_time,
            SUM(
                GREATEST(0,
                    LEAST(chart_intervals.interval_tick_end, UNIX_TIMESTAMP(done.grading_start_time)) -
                    GREATEST(chart_intervals.interval_tick_begin, UNIX_TIMESTAMP(done.received_time)))
            ) as sum_queue_time
        FROM chart_intervals
        LEFT JOIN done
        ON done.received_time <= FROM_UNIXTIME(chart_intervals.interval_tick_end) AND done.grading_end_time >= FROM_UNIXTIME(chart_intervals.interval_tick_begin)
        GROUP BY interval_tick_begin');
    while($row = $stmt->fetch()) {
        $chart_data['avg_queue_size'][] = (float) $row['sum_queue_time'] / $interval['tick'];
        $chart_data['avg_server_time'][] = (float) $row['sum_server_time'] / $interval['tick'];
    }

    include('views/server_statistic/content.php');
