<?php

require_once __DIR__.'/config.inc.php';
require_once __DIR__.'/funcs.inc.php';

$timepoint = date("o-m-d H:i:s");

error_reporting(E_ALL);

function usage_per_minute($minutes) {
    global $db, $timepoint;
    $stmt = $db->prepare("SELECT SUM(TIMESTAMPDIFF(SECOND, grading_start_time, grading_end_time)+1) FROM done WHERE grading_end_time >= :time - INTERVAL :interval MINUTE AND grading_end_time < :time");
    $stmt->execute(['time' => $timepoint, 'interval' => $minutes]);
    $ret = $stmt->fetchColumn();
    if(!$ret) { $ret = 0; }
    return $ret / $minutes / 60;
}

$desired_servers =
    ceil(
        max(
            usage_per_minute(1) * $CFG_autoscaling_ratio_burst,
            max(usage_per_minute(5), usage_per_minute(15)) * $CFG_autoscaling_ratio_long
        ));

// Stay within limits
$infos = autoscaling_get_infos();
$desired_servers = max($infos['min'], min($infos['max'], $desired_servers));

if($desired_servers > $infos['cur']) {
    // Need to increase
    echo "Increasing to $desired_servers\n";
    autoscaling_set_desired($desired_servers);
    $stmt = $db->prepare("INSERT INTO autoscaling (date, desired) VALUES(NOW(), :cur);");
    $stmt->execute(['cur' => $desired_servers]);
} else if($desired_servers < $infos['cur']) {
    // Can reduce
    $stmt = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, date, NOW()) FROM autoscaling ORDER BY ID DESC LIMIT 1;");
    $stmt->execute();
    $last_scaling = $stmt->fetchColumn();
    echo "Reducing to $desired_servers...";
    if(!$last_scaling || $last_scaling > $CFG_autoscaling_minutes_downscale * 60) {
        // Enough time happened since last auto-scaling action
        $toBeStopped = $infos['cur'] - $desired_servers;
        $stoppable = autoscaling_get_stoppable($toBeStopped);

        autoscaling_terminate_instances($stoppable);

        $new_cur = $infos['cur'] - count($stoppable);

        $stmt = $db->prepare("INSERT INTO autoscaling (date, desired) VALUES(NOW(), :cur);");
        $stmt->execute(['cur' => $new_cur]);
        echo " reduced to $new_cur.\n";
    } else {
        echo " canceled, not enough time passed.\n";
    }
}
