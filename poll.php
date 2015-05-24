<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

# TODO verifier l'auth SSL
$server_tags = array();
$server_id = 2;

$servres = $db->query("SELECT * FROM `servers` WHERE id=" . $server_id . ";");

if(!$servdata = $servres->fetch_assoc()) {
  die(jsonerror(2, "Authentication failed."));
}

# Check the server isn't considered as busy
if($servdata['simult_tasks'] > 0) {
  $taskcount = $db->query("SELECT COUNT(*) FROM `queue` WHERE sent_to=" . $server_id . ";")->fetch_row()[0];
  if($taskcount > $servdata['simult_tasks']) {
    die(jsonerror(1, "Server already accepted too many tasks."));
  }
}

$db->query("UPDATE `servers` SET status='polling', last_poll=NOW() WHERE id=" . $server_id . ";");
$start_time = time();

while(time() - $start_time < 30) {
  # We use a polling lock so that only one server polls the queue repeatedly
  if($db->query("SELECT GET_LOCK('queue-poll" . $servdata['type'] . "', 10);")->fetch_row()[0] != 1) {
    # We didn't acquire the lock
    continue;
  }

  # Start a transaction to lock rows
  $db->query("START TRANSACTION;");

  # We select all tasks which can possibly be sent to the server; we'll sort through them later
  $queuelist = $db->query("SELECT * FROM `queue` WHERE sent_to!=" . $server_id . "
                AND (status='queued' OR (status='sent' AND timeout_time <= NOW() AND nb_fails=0))
                AND EXISTS (SELECT 1 FROM task_types WHERE taskid=queue.id AND typeid=" . $servdata['type'] . ")
                ORDER BY priority DESC, received_time ASC LIMIT 1;");

  if($row = $queuelist->fetch_assoc()) {
    # We have a matching task
    if($row['status'] == 'sent') {
      # Task was selected because it timed out on last server
      # We update the tables
      $db->query("UPDATE `servers`,`queue` SET servers.status='timedout' WHERE queue.status='sent' AND queue.timeout_time <= NOW() AND queue.sent_to=servers.id;");
      $db->query("INSERT INTO `log` (log_type, task_id, server_id) SELECT 'error_timeout', queue.id, queue.sent_to FROM `queue` WHERE queue.status='sent' AND queue.timeout_time <= NOW();");
      $db->query("UPDATE `queue` SET sent_to=-1, status='error' WHERE status='sent' AND timeout_time <= NOW() AND nb_fails>=1;");
      $db->query("UPDATE `queue` SET sent_to=-1, nb_fails=nb_fails+1, status='queued' WHERE status='sent' AND timeout_time <= NOW() AND nb_fails<2;");
    }

    # We send the task and write down which server we sent it to
    if($db->query("UPDATE `queue` SET status='sent', sent_to=" . $server_id . ", sent_time=NOW(), timeout_time=NOW()+timeout WHERE id=" . $row['id'] . ";"))
    {
      echo "{\"errorcode\": 0, \"taskid\": " . $row['id'] . ", \"taskname\": " . json_encode($row['name']) . ", \"taskdata\": " . $row['taskdata'] . "}";
      $db->query("UPDATE `servers` SET status='busy' WHERE id=" . $server_id . ";");
      $db->query("INSERT INTO `log` (log_type, task_id, server_id) VALUES('notice_sentto', " . $row['id'] . "," . $server_id . ");");
    }
    else
    {
      echo jsonerror(2, "Failed to update queue, cannot send task.");
    }
    # We sent something normally (except if we got an error), so we end
    $db->query("COMMIT;");
    $db->query("SELECT RELEASE_LOCK('queue-poll" . $servdata['type'] . "');");
    exit();
  }
  # We commit the transaction and release the polling lock after 0.5 seconds
  $db->query("COMMIT;");
  usleep(500000);
  $db->query("SELECT RELEASE_LOCK('queue-poll" . $servdata['type'] . "');");
}
echo jsonerror(1, "No task available.");
?>
