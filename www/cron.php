<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require_once __DIR__.'/config.inc.php';
require_once __DIR__.'/funcs.inc.php';

$go = getopt('h', ['hourly']);
$hourly = isset($go['h']) || isset($go['hourly']) || isset($_GET['h']) || isset($_GET['hourly']);

# Set tasks in error when their nb_fails is too high
$stmt = $db->prepare("UPDATE `queue` SET status='error' WHERE status='queued' AND nb_fails>=:maxfails;");
$stmt->execute(array(':maxfails' => $CFG_max_fails));

if($hourly) {
  # Delete old tasks and logs
  $stmt = $db->prepare("DELETE FROM `queue` WHERE received_time <= NOW() - INTERVAL :days day AND grading_start_time <= NOW() - INTERVAL :days day;");
  $stmt->execute(array(':days' => $CFG_keep_old_days));
  $stmt = $db->prepare("DELETE FROM `done` WHERE received_time <= NOW() - INTERVAL :days day AND grading_start_time <= NOW() - INTERVAL :days day;");
  $stmt->execute(array(':days' => $CFG_keep_old_days));
  $stmt = $db->prepare("DELETE FROM `log` WHERE datetime <= NOW() - INTERVAL :days day;");
  $stmt->execute(array(':days' => $CFG_keep_old_days));

  # Delete orphan job_types
  $db->query("DELETE FROM `job_types` WHERE NOT EXISTS (SELECT 1 FROM `queue` WHERE `job_types`.`jobid`=`queue`.`id`);");
}

# Warn about tasks in error / stuck
$stmt = $db->prepare("
    SELECT COUNT(*) FROM `queue`
    WHERE received_time >= NOW() - INTERVAL 5 minute
    AND received_time <= NOW() - INTERVAL :seconds second
    AND (status = 'queued' OR status = 'error');");
$stmt->execute(array(':seconds' => $CFG_warn_seconds));
$row = $stmt->fetch();
if($row[0] > $CFG_warn_nb) {
  $msg = strtr("
Hi,

There are :count tasks stuck or in error in the queue.
Please check :url.

Cheers,

--
graderqueue", array(':count' => $row[0], ':url' => $CFG_interface_url));
  if(gettype($CFG_admin_email) == 'array') {
    foreach($CFG_admin_email as $recipient) {
      mail($recipient, "[graderqueue] Tasks in error", $msg);
    }
  } else {
    mail($CFG_admin_email, "[graderqueue] Tasks in error", $msg);
  }
}

# Try waking-up servers again
wake_up_server_by_error();

if($hourly) {
  # Warn about servers not waking-up
  $stmt = $db->query("SELECT name, wakeup_fails, last_poll_time FROM `servers` WHERE wakeup_fails >= 3;");
  $wakeup_error_servers = '';
  while($row = $stmt->fetch()) {
    $wakeup_error_servers .= $row['name'] . "(" . $row['wakeup_fails'] . " failures, last poll " . $row['last_poll_time'] . ")\n";
  }
  if($wakeup_error_servers != '') {
    $msg = strtr("
Hi,

The following servers haven't woken up after a few tries:
:errorserv
Please check :url.

Cheers,

--
graderqueue", array(':errorserv' => $wakeup_error_servers, ':url' => $CFG_interface_url));
    if(gettype($CFG_admin_email) == 'array') {
      foreach($CFG_admin_email as $recipient) {
        mail($recipient, "[graderqueue] Servers not waking-up", $msg);
      }
    } else {
      mail($CFG_admin_email, "[graderqueue] Servers not waking-up", $msg);
    }
  }
}
?>
