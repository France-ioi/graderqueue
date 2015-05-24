<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

if(!(isset($_POST['taskpath']) and isset($_POST['memlimit']) and isset($_POST['timelimit']) and isset($_POST['lang']) and (isset($_POST['solpath']) or (isset($_FILES['solfile']) and is_uploaded_file($_FILES['solfile']['tmp_name'])))))
{
  die("Invalid form data.");
}

$memlimit = max(4, intval($_POST['memlimit']));
$timelimit = max(1, intval($_POST['timelimit']));
$sollang = $_POST['lang'];
$priority = max(0, intval($_POST['priority']));

if($_POST['tags'] != '')
{
  $tags = explode(',', $_POST['tags']);
  $tagids = array();

  # Convert tags to list of server types which can execute the task
  foreach($tags as $t) {
    $tagq = $db->query("SELECT id FROM tags WHERE name='" . $db->real_escape_string($t) . "';")->fetch_row();
    if($tagq) {
      $tagids[] = $tagq[0];
    } else {
      die("Tag `" . $t . "` unrecognized.");
    }
  }

  $typeids = array();
  $typeq = $db->query("SELECT typeid, COUNT(*) AS nb FROM type_tags WHERE tagid IN (" . implode(',', $tagids) . ") GROUP BY typeid HAVING nb=" . count($tagids) . ";");
  while($row = $typeq->fetch_assoc()) {
    $typeids[] = $row['typeid'];
  }
  if(count($typeids) == 0) {
    die("No server type can execute tasks with tags " . $_POST['tags'] . ".");
  }
}

if(isset($_POST['solpath'])) {
  $solname = basename($_POST['solpath']);
  $soljson = array(
    "name" => $solname,
    "path" => $_POST['solpath']);
} else {
  $solname = $_FILES['solfile']['name'];
  $soljson = array(
    "name" => $solname,
    "content" => file_get_contents($_FILES['solfile']['tmp_name']));
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

# Insert into queue
$db->query("START TRANSACTION;");

# Queue entry
$stmt = $db->prepare("INSERT INTO `queue` (name, priority, received_from, received_time, taskdata) VALUES(?, ?, -1, NOW(), ?);");
$taskname = "interface-" . $solname;
$jsondata = json_encode($evaljson);
$stmt->bind_param("sis", $taskname, $priority, $jsondata);
$stmt->execute();

# Set the task to be accepted by any server
$taskid = $stmt->insert_id;
if(isset($typeids)) {
  $db->query("INSERT INTO `task_types` (taskid, typeid) VALUES (" . $taskid . "," . implode("), (" . $taskid . ",", $typeids) . ");");
} else {
  $db->query("INSERT INTO `task_types` (taskid, typeid) SELECT " . $taskid . ", server_types.id FROM server_types;");
}

$db->query("COMMIT;");

echo "<div>Queued as task ID #" . $taskid . ". (refresh)</div>";

?>
