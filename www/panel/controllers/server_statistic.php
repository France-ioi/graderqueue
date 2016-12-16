<?php

    $queue_size = $db->query('SELECT COUNT(*) FROM queue')->fetch()[0];

    $interval_idx = intval($_GET['interval']);
    if(!$interval_idx) $interval_idx = 0;
    $interval = $CFG_stat_intervals[$interval_idx];

    $interval_end = time();
    $interval_begin = $interval_end - $interval['duration'];

    // stat
    $where = ' WHERE done_time BETWEEN FROM_UNIXTIME('.$interval_begin.') AND FROM_UNIXTIME('.$interval_end.')';
    $overview = $db->query("
        SELECT
            COUNT(*) as tasks_processed,
            AVG(TIMESTAMPDIFF(SECOND, received_time, done_time)) as avg_time_per_task,
            AVG(TIMESTAMPDIFF(SECOND, sent_time, done_time)) as avg_time_per_send_back,
            SUM(nb_fails) as errors_count
        FROM done ".$where
    )->fetch();
    $overview['avg_queue_size'] = $overview['avg_time_per_task'] * $overview['tasks_processed'] / $interval['duration'];


    // average number of busy servers
    $where = ' WHERE done_time >= FROM_UNIXTIME('.$interval_begin.') AND  received_time <= FROM_UNIXTIME('.$interval_end.')';
    $avg_server_time = $db->query('
        SELECT
            SUM(
                LEAST('.$interval_end.', UNIX_TIMESTAMP(done_time)) -
                GREATEST('.$interval_begin.', UNIX_TIMESTAMP(received_time))
            ) as avg_server_time
        FROM done '.$where
    )->fetch()['avg_server_time'];
    $overview['avg_server_time'] = $avg_server_time / $interval['duration'];



    // charts
    $where = ' WHERE done_time BETWEEN FROM_UNIXTIME('.$interval_begin.') AND FROM_UNIXTIME('.$interval_end.')';
    $query = $db->query("
        SELECT
            COUNT(*) as tasks_processed,
            AVG(TIMESTAMPDIFF(SECOND, received_time, done_time)) as avg_time_per_task,
            AVG(TIMESTAMPDIFF(SECOND, received_time, sent_time)) as avg_waiting_time,
            SUM(cpu_time_ms) as sum_cpu_time_ms,
            SUM(real_time_ms) as sum_real_time_ms,
            UNIX_TIMESTAMP(done_time) DIV ".$interval['tick']." as tick_pointer,
            SUM(
                LEAST(".$interval['tick']." * (1 + UNIX_TIMESTAMP(done_time) DIV ".$interval['tick']."), UNIX_TIMESTAMP(done_time)) -
                GREATEST(".$interval['tick']." * (UNIX_TIMESTAMP(done_time) DIV ".$interval['tick']."), UNIX_TIMESTAMP(received_time))
            ) as avg_server_time
        FROM done
        ".$where." GROUP BY tick_pointer"
    );

    $chart_data = array(
        'avg_waiting_time' => array(),
        'sum_cpu_time_ms' => array(),
        'sum_real_time_ms' => array(),
        'avg_queue_size' => array(),
        'avg_server_time' => array(),
        'labels' => array()
    );
    while($row = $query->fetch()) {
        $chart_data['avg_waiting_time'][] = (float) $row['avg_waiting_time'];
        $chart_data['sum_cpu_time_ms'][] = (float) $row['sum_cpu_time_ms'];
        $chart_data['sum_real_time_ms'][] = (float) $row['sum_real_time_ms'];
        $chart_data['avg_queue_size'][] = (float) $row['avg_time_per_task'] * $row['tasks_processed'] / $interval['tick'];
        $chart_data['avg_server_time'][] = (float) $row['avg_server_time'] / $interval['tick'];
        $chart_data['labels'][] = date("Y-m-d H:i:s", $row['tick_pointer'] * $interval['tick']);
    }



    include('views/server_statistic/content.php');