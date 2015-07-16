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

function getclientinfo($table) {
  # Returns the database row about a client identified by his SSL cert
  # table must be one of 'platforms' or 'servers'
  # Returns NULL if identification failed

  global $db;

  if($table != 'platforms' && $table != 'servers') {
    throw new Exception("`$table` invalid for getclientinfo.");
  }

  if(isset($_SERVER['SSL_CLIENT_VERIFY']) && $_SERVER['SSL_CLIENT_VERIFY'] == 'SUCCESS') {
    # Client certificate valid, fetching information from the database
    $serial = $_SERVER['SSL_CLIENT_M_SERIAL'];
    $dn = $_SERVER['SSL_CLIENT_I_DN'];

    $stmt = $db->prepare("SELECT * FROM $table WHERE ssl_serial=? AND ssl_dn=?");
    $stmt->bind_param('ss', $serial, $dn);
    $stmt->execute();

    # If no row was found, returns NULL
    return $stmt->get_result()->fetch_assoc();
  } else {
    return NULL; # Client certificate was invalid or non-present
  }
}
?>
