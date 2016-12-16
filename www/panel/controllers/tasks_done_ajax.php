<?php

    // table rows total amount
    $total = $db->query('SELECT COUNT(*) FROM `done`')->fetch()[0];

    $res = array(
          'draw' => isset($_GET['draw']) ? intval($_GET['draw']) : 0,
          'recordsTotal' => $total,
          'recordsFiltered' => 0,
          'data' => array()
    );

    // page limits
    $limit = '';
    if(isset($_GET['start']) && $_GET['length'] != -1) {
        $limit = ' LIMIT '.intval($_GET['start']).", ".intval($_GET['length']);
    }


    // filter condition
    $where = [];
    $params = [];
    $filter = isset($_GET['filter']) && is_array($_GET['filter']) ? $_GET['filter'] : [];
    if(isset($filter['id'])) {
        $where[] = 'id = :id';
        $params[':id'] = $filter['id'];
    }
    if(isset($filter['task_path'])) {
        $where[] = 'task_path LIKE :task_path';
        $params[':task_path'] = $filter['task_path'].'%';
    }
    if(isset($filter['task_name'])) {
        $where[] = 'task_name LIKE :task_name';
        $params[':task_name'] = '%'.$filter['task_name'].'%';
    }
    if(isset($filter['language'])) {
        $where[] = 'language LIKE :language';
        $params[':language'] = '%'.$filter['language'].'%';
    }
    if(isset($filter['date_start'])) {
        $where[] = 'done_time >= :date_start';
        $params[':date_start'] = $filter['date_start'];
    }
    if(isset($filter['date_end'])) {
        $where[] = 'done_time <= :date_end';
        $params[':date_end'] = $filter['date_end'];
    }
    $where = count($where) > 0 ? ' WHERE '.implode(' AND ', $where) : '';


    // filtered rows total amount
    $query = $db->prepare('SELECT COUNT(*) FROM `done`'.$where);
    $query->execute($params);
    $res['recordsFiltered'] = $query->fetch()[0];

    $query = $db->prepare('SELECT * FROM `done`'.$where.' ORDER BY done_time DESC'.$limit);
    $query->execute($params);




    while($row = $query->fetch()) {
        $resultdata = json_decode($row['resultdata'], true);

        $summary = [];
        if($row['task_path'] != '' && $row['task_path'] != '/') {
            $summary[] = HTML::hint('Task: '.$row['task_name'], $row['task_path']);
        }

        if(isset($resultdata['errorcode']) && $resultdata['errorcode'] > 0) {
            $summary[] = HTML::expander(
                'Error #'.$resultdata['errorcode'].' received from server.',
                $resultdata['errormsg'],
                'error'
            );
        } else {
            # No error
            if(isset($resultdata['errormsg'])) {
                $errormsg = str_replace("Taskgrader executed successfully.\n", '', $resultdata['errormsg']);
                if($errormsg != '') {
                    $summary[] = HTML::expander('Toggle server message', $errormsg);
                }
            }
            if(isset($resultdata['jobdata'])) {
                $resultdata = $resultdata['jobdata'];
                foreach($resultdata['solutions'] as $solution) {
                    if($solution['compilationExecution']['exitCode'] > 0) {
                        $summary[] = HTML::expander('Solution #'.$solution['id']. ' didn\'t compile.', $solution['compilationExecution']['stderr']['data']);
                    }
                }
                foreach($resultdata['executions'] as $execution) {

                    $summary[] = 'Execution #'.$execution['id'];
                    foreach($execution['testsReports'] as $report) {
                        if(isset($report['checker'])) {
                            $summary[] = HTML::expander('Solution executed successfully', $report['checker']['stdout']['data']);
                        } elseif(isset($report['execution'])) {
                            $summary[] = 'Solution returned an error';
                        } else {
                            $summary[] = 'Test rejected by sanitizer';
                        }
                    }
                }
            }
        }




        $res['data'][] = array(
            HTML::lines(
                '#'.$row['jobid'],
                HTML::italic('('.$row['id'].')'),
                $row['name']
            ),
            HTML::lines(
                'priority: '.$row['priority'],
                'timeout: '.$row['timeout_sec'].'s',
                $row['nb_fails'] > 0 ? HTML::error('('.$row['nb_fails'].' fails)') : null
            ),
            HTML::lines(
                'Received from #'.$row['received_from'],
                $row['job_repeats'] > 0 ? HTML::error('ignored '.$row['job_repeats'].' repeats') : null,
                'Sent to #'.$row['sent_to']
            ),
            HTML::lines(
                'Received: '.$row['received_time'],
                'sent in '.HTML::hint(deltatime($row['received_time'], $row['sent_time']), $row['sent_time']),
                'done in '.HTML::hint(deltatime($row['sent_time'], $row['done_time']), $row['done_time'])
            ),
            HTML::lines($summary),
            HTML::json($row['jobdata']),
            HTML::json($row['resultdata'])
        );
    }


    die(json_encode($res));