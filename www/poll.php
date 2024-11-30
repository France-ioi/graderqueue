<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

if($servdata = get_ssl_client_info('servers')) {
  # Client was identified by a SSL client certificate
  $server_id = $servdata['id'];
} elseif($servdata = auth_graderserver_by_token()) {
  $server_id = $servdata['id'];
} else {
  die(jsonerror(3, "No valid authentication provided."));
}

$server_tags = array();

if(isset($_POST['nbtasks']) && $_POST['nbtasks'] == 0) {
  # The server says he has no task in progress, if the queue considers it has
  # some, we consider them as timeouted on that server
  $stmt = $db->prepare("UPDATE `queue` SET sent_to=-1, nb_fails=nb_fails+1, status='queued' WHERE status='sent' AND sent_to=:sid;");
  $stmt->execute(array(':sid' => $server_id));
}

# Check the server isn't considered as busy
if($servdata['max_concurrent_jobs'] > 0) {
  $stmt = $db->prepare("SELECT COUNT(*) FROM `queue` WHERE status='queued' AND sent_to=:sid AND timeout_time > NOW();");
  $stmt->execute(array(':sid' => $server_id));
  $jobcount = $stmt->fetch()[0];
  if($jobcount > $servdata['max_concurrent_jobs']) {
    die(jsonerror(1, "Server already accepted too many jobs."));
  }
}

$stmt = $db->prepare("UPDATE `servers` SET last_poll_time=NOW(), wakeup_fails=0 WHERE id=:sid;");
$stmt->execute(array(':sid' => $server_id));
$start_time = time();

while(time() - $start_time < 20) {
  # Start a transaction to lock rows
  $db->beginTransaction();

  # We select a job which can be sent to the server
  $queuelist = $db->prepare("SELECT * FROM `queue`
        JOIN job_types ON job_types.jobid = queue.id
        WHERE sent_to!=:sid
        AND nb_fails<:maxfails
        AND (
            status='queued'
            OR (timeout_time <= NOW() AND
                (status='sent' OR status='waiting'))
        )
        AND job_types.typeid = :typeid
        ORDER BY received_time ASC
        LIMIT 1
        FOR UPDATE;");
  $queuelist->execute(array(':sid' => $server_id, ':typeid' => $servdata['type'], ':maxfails' => $CFG_max_fails));

  if($row = $queuelist->fetch()) {
    # We have a matching job
    $query = "UPDATE `queue` SET status='sent', sent_to=:sid, grading_start_time=NOW(), timeout_time=DATE_ADD(NOW(), INTERVAL timeout_sec SECOND)";
    if($row['status'] == 'sent') {
      # Task was selected because it timed out on last server
      db_log('error_timeout', $row['id'], $row['sent_to'], '');
      $query .= ", nb_fails=nb_fails+1";
    }
    $query .= " WHERE id=:id AND sent_to=:sentto;";

    # We send the job and write down which server we sent it to
    $stmt = $db->prepare($query);
    if(!$stmt->execute(array(':sid' => $server_id, ':id' => $row['id'], ':sentto' => $row['sent_to']))) {
      echo jsonerror(2, "Failed to update queue, cannot send job.");
      exit();
      # Output the task information
    }
    # Check we did get the task
    $stmt = $db->prepare("SELECT * FROM `queue` WHERE id=:id AND sent_to=:sid;");
    $stmt->execute(array(':id' => $row['id'], ':sid' => $server_id));
    if($row = $stmt->fetch()) {
      echo json_encode(array(
        'errorcode' => 0,
        'jobid' => intval($row['id']),
        'jobname' => $row['name'],
        'taskrevision' => $row['taskrevision'],
        'jobdata' => json_decode($row['jobdata']
      )));
      # We sent something normally (except if we got an error), so we end
      $db->commit();
      exit();
    }
  }
  # We commit the transaction and release the polling lock
  $db->commit();
  usleep(500000);
}
echo jsonerror(1, "No job available.");
?>
