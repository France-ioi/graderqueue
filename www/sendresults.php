<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

# Get server data
if($servdata = get_ssl_client_info('servers')) {
  # Client was identified by a SSL client certificate
  $server_id = $servdata['id'];
} elseif($servdata = auth_graderserver_by_token()) {
  $server_id = $servdata['id'];
} else {
  die(jsonerror(3, "No valid authentication provided."));
}

# Validate sent information
if(isset($_POST['jobid'])) {
  $job_id = intval($_POST['jobid']);
} else {
  die(jsonerror(2, "No job ID sent."));
}

if (!$_POST['resultdata']) {
   db_log('error_no_resultdata', $job_id, $server_id, '');
   die(jsonerror(2, "No resultdata received."));
}

try {
   $resultdata = json_decode($_POST['resultdata'], true);
} catch(Exception $e) {
   db_log('error_resultdata_not_json', $job_id, $server_id, '');
   die(jsonerror(2, "Cannot decode resultdata: ".$e->getMessage()));
}

# Isolate as a transaction
$db->beginTransaction();

# Check the server was sent this job
$res = $db->prepare("SELECT * FROM `queue` WHERE status='sent' AND id=:jobid AND sent_to=:sid FOR UPDATE;");
$res->execute(array(':jobid' => $job_id, ':sid' => $server_id));
if(!$jobrow = $res->fetch()) {
  db_log('error_not_assigned', $job_id, $server_id, '');
  $db->commit();
  die(jsonerror(2, "Task doesn't exist or server doesn't have this job assigned."));
}


// get platform information
if($jobrow['received_from'] > 0) {
  $res = $db->prepare("SELECT * FROM `platforms` WHERE id = :platid;");
  $res->execute(array(':platid' => $jobrow['received_from']));
  $platform = $res->fetch();
  if (!$platform) {
    $db->commit();
    die(jsonerror(2, "Cannot find platform corresponding to job ".$job_id));
  }
}


# Check error code; if 0 or 1, save the results and don't try again // if 2, retry sending the task
if(isset($resultdata['errorcode']) and $resultdata['errorcode'] <= 1) {

  # Extract values for statistic
  $stat_data = extractTaskStat(json_decode($jobrow['jobdata'], true), $resultdata);

  # Save the results
  $stat_keys = implode(',', array_map(function ($s) { return substr($s, 1); }, array_keys($stat_data)));
  $stat_placeholders = implode(',', array_keys($stat_data));
  $stmt = $db->prepare("INSERT INTO `done` (jobid, name, job_repeats, priority, timeout_sec, nb_fails, received_from, received_time, sent_to, grading_start_time, tags, jobdata, grading_end_time, resultdata, ".$stat_keys.")
                 SELECT queue.id, queue.name, queue.job_repeats, queue.priority, queue.timeout_sec, queue.nb_fails, queue.received_from, queue.received_time, queue.sent_to, queue.grading_start_time, queue.tags, queue.jobdata, NOW(), :resultdata, ".$stat_placeholders."
                 FROM `queue`
                 WHERE id=:jobid;");

  $stmt_params = array_merge(
    $stat_data,
    array(':resultdata' => json_encode($resultdata), ':jobid' => $job_id)
  );

  if($stmt->execute($stmt_params)) {
    # Success!
    $stmt = $db->prepare("DELETE FROM `queue` WHERE id=:jobid;");
    $stmt->execute(array(':jobid' => $job_id));
    $db->commit();

    # Delete job_types for this job
    $stmt = $db->prepare("DELETE FROM `job_types` WHERE jobid=:jobid;");
    $stmt->execute(array(':jobid' => $job_id));

    echo jsonerror(0, "Saved resultdata.");
  } else {
    # Error while saving results
    db_log('error_saving_resultdata', $job_id, $server_id, '');
    $db->commit();
    # We send a code of one to tell the server to try again
    die(jsonerror(1, "Error saving resultdata: " . $stmt->errorInfo()[2]));
  }
} else {
  # Try again sending the task
  $query = "UPDATE `queue` SET status='queued', sent_to=-1";
//  if($resultdata['errorcode'] != 3) {
    $query .= ", nb_fails=nb_fails+1";
//  }
  $query.= " WHERE id=:jobid;";
  $stmt = $db->prepare($query);
  $stmt->execute(array(':jobid' => $job_id));
  db_log('error_in_result', $job_id, $server_id, isset($resultdata['errormsg']) ? $resultdata['errormsg'] : '');
  $db->commit();
  die(jsonerror(2, "Resultdata received invalid."));
}

# If job was sent through interface, we're done
if($jobrow['received_from'] <= 0 || $platform['return_url'] == '') {
  die();
} else {
  # Else send result to return_url if present
  # Close connection to database while sending back results
  $res = null;
  $stmt = null;
  $db = null;
}

$tokenParams = array(
  'sTaskName' => $jobrow['name'],
  'sResultData' => $resultdata['jobdata']
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
   error_log('cannot read platform return url of platform '.$platform['id'].' for job '.$job_id.': '.$e->getMessage());
}

if (!$server_output['bSuccess']) {
   error_log('received error from return url of platform '.$platform['id'].' for job '.$job_id.': '.$server_output['sError']);
}
