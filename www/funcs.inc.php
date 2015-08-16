<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

function deltatime($start, $end) {
  # Returns a string corresponding to the time delta between two datetimes
  $delta = strtotime($end) - strtotime($start);
  if($delta < 5*60) {
    return $delta . " seconds";
  } elseif($delta < 2*60*60) {
    return floor($delta/60) . " minutes";
  } else {
    return floor($delta/(60*60)) . " hours";
  }
}


function jsonerror($code, $msg) {
  # Returns a success or an error as a json
  # An error code of 0 means success.
  # An error code of 1 means a temporary error, 2 means a fatal error.
  return json_encode(array('errormsg' => $msg, 'errorcode' => $code));
}


function db_log($log_type, $task_id, $server_id, $message) {
  # Adds a log line to the database table 'log'
  global $db;
  $stmt = $db->prepare("INSERT INTO `log` (date, log_type, task_id, server_id, message) VALUES(:type, :taskid, :sid, :msg)");
  return $stmt->execute(array(':type' => $log_type, ':taskid' => $task_id, ':sid' => $server_id, ':msg' => $message));
}


function get_ssl_client_info($table) {
  # Returns the database row about a client identified by his SSL cert
  # table must be one of 'platforms' or 'servers'
  # Returns NULL if identification failed

  global $db;

  if($table != 'platforms' && $table != 'servers') {
    throw new Exception("`$table` invalid for get_ssl_client_info.");
  }

  if(isset($_SERVER['SSL_CLIENT_VERIFY']) && $_SERVER['SSL_CLIENT_VERIFY'] == 'SUCCESS') {
    # Client certificate valid, fetching information from the database
    $stmt = $db->prepare("SELECT * FROM $table WHERE ssl_serial=:serial AND ssl_dn=:dn");
    $stmt->execute(array(':serial' => $_SERVER['SSL_CLIENT_M_SERIAL'], ':dn' => $_SERVER['SSL_CLIENT_I_DN']));

    # If no row was found, returns NULL
    return $stmt->fetch();
  } else {
    return NULL; # Client certificate was invalid or non-present
  }
}


function wake_up_server($typeids = array()) {
  # Wake up a server if needed

  global $db;

  $query = "
    SELECT servers.*,
      COUNT(queue.id) AS nbtasks
    FROM `servers`
    LEFT JOIN queue ON queue.sent_to=servers.id";
  if(count($typeids) > 0) {
    $query .= " WHERE type IN (" . implode(',', $typeids) . ")";
  }
  $query .= " GROUP BY servers.id
    ORDER BY nbtasks DESC, last_poll_time DESC;";
  $res = $db->query($query);
  while($row = $res->fetch()) {
    if(!($row['nbtasks'] < $row['max_concurrent_tasks'] or time()-strtotime($row['last_poll_time'] < 60)))
    {
      # Need to wake this server up
      if(($fs = fsockopen($row['wakeup_url'])) !== False)
      {
        fwrite($fs, ' ');
        fclose($fs);
        return True;
      }
      # If failed we'll try the next server
    }
  }
  return False;
}


function tags_to_tagids($tags) {
  # Convert tag names list to list of tag IDs

  global $db;

  $tagids = array();
  if($tags != '')
  {
    $taglist = explode(',', $tags);

    # Fetch each tag
    foreach($taglist as $t) {
      # We make slow requests to have meaningful error messages
      $stmt = $db->prepare("SELECT id FROM tags WHERE name = :name;");
      $stmt->execute(array(':name' => $t));
      $tagq = $stmt->fetch();
      if($tagq) {
        $tagids[] = $tagq[0];
      } else {
        die(jsonerror(2, "Tag `" . $t . "` unrecognized."));
      }
    }
  }
  return $tagids;
}


function tagids_to_typeids($tagids) {
  # Fetch all server types which can execute with these tags

  global $db;

  $typeids = array();
  if(count($tagids) > 0) {
    # Data is safe, and a prepared statement would be too complicated
    $typeq = $db->query("SELECT typeid, COUNT(*) AS nb FROM type_tags WHERE tagid IN (" . implode(',', $tagids) . ") GROUP BY typeid HAVING nb=" . count($tagids) . ";");
    while($row = $typeq->fetch()) {
      $typeids[] = $row['typeid'];
    }
  }
  return $typeids;
}



?>
