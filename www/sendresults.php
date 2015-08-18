<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

if($servdata = get_ssl_client_info('servers')) {
  # Client was identified by a SSL client certificate
  $server_id = $servdata['id'];
} else {
  die(jsonerror(3, "No valid authentication provided."));
}

if(isset($_POST['taskid'])) {
  $task_id = intval($_POST['taskid']);
} else {
  die(jsonerror(2, "No task ID sent."));
}

// get platform information
$res = $db->prepare("SELECT platforms.* FROM `platforms` JOIN `queue` on queue.received_from = platforms.id WHERE queue.id = :taskid;");
$res->execute(array(':taskid' => $task_id));
$platform = $res->fetch();
if (!$platform) {
  die(jsonerror(2, "Cannot find platform corresponding to task ".$task_id));
}

# Isolate as a transaction
$db->beginTransaction();

# Check the server was sent this task
$res = $db->prepare("SELECT * FROM `queue` WHERE status='sent' AND id=:taskid AND sent_to=:sid;");
$res->execute(array(':taskid' => $task_id, ':sid' => $server_id));
if(!$row = $res->fetch()) {
  db_log('error_not_assigned', $task_id, $server_id, '');
  $db->commit();
  die(jsonerror(2, "Task doesn't exist or server doesn't have this task assigned."));
}

if(isset($_POST['resultdata']) && isset($_POST['resultdata']['errorcode']) && $_POST['resultdata']['errorcode'] == 0) {
  $stmt = $db->prepare("INSERT INTO `done` (id, name, priority, timeout_sec, nb_fails, received_from, received_time, sent_to, sent_time, tags, taskdata, done_time, resultdata)
                 SELECT queue.id, queue.name, queue.priority, queue.timeout_sec, queue.nb_fails, queue.received_from, queue.received_time, queue.sent_to, queue.sent_time, queue.tags, queue.taskdata, NOW(), :resultdata
                 FROM `queue`
                 WHERE id=:taskid;");
  if($stmt->execute(array(':resultdata' => $_POST['resultdata'], ':taskid' => $task_id))) {
    # Success!
    $stmt = $db->prepare("DELETE FROM `queue` WHERE id=:taskid;");
    $stmt->execute(array(':taskid' => $task_id));
    $stmt = $db->prepare("UPDATE `servers` SET status='idle' WHERE id=:sid;");
    db_log('notice_recv_resultdata', $task_id, $server_id, '');
    echo jsonerror(0, "Saved resultdata.");
  } else {
    # Error while saving results
    db_log('error_saving_resultdata', $task_id, $server_id, '');
    echo jsonerror(2, "Error saving resultdata.");
  }
  $db->commit();
} else {
  if (!isset($_POST['resultdata'])) {
     # No result data sent
     db_log('error_no_resultdata', $task_id, $server_id, '');
     echo jsonerror(2, "No resultdata received.");
  } else {
     # No result data sent
     db_log('error_in_result', $task_id, $server_id, isset($_POST['resultdata']['errormsg']) ? $_POST['resultdata']['errormsg'] : '');
     echo jsonerror(2, "Error received: ".$_POST['resultdata']['errormsg']);
  }
  $db->commit();
  exit();
}

// send result to return_url:

$tokenParams = array(
  'sTaskName' => $row['name'],
  'sResultData' => $_POST['resultdata']
);

$jwe = encode_params_in_token($tokenParams, $platform);

$post_request = array(
   'sToken' => $jwe
);

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL,$platform['return_url']);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_request));

$server_output = curl_exec ($ch);

curl_close ($ch);

try {
   $server_output = json_decode($server_output, true);
} catch(Exception $e) {
   error_log('cannot read platform return url of platform '.$platform['id'].' for task '.$task_id.': '.$e->getMessage());
}

if (!$server_output['bSuccess']) {
   error_log('received error from return url of platform '.$platform['id'].' for task '.$task_id.': '.$server_output['sError']);
}
