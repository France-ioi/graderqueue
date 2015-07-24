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

if(isset($_POST['resultdata'])) {
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
} else {
  # No result data sent
  db_log('error_no_resultdata', $task_id, $server_id, '');
  $db->query("INSERT INTO `log` (log_type, task_id, server_id) VALUES('error_no_resultdata', " . $task_id . "," . $server_id . ");");
  echo jsonerror(2, "No resultdata received.");
}
$db->commit();
?>
