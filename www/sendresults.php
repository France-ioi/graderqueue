<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

if($servdata = getclientinfo('servers')) {
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
$db->query("START TRANSACTION;");

# Check the server was sent this task
$res = $db->query("SELECT * FROM `queue` WHERE status='sent' AND id=" . $task_id . " AND sent_to=" . $server_id . ";");
if(!$row = $res->fetch_assoc()) {
  $db->query("INSERT INTO `log` (log_type, task_id, server_id) VALUES('error_not_assigned', " . $task_id . "," . $server_id . ");");
  $db->query("COMMIT;");
  die(jsonerror(2, "Task doesn't exist or server doesn't have this task assigned."));
}

if(isset($_POST['resultdata'])) {
  if($db->query("INSERT INTO `done` (id, name, priority, timeout, nb_fails, received_from, received_time, sent_to, sent_time, tags, taskdata, done_time, resultdata)
                 SELECT queue.id, queue.name, queue.priority, queue.timeout, queue.nb_fails, queue.received_from, queue.received_time, queue.sent_to, queue.sent_time, queue.tags, queue.taskdata, NOW(), '" . $db->escape_string($_POST['resultdata']) . "'
                 FROM `queue`
                 WHERE id=" . $task_id . ";")) {
    # Success!
    $db->query("DELETE FROM `queue` WHERE id=" . $task_id . ";");
    $db->query("UPDATE `servers` SET status='idle' WHERE id=" . $server_id . ";");
    $db->query("INSERT INTO `log` (log_type, task_id, server_id) VALUES('notice_recv_resultdata', " . $task_id . "," . $server_id . ");");
    echo jsonerror(0, "Saved resultdata.");
  } else {
    # Error while saving results
    $db->query("INSERT INTO `log` (log_type, task_id, server_id) VALUES('error_saving_resultdata', " . $task_id . "," . $server_id . ");");
    echo jsonerror(2, "Error saving resultdata.");
  }
} else {
  # No result data sent
  $db->query("INSERT INTO `log` (log_type, task_id, server_id) VALUES('error_no_resultdata', " . $task_id . "," . $server_id . ");");
  echo jsonerror(2, "No resultdata received.");
}
$db->query("COMMIT;");
?>
