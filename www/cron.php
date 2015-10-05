<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require __DIR__.'/config.inc.php';

# Delete old tasks and logs
$stmt = $db->prepare("DELETE FROM `tasks` WHERE received_time <= NOW() - INTERVAL :days day AND sent_time <= NOW() - INTERVAL :days day);");
$stmt->execute(array(':days' => $CFG_keep_old_days));
$stmt = $db->prepare("DELETE FROM `done` WHERE received_time <= NOW() - INTERVAL :days day AND sent_time <= NOW() - INTERVAL :days day;");
$stmt->execute(array(':days' => $CFG_keep_old_days));
$stmt = $db->prepare("DELETE FROM `log` WHERE datetime <= NOW() - INTERVAL :days day;");
$stmt->execute(array(':days' => $CFG_keep_old_days));

# Warn about tasks in error / stuck
$stmt = $db->prepare("
    SELECT COUNT(*) FROM `queue`
    WHERE received_time >= NOW() - INTERVAL :hours hour - INTERVAL :cronintv hour
    AND received_time <= NOW() - INTERVAL :hours hour
    AND (status = 'queued' OR status = 'error');");
$stmt->execute(array(':hours' => $CFG_warn_hours, ':cronintv' => $CFG_cron_interval_hours));
$row = $stmt->fetch();
if($row[0] > 0) {
  mail($CFG_admin_email, "[graderqueue] Tasks in error", strtr("
Hi,

There are :count tasks stuck or in error in the queue.
Please check :url.

Cheers,

-- 
graderqueue", array(':count' => $row[0], ':url' => $CFG_interface_url)));
}
?>
