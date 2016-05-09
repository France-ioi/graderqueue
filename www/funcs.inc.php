<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require_once __DIR__."/../vendor/autoload.php";

use Jose\Factory\VerifierFactory;
use Jose\Factory\JWKFactory;
use Jose\Loader;
use Jose\Object\JWKSet;
use Jose\Factory\SignerFactory;
use Jose\Factory\JWSFactory;

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


function db_log($log_type, $job_id, $server_id, $message) {
  # Adds a log line to the database table 'log'
  global $db;
  $stmt = $db->prepare("INSERT INTO `log` (date, log_type, job_id, server_id, message) VALUES(:type, :jobid, :sid, :msg)");
  return $stmt->execute(array(':type' => $log_type, ':jobid' => $job_id, ':sid' => $server_id, ':msg' => $message));
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

function wake_up_server_by_type($typeids = array()) {
  # Wake up a server if needed

  global $db;

  $query = "
    SELECT servers.*,
      COUNT(queue.id) AS nbjobs
    FROM `servers`
    LEFT JOIN queue ON queue.sent_to=servers.id AND queue.status='sent'";
  if(count($typeids) > 0) {
    $query .= " WHERE servers.type IN (" . implode(',', $typeids) . ")";
  }
  $query .= " GROUP BY servers.id
    ORDER BY nbjobs DESC, last_poll_time DESC;";
  $res = $db->query($query);
  while($row = $res->fetch()) {
    if($row['nbjobs'] < $row['max_concurrent_jobs'] or (time()-strtotime($row['last_poll_time'])) > 20)
    {
      # Need to wake this server up
      if(wake_up($row['wakeup_url'])) {
        $stmt = $db->prepare("UPDATE `servers` SET wakeup_fails=0 WHERE id=:sid;");
        $stmt->execute(array(':sid' => $row['id']));
        return True;
      } else {
        $stmt = $db->prepare("UPDATE `servers` SET wakeup_fails=wakeup_fails+1 WHERE id=:sid;");
        $stmt->execute(array(':sid' => $row['id']));
      } # If failed we'll try the next server
    }
  }
  return False;
}

function wake_up_server_by_id($sid) {
  global $db;

  $stmt = $db->prepare("SELECT wakeup_url FROM `servers` WHERE id=:sid;");
  $stmt->execute(array(':sid' => $sid));
  if($row = $stmt->fetch()) {
    if(wake_up($row['wakeup_url'])) {
      $stmt = $db->prepare("UPDATE `servers` SET wakeup_fails=0 WHERE id=:sid;");
      $stmt->execute(array(':sid' => $sid));
      return True;
    } else {
      $stmt = $db->prepare("UPDATE `servers` SET wakeup_fails=wakeup_fails+1 WHERE id=:sid;");
      $stmt->execute(array(':sid' => $sid));
      return False;
    }
  } else {
    return False;
  }
}

function wake_up($url) {
  if(($fs = fsockopen($url)) !== False)
  {
    fwrite($fs, 'wakeup');
    $answer = fread($fs, 1024);
    fclose($fs);
    return ($answer == 'ok');
  } else {
    return False;
  }
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

  // actually decrypting token
  $publicKey = JWKFactory::createFromKey($platform['public_key'], null, array('kid' => $platform['name']));
  try {
    $res = Loader::load($_POST['sToken']);
    $verifier = VerifierFactory::createVerifier(['RS512']);
    $valid_signature = $verifier->verifyWithKey($res, $publicKey);
    if ($valid_signature === false) {
       throw new Exception('Signature cannot be validated, please check your SSL keys');
    }
    $params = $res->getPayload();
  } catch (Exception $e) {
    die(jsonerror(2, "Invalid token: ".$e->getMessage()));
  }
  return array($platform, $params);
}

function encode_params_in_token($params, $platform) {
  global $CFG_private_key, $CFG_key_name;
  $params['date'] = date('d-m-Y');

  $privateKey = JWKFactory::createFromKey($CFG_private_key, null, array('kid' => $CFG_key_name));

  $jws = JWSFactory::createJWS($params);
  $signer = SignerFactory::createSigner(['RS512']);
  $signer->addSignature(
     $jws,
     $privateKey,
     ['alg' => 'RS512']
  );
  return $jws->toCompactJSON(0);
}
