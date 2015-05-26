<?php

# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

$received_from = -1;

if(!isset($_POST['request'])) {
  die(jsonerror(2, "No request made."));

} elseif($_POST['request'] == 'sendtask' or $_POST['request'] == 'sendsolution') {
  # Send a task, either directly the JSON data, either the solution information

  if($_POST['request'] == 'sendtask') {
    # Client is sending task JSON directly
    if(!isset($_POST['taskdata'])) {
      die(jsonerror(2, "taskdata missing from request."));
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
  if(isset($_POST['tags']) and $_POST['tags'] != '')
  {
    $tags = explode(',', $_POST['tags']);
    $tagids = array();

    # Fetch each tag
    foreach($tags as $t) {
      $tagq = $db->query("SELECT id FROM tags WHERE name='" . $db->real_escape_string($t) . "';")->fetch_row();
      if($tagq) {
        $tagids[] = $tagq[0];
      } else {
        die(jsonerror(2, "Tag `" . $t . "` unrecognized."));
      }
    }

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

  # Insert into queue
  $db->query("START TRANSACTION;");

  # Queue entry
  $stmt = $db->prepare("INSERT INTO `queue` (name, priority, received_from, received_time, taskdata) VALUES(?, ?, ?, NOW(), ?);");
  $jsondata = json_encode($evaljson);
  $stmt->bind_param("siis", $taskname, $priority, $received_from, $jsondata);
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

} elseif($_POST['request'] == "gettask") {
  # Read task information
  if(!isset($_POST['taskid'])) {
    die(jsonerror(2, "No taskid given."));
  }
  $taskid = intval($_POST['taskid']);
  if($_POST['taskid'] != strval($taskid)) {
    die(jsonerror(2, "Invalid taskid."));
  }

  if($row = $db->query("SELECT * FROM queue WHERE id=" . $taskid . ";")->fetch_assoc()) {
    echo json_encode(array('errorcode' => 0, 'errormsg' => 'Success', 'origin' => 'queue', 'data' => $row));
  } elseif($row = $db->query("SELECT * FROM done WHERE id=" . $taskid . ";")->fetch_assoc()) {
    echo json_encode(array('errorcode' => 0, 'errormsg' => 'Success', 'origin' => 'done', 'data' => $row));
  } else {
    echo jsonerror(2, "Invalid taskid.");
  }

} else {
  die(jsonerror(2, "No request made."));
}

?>
