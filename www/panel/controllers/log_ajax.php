<?php

    $total = $db->query("SELECT COUNT(*) FROM `log`;")->fetch()[0];
    $res = array(
          'draw' => isset($_GET['draw']) ? intval($_GET['draw']) : 0,
          'recordsTotal' => $total,
          'recordsFiltered' => $total,
          'data' => array()
    );

    $limit = '';
    if(isset($_GET['start']) && $_GET['length'] != -1) {
        $limit = "LIMIT ".intval($_GET['start']).", ".intval($_GET['length']);
    }
    $query = $db->query("SELECT * FROM `log` ORDER BY datetime DESC ".$limit);

    while($row = $query->fetch()) {
        $res['data'][] = array(
            $row['id'],
            $row['datetime'],
            $row['log_type'],
            $row['job_id'],
            $row['server_id'],
            $row['message']
        );
    }

    die(json_encode($res));