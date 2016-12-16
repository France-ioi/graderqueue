<?php

    // total in table
    $total = $db->query("SELECT COUNT(*) FROM `queue`;")->fetch()[0];


    $res = array(
          'draw' => isset($_GET['draw']) ? intval($_GET['draw']) : 0,
          'recordsTotal' => $total,
          'recordsFiltered' => $total,
          'data' => array()
    );


    // per page
    $limit = '';
    if(isset($_GET['start']) && $_GET['length'] != -1) {
        $limit = "LIMIT ".intval($_GET['start']).", ".intval($_GET['length']);
    }


    // filter condition
    $where = [];
    $params = [];
    $filter = isset($_GET['filter']) && is_array($_GET['filter']) ? $_GET['filter'] : [];
    if(isset($filter['id'])) {
        $where[] = 'queue.id = :id';
        $params[':id'] = $filter['id'];
    }
    if(isset($filter['date_start'])) {
        $where[] = 'queue.received_time >= :date_start';
        $params[':date_start'] = $filter['date_start'];
    }
    if(isset($filter['date_end'])) {
        $where[] = 'queue.received_time <= :date_end';
        $params[':date_end'] = $filter['date_end'];
    }
    $where = count($where) > 0 ? ' WHERE '.implode(' AND ', $where) : '';


    // filtered rows total amount
    $query = $db->prepare('SELECT COUNT(*) FROM `queue`'.$where);
    $query->execute($params);
    $res['recordsFiltered'] = $query->fetch()[0];

    // filtered rows per page
    $select = "
        SELECT queue.*,
        GROUP_CONCAT(server_types.name SEPARATOR ',') AS types
        FROM `queue`
        LEFT JOIN job_types ON job_types.jobid=queue.id
        LEFT JOIN server_types ON server_types.id=job_types.typeid";
    $query = $db->prepare($select.$where.' GROUP BY queue.id ORDER BY priority DESC, received_time ASC '.$limit);
    $query->execute($params);


    while($row = $query->fetch()) {
        $res['data'][] = array(
            $row['id'],
            HTML::lines(
                $row['name'],
                $row['taskrevision'] != '' ? "(rev: " . $row['taskrevision'] . ")" : null
            ),
            HTML::lines(
                $row['status'] == 'error' ? HTML::error($row['status']) : $row['status'],
                $row['nb_fails'] > 0 ? HTML::error("(" . $row['nb_fails'] . " fails)") : null
            ),
            $row['priority'],
            $row['timeout_sec'].'s',
            HTML::lines(
                'Received from #'.$row['received_from'],
                $row['job_repeats'] > 0 ? HTML::error('ignored '.$row['job_repeats'].' repeats') : null,
                $row['sent_to'] > 0 ? 'Sent to #'.$row['sent_to'] : HTML::hint('Not sent yet', 'Can be sent to server types '.$row['types'])
            ),
            HTML::lines(
                'Received: '.$row['received_time'],
                $row['sent_to'] > 0 ? "Sent in ".HTML::hint(deltatime($row['received_time'], $row['sent_time']), $row['sent_time']) : null
            ),
            HTML::json($row['jobdata'])
        );
    }


    die(json_encode($res));