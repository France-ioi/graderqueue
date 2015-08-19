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

$server_tags = array();

# Check the server isn't considered as busy
if($servdata['max_concurrent_tasks'] > 0) {
  $stmt = $db->prepare("SELECT COUNT(*) FROM `queue` WHERE sent_to=:sid;");
  $stmt->execute(array(':sid' => $server_id));
  $taskcount = $stmt->fetch()[0];
  if($taskcount > $servdata['max_concurrent_tasks']) {
    die(jsonerror(1, "Server already accepted too many tasks."));
  }
}

$stmt = $db->prepare("UPDATE `servers` SET status='polling', last_poll_time=NOW() WHERE id=:sid;");
$stmt->execute(array(':sid' => $server_id));
$start_time = time();

while(time() - $start_time < 20) {
  # We use a polling lock so that only one server polls the queue repeatedly
  $stmt = $db->prepare("SELECT GET_LOCK(:lockname, 10);");
  $stmt->execute(array(':lockname' => 'queue-poll' . $servdata['type']));
  if($stmt->fetch()[0] != 1) {
    # We didn't acquire the lock
    continue;
  }

  # Start a transaction to lock rows
  $db->beginTransaction();

  # We select all tasks which can possibly be sent to the server; we'll sort through them later
  $queuelist = $db->prepare("SELECT * FROM `queue` WHERE sent_to!=:sid
                AND (status='queued' OR (status='sent' AND timeout_time <= NOW() AND nb_fails=0))
                AND EXISTS (SELECT 1 FROM task_types WHERE taskid=queue.id AND typeid=:typeid)
                ORDER BY priority DESC, received_time ASC LIMIT 1;");
  $queuelist->execute(array(':sid' => $server_id, ':typeid' => $servdata['type']));

  if($row = $queuelist->fetch()) {
    # We have a matching task
    if($row['status'] == 'sent') {
      # Task was selected because it timed out on last server
      # We update the tables
      $db->query("UPDATE `servers`,`queue` SET servers.status='timedout' WHERE queue.status='sent' AND queue.timeout_time <= NOW() AND queue.sent_to=servers.id;");
      $db->query("INSERT INTO `log` (datetime, log_type, task_id, server_id) SELECT NOW(), 'error_timeout', queue.id, queue.sent_to FROM `queue` WHERE queue.status='sent' AND queue.timeout_time <= NOW();");
      $db->query("UPDATE `queue` SET sent_to=-1, status='error' WHERE status='sent' AND timeout_time <= NOW() AND nb_fails>=1;");
      $db->query("UPDATE `queue` SET sent_to=-1, nb_fails=nb_fails+1, status='queued' WHERE status='sent' AND timeout_time <= NOW() AND nb_fails<2;");
    }

    # We send the task and write down which server we sent it to
    $stmt = $db->prepare("UPDATE `queue` SET status='sent', sent_to=:sid, sent_time=NOW(), timeout_time=NOW()+timeout_sec WHERE id=:id;");
    if($stmt->execute(array(':sid' => $server_id, ':id' => $row['id']))) {
      echo json_encode(array('errorcode' => 0,
            'taskid' => intval($row['id']),
            'taskname' => $row['name'],
            'taskdata' => json_decode($row['taskdata'])));
      $stmt = $db->prepare("UPDATE `servers` SET status='busy' WHERE id=:sid;");
      $stmt->execute(array(':sid' => $server_id));
      db_log('notice_sentto', $row['id'], $server_id, '');
    } else {
      echo jsonerror(2, "Failed to update queue, cannot send task.");
    }
    # We sent something normally (except if we got an error), so we end
    $db->commit();
    $stmt = $db->prepare("SELECT RELEASE_LOCK(:lockname);");
    $stmt->execute(array(':lockname' => 'queue-poll' . $servdata['type']));
    exit();
  }
  # We commit the transaction and release the polling lock
  $db->commit();
  $stmt = $db->prepare("SELECT RELEASE_LOCK(:lockname);");
  $stmt->execute(array(':lockname' => 'queue-poll' . $servdata['type']));
}
echo jsonerror(1, "No task available.");
?>
