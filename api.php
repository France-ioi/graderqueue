<?php

# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

if(isset($_SERVER['SSL_CLIENT_VERIFY']) && $_SERVER['SSL_CLIENT_VERIFY'] == 'SUCCESS'
    && ($platdata = $db->query("SELECT * FROM platforms
        WHERE ssl_serial='" . $db->real_escape_string($_SERVER['SSL_CLIENT_M_SERIAL']) . "'
        AND ssl_dn='" . $db->real_escape_string($_SERVER['SSL_CLIENT_I_DN']) . "'")->fetch_assoc())) {
  $received_from = $platdata['id'];
} elseif(isset($_POST['token'])) {
  if($db->query("SELECT * FROM `tokens` WHERE expires >= NOW() AND token='" . $db->real_escape_string($_POST['token']) . "';")->fetch_row()) {
    $received_from = -1;
    $platdata = array(
        'restrict_paths' => '',
        'force_tag' => -1);
  } else {
    die(jsonerror(3, "Invalid token, please refresh the interface to get a new one."));
  }
  $db->query("DELETE FROM `tokens` WHERE expires < NOW();");
} else {
  die(jsonerror(3, "No valid authentication provided."));
}

if(!isset($_POST['request'])) {
  die(jsonerror(2, "No request made."));

} elseif($_POST['request'] == 'sendtask' or $_POST['request'] == 'sendsolution') {
  # Send a task, either directly the JSON data, either the solution information

  if($_POST['request'] == 'sendtask') {
    # Client is sending task JSON directly
    if(!isset($_POST['taskdata'])) {
      die(jsonerror(2, "taskdata missing from request."));
    }
    try {
        $taskdata = json_decode($_POST['taskdata']);
    } catch(Exception $e) {
        die(jsonerror(2, "Error while decoding task JSON : " . $e->getMessage()));
    }

    if(isset($_POST['taskname'])) {
      $taskname = $_POST['taskname'];
    } else {
      $taskname = "api-sendtask";
    }

  } elseif($_POST['request'] == 'sendsolution') {
    # Client is sending only parameters, we make the task JSON from those
    foreach(array('taskpath', 'memlimit', 'timelimit', 'lang') as $key) {
      if(!isset($_POST[$key])) {
        die(jsonerror(2, $key . " missing from request."));
      }
    }

    if(!((isset($_POST['solpath']) and $_POST['solpath'] != '')
        or (isset($_POST['solcontent']) and $_POST['solcontent'] != '')
        or (isset($_FILES['solfile']) and is_uploaded_file($_FILES['solfile']['tmp_name'])))) {
      die(jsonerror(2, "Solution missing from request."));
    }

    $memlimit = max(4, intval($_POST['memlimit']));
    $timelimit = max(1, intval($_POST['timelimit']));
    $sollang = $_POST['lang'];
    $priority = max(0, intval($_POST['priority']));

    if(isset($_POST['solpath']) and $_POST['solpath'] != '') {
      $solname = basename($_POST['solpath']);
      $soljson = array(
        "name" => $solname,
        "path" => $_POST['solpath']);
    } elseif(isset($_POST['solcontent']) and $_POST['solcontent'] != '') {
      # Adapt to sol
      if(isset($CFG_defaultexts[$sollang])) {
        $solname = "main" . $CFG_defaultexts[$sollang];
      } else {
        $solname = "main" . $CFG_defaultexts['[default]'];
      }
      $soljson = array(
        "name" => $solname,
        "content" => $_POST['solcontent']);
    } else {
      $solname = $_FILES['solfile']['name'];
      $soljson = array(
        "name" => $solname,
        "content" => file_get_contents($_FILES['solfile']['tmp_name']));
    }

    if(isset($_POST['taskname'])) {
      $taskname = $_POST['taskname'];
    } else {
      $taskname = "api-" . $solname;
    }

    # Make JSON (as grade.py from taskgrader does)
    $execparamsjson = array("timeLimitMs" => $timelimit,
        "memoryLimitKb" => $memlimit,
        "useCache" => True,
        "stdoutTruncateKb" => -1,
        "stderrTruncateKb" => -1,
        "getFiles" => array());

    $solutionsjson = array(array(
        "id" => "sol-" . $solname,
        "compilationDescr" => array(
            "language" => $sollang,
            "files" => array($soljson),
            "dependencies" => "@defaultDependencies-" . $sollang),
        "compilationExecution" => $execparamsjson));

    $executionsjson = array(array(
        "id" => "exec-" . $solname,
        "idSolution" => "sol-" . $solname,
        "filterTests" => "@defaultFilterTests-" . $sollang,
        "runExecution" => $execparamsjson));

    $evaljson = array(
        "taskPath" => $_POST['taskpath'],
        "generators" => array("@defaultGenerator"),
        "generations" => array("@defaultGeneration"),
        "extraTests" => "@defaultExtraTests",
        "sanitizer" => "@defaultSanitizer",
        "checker" => "@defaultChecker",
        "solutions" => $solutionsjson,
        "executions" => $executionsjson);
  }

  # Convert tags to list of server types which can execute the task
  # We make slow requests to have meaningful error messages
  $tagids = array();

  if($platdata['force_tag'] != -1) {
    $tagids[] = $platdata['force_tag'];
  }

  if(isset($_POST['tags']) and $_POST['tags'] != '')
  {
    $tags = explode(',', $_POST['tags']);

    # Fetch each tag
    foreach($tags as $t) {
      $tagq = $db->query("SELECT id FROM tags WHERE name='" . $db->real_escape_string($t) . "';")->fetch_row();
      if($tagq) {
        $tagids[] = $tagq[0];
      } else {
        die(jsonerror(2, "Tag `" . $t . "` unrecognized."));
      }
    }
  }

  if(count($tagids) > 0) {
    # Fetch all server types which can execute with these tags
    $typeids = array();
    $typeq = $db->query("SELECT typeid, COUNT(*) AS nb FROM type_tags WHERE tagid IN (" . implode(',', $tagids) . ") GROUP BY typeid HAVING nb=" . count($tagids) . ";");
    while($row = $typeq->fetch_assoc()) {
      $typeids[] = $row['typeid'];
    }
    if(count($typeids) == 0) {
      die(jsonerror(2, "No server type can execute tasks with tags " . $_POST['tags'] . "."));
    }
  }
  $posttags = $_POST['tags'];

  # Add path restrictions if needed
  if($platdata['restrict_paths'] != '') {
    $evaljson['restrictToPaths'] = $platdata['restrict_paths'];
  }

  # Insert into queue
  $db->query("START TRANSACTION;");

  # Queue entry
  $stmt = $db->prepare("INSERT INTO `queue` (name, priority, received_from, received_time, tags, taskdata) VALUES(?, ?, ?, NOW(), ?, ?);");
  $jsondata = json_encode($evaljson);
  $stmt->bind_param("siiss", $taskname, $priority, $received_from, $posttags, $jsondata);
  $stmt->execute();

  $taskid = $stmt->insert_id;
  if(isset($typeids)) {
    # Only some server types can execute it
    $db->query("INSERT INTO `task_types` (taskid, typeid) VALUES (" . $taskid . "," . implode("), (" . $taskid . ",", $typeids) . ");");
  } else {
    # Set the task to be accepted by any server
    $db->query("INSERT INTO `task_types` (taskid, typeid) SELECT " . $taskid . ", server_types.id FROM server_types;");
  }

  $db->query("COMMIT;");

  echo json_encode(array('errorcode' => 0, 'errormsg' => "Queued as task ID #" . $taskid . ".", 'taskid' => $taskid));
  flush();

  # Wake up a server if needed
  $query = "
    SELECT servers.*,
      COUNT(queue.id) AS nbtasks
    FROM `servers`
    LEFT JOIN queue ON queue.sent_to=servers.id";
  if(isset($typeids)) {
    $query .= " WHERE type IN (" . implode(',', $typeids) . ")";
  }
  $query .= " GROUP BY servers.id
    ORDER BY nbtasks DESC, last_poll DESC;";
  $res = $db->query($query);
  while($row = $res->fetch_assoc()) {
    if(!($row['nbtasks'] < $row['simult_tasks'] or time()-strtotime($row['last_poll'] < 60)))
    {
      # Need to wake this server up
      if(($fs = fsockopen($row['url_wakeup'])) !== FALSE)
      {
        fwrite($fs, ' ');
        fclose($fs);
        die();
      }
      # If failed we'll try the next server
    }
  }

} elseif($_POST['request'] == "gettask") {
  # Read task information
  if(!isset($_POST['taskid'])) {
    die(jsonerror(2, "No taskid given."));
  }
  $taskid = intval($_POST['taskid']);
  if($_POST['taskid'] != strval($taskid)) {
    die(jsonerror(2, "Invalid taskid."));
  }

  if($row = $db->query("SELECT * FROM queue WHERE id=" . $taskid . " AND received_from=" . $received_from . ";")->fetch_assoc()) {
    echo json_encode(array('errorcode' => 0, 'errormsg' => 'Success', 'origin' => 'queue', 'data' => $row));
  } elseif($row = $db->query("SELECT * FROM done WHERE id=" . $taskid . " AND received_from=" . $received_from . ";")->fetch_assoc()) {
    echo json_encode(array('errorcode' => 0, 'errormsg' => 'Success', 'origin' => 'done', 'data' => $row));
  } else {
    echo jsonerror(2, "Invalid taskid.");
  }

} else {
  die(jsonerror(2, "No request made."));
}

?>
