<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require_once "../vendor/autoload.php";

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
    $typeq = $db->prepare("SELECT typeid, COUNT(*) AS nb FROM type_tags WHERE tagid IN (" . implode(',', $tagids) . ") GROUP BY typeid HAVING nb=" . count($tagids) . ";");
    $typeq->execute();
    while($row = $typeq->fetch()) {
      $typeids[] = $row['typeid'];
    }
  }
  return $typeids;
}

function get_token_client_info() {
  # Returns the database row about a platform identified by the JWE token + the token data
  # Returns NULL if identification failed

  global $db, $CFG_private_key, $CFG_key_name;

  if (!isset($_POST['sToken']) || !$_POST['sToken'] || !isset($_POST['sPlatform']) || !$_POST['sPlatform']) {
    return NULL;
  }

  // get all keys from platforms, to see if one fits
  $stmt = $db->prepare("SELECT * FROM `platforms` where name = :sPlatform;");
  $stmt->execute(array('sPlatform' => $_POST['sPlatform']));
  $platform = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$platform) {
    return null;
  }

  // basic jwe configuration (must be the same on both sides!)
  $jose = SpomkyLabs\Service\Jose::getInstance();
  $jose->getConfiguration()->set('Compression', array('DEF'));
  $jose->getConfiguration()->set('Algorithms', array(
    'A256CBC-HS512',
    'RSA-OAEP-256',
    'RS512'
  ));

  // actually decrypting token
  $jose->getKeyManager()->addRSAKeyFromOpenSSLResource($platform['name'], openssl_pkey_get_public($platform['public_key']));
  $jose->getKeyManager()->addRSAKeyFromOpenSSLResource($CFG_key_name, openssl_pkey_get_private($CFG_private_key));
  try {
    $jws = $jose->load($_POST['sToken'])->getPayload();
    $params = $jose->load($jws)->getPayload();
  } catch (Exception $e) {
    die(jsonerror(2, "Invalid token."));
  }
  return array($platform, $params);
}

function encode_params_in_token($params, $platform) {
  global $CFG_private_key, $CFG_key_name;
  $params['date'] = date('d-m-Y');
  // basic jwe configuration (must be the same on both sides!)
  $jose = SpomkyLabs\Service\Jose::getInstance();
  $jose->getConfiguration()->set('Compression', array('DEF'));
  $jose->getConfiguration()->set('Algorithms', array(
    'A256CBC-HS512',
    'RSA-OAEP-256',
    'RS512'
  ));

  // actually encrypting token
  $jose->getKeyManager()->addRSAKeyFromOpenSSLResource($platform['name'], openssl_pkey_get_public($platform['public_key']));
  $jose->getKeyManager()->addRSAKeyFromOpenSSLResource($CFG_key_name, openssl_pkey_get_private($CFG_private_key));
  $jws = $jose->sign(
    $CFG_key_name,
    $params,
    array(
      "alg" => "RS512",
      "kid" => $CFG_key_name,
    )
  );
  $jwe = $jose->encrypt($platform['name'], $jws, array(
      'alg' => 'RSA-OAEP-256',
      'enc' => 'A256CBC-HS512',
      'kid' => $platform['name'],
      'zip' => 'DEF',
  ));
  return $jwe;
}
