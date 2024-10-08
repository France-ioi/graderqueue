<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require_once __DIR__."/../vendor/autoload.php";

use Aws\AutoScaling\AutoScalingClient;
use Aws\CloudWatch\CloudWatchClient;
use Jose\Factory\JWKFactory;
use Jose\Loader;
use Jose\Factory\JWSFactory;
use Jose\Factory\JWEFactory;

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
  $stmt = $db->prepare("INSERT INTO `log` (datetime, log_type, job_id, server_id, message) VALUES(NOW(), :type, :jobid, :sid, :msg)");
  return $stmt->execute(array(':type' => $log_type, ':jobid' => $job_id, ':sid' => $server_id, ':msg' => $message));
}

function connect_pdo($hostname, $database, $user, $password) {
   // computing timezone difference with gmt:
   // http://www.sitepoint.com/synchronize-php-mysql-timezone-configuration/
   $now = new DateTime();
   $mins = $now->getOffset() / 60;
   $sgn = ($mins < 0 ? -1 : 1);
   $mins = abs($mins);
   $hrs = floor($mins / 60);
   $mins -= $hrs * 60;
   $offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);
   try {
      $pdo_options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
      $pdo_options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8";
      $connexionString = "mysql:host=".$hostname.";dbname=".$database.";charset=utf8";
      $db = new PDO($connexionString, $user, $password, $pdo_options);
      $db->exec("SET time_zone='".$offset."';");
   } catch (Exception $e) {
      die("Erreur : " . $e->getMessage());
   }
   return $db;
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

function auth_graderserver_by_token() {
  # Authenticate a graderserver from its token
  global $db;

  if(!isset($_POST['server_token'])) {
    return NULL;
  }

  $ip = $_SERVER['REMOTE_ADDR'];

  $stmt = $db->prepare("SELECT * FROM server_tokens WHERE token = :token");
  $stmt->execute(['token' => $_POST['server_token']]);
  $tokenData = $stmt->fetch();
  if(!$tokenData) { return NULL; }

  $stmt = $db->prepare("SELECT * FROM servers WHERE token_id = :tokenId AND ip = :ip");
  $stmt->execute(['tokenId' => $tokenData['id'], 'ip' => $ip]);
  $serverData = $stmt->fetch();
  if($serverData) { return $serverData; }

  # We need to register this server
  $servdata = [
    'name' => $tokenData['name'] . '-' . $ip,
    'token_id' => $tokenData['id'],
    'ip' => $ip,
    'wakeup_url' => 'udp://' . $ip . ':20000',
    'type' => $tokenData['type'],
    'max_concurrent_jobs' => $tokenData['max_concurrent_jobs']
    ];
  $stmt = $db->prepare("INSERT INTO servers (name, token_id, ip, wakeup_url, type, max_concurrent_jobs) VALUES(:name, :token_id, :ip, :wakeup_url, :type, :max_concurrent_jobs)");
  $stmt->execute($servdata);
  $servdata['id'] = $db->lastInsertId();
  return $servdata;
}

function wake_up_server_by_type($typeids = array(), $strat = 'default', $secondtry = false) {
  # Wake up a server if needed
  # If secondtry is true, we try to wake up any matching server

  global $db;

  # Try to wake up as many different servers as the queue is long
  $queueLength = $db->query("SELECT COUNT(`queue`.`id`) FROM `queue` WHERE `status` != 'sent';")->fetchColumn();

  $query = "
    SELECT servers.*,
      COUNT(queue.id) AS nbjobs,
      TIMESTAMPDIFF(SECOND, servers.last_poll_time, NOW()) AS last_poll_ago
    FROM `servers`
    LEFT JOIN queue ON queue.sent_to=servers.id AND queue.status='sent'
    WHERE wakeup_fails=0";
  if(count($typeids) > 0) {
    $query .= " AND servers.type IN (" . implode(',', $typeids) . ")";
  }
  $query .= " GROUP BY servers.id";

  if($strat == 'last') {
    // Try to get all servers to work equally
    $query .= " ORDER BY last_poll_time ASC, nbjobs ASC;";
  } else {
    // default strat 'first'
    $query .= " ORDER BY nbjobs DESC, last_poll_time DESC;";
  }

  $res = $db->query($query);
  while($row = $res->fetch()) {
    if($row['nbjobs'] < $row['max_concurrent_jobs'] || $secondtry)
    {
      # There's already one server polling which will take this new task
      if($row['last_poll_ago'] < 18 && !$secondtry) {
        $queueLength--;
        if($queueLength <= 0) {
          return True;
        } else {
          continue;
        }
      }

      # Need to wake this server up
      if(wake_up($row['wakeup_url'])) {
        $stmt = $db->prepare("UPDATE `servers` SET wakeup_fails=0 WHERE id=:sid;");
        $stmt->execute(array(':sid' => $row['id']));
        $queueLength--;
        if($queueLength <= 0) {
          return True;
        } else {
          continue;
        }
      } else {
        $stmt = $db->prepare("UPDATE `servers` SET wakeup_fails=wakeup_fails+1 WHERE id=:sid;");
        $stmt->execute(array(':sid' => $row['id']));
      } # If failed we'll try the next server
    }
  }

  if(!$secondtry) {
    # Try again, but this time we wake up any matching server, starting with
    # the idle ones
    return wake_up_server_by_type($typeids, 'last', true);
  } else {
    return False;
  }
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

function wake_up_server_by_error() {
  global $db;

  $stmt = $db->prepare("SELECT id, wakeup_url FROM `servers` WHERE wakeup_fails > 0;");
  $stmt->execute();
  while($row = $stmt->fetch()) {
    if(wake_up($row['wakeup_url'])) {
      $stmt2 = $db->prepare("UPDATE `servers` SET wakeup_fails=0 WHERE id=:sid;");
      $stmt2->execute(array(':sid' => $row['id']));
    } else {
      $stmt2 = $db->prepare("UPDATE `servers` SET wakeup_fails=wakeup_fails+1 WHERE id=:sid;");
      $stmt2->execute(array(':sid' => $row['id']));
    }
  }
}

function wake_up($url) {
  if(($fs = fsockopen($url, -1, $errno, $errstr, 1)) !== False)
  {
    stream_set_timeout($fs, 2);
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
  $privateKey = JWKFactory::createFromKey($CFG_private_key, null, array('kid' => $CFG_key_name));
  try {
    $loader = new Loader();

    $jws = $loader->loadAndDecryptUsingKey(
        $_POST['sToken'],
        $privateKey,
        ['RSA-OAEP-256'],
        ['A256CBC-HS512'],
        $recipient_index
    )->getPayload();

    $params = $loader->loadAndVerifySignatureUsingKey(
        $jws,
        $publicKey,
        ['RS512'],
        $signature_index
    )->getPayload();
  } catch (Exception $e) {
    die(jsonerror(2, "Invalid token: ".$e->getMessage()));
  }
  return array($platform, $params);
}

function encode_params_in_token($params, $platform) {
  global $CFG_private_key, $CFG_key_name;
  $params['date'] = date('d-m-Y');

  $publicKey = JWKFactory::createFromKey($platform['public_key'], null, array('kid' => $platform['name']));
  $privateKey = JWKFactory::createFromKey($CFG_private_key, null, array('kid' => $CFG_key_name));

  $jws = JWSFactory::createJWSToCompactJSON($params, $privateKey, ['alg' => 'RS512']);

  return JWEFactory::createJWEToCompactJSON(
      $jws,
      $publicKey,
      [
          'alg' => 'RSA-OAEP-256',
          'enc' => 'A256CBC-HS512',
          'zip' => 'DEF',
      ]
  );
}



function extractTaskStat($jobdata, $resultdata) {
    $res = array(
        ':cpu_time_ms' => 0,
        ':real_time_ms' => 0,
        ':max_real_time_ms' => 0,
        ':is_success' => 1,
        ':task_path' => $jobdata['taskPath'],
        ':task_name' => basename($jobdata['taskPath']),
        ':language' => $jobdata['extraParams']['solutionLanguage']
    );

    foreach($resultdata['jobdata']['executions'] as $execution) {
        foreach($execution['testsReports'] as $report) {
            //var_dump($report['checker']);die();
            if(isset($report['checker']) && intval(trim($report['checker']['stdout']['data'])) != 100) {
                $res[':is_success'] = 0;
            }
            $res[':cpu_time_ms'] +=
                (isset($report['execution']) ? intval($report['execution']['timeTakenMs']) : 0) +
                (isset($report['checker']) ? intval($report['checker']['timeTakenMs']) : 0) +
                (isset($report['sanitizer']) ? intval($report['sanitizer']['timeTakenMs']) : 0);
            $res[':real_time_ms'] +=
                (isset($report['execution']) ? intval($report['execution']['realTimeTakenMs']) : 0) +
                (isset($report['checker']) ? intval($report['checker']['realTimeTakenMs']) : 0) +
                (isset($report['sanitizer']) ? intval($report['sanitizer']['realTimeTakenMs']) : 0);

            if(isset($report['execution'])) {
                $res[':max_real_time_ms'] = max($res[':max_real_time_ms'], intval($report['execution']['timeTakenMs']));
            }
        }
    }
    return $res;
}



// old interface
function make_pages_selector($curpage, $nbpages) {
  // Make the pages selector for interface.php, shown before and after each
  // "big" table
  if($nbpages == 1) return '';

  $realpage = max(1, min($curpage, $nbpages));

  $html = "<div>Pages: ";
  if($realpage <= 1) {
    $html .= "<b>&lt;&lt; First</b>&nbsp;&lt; Prev&nbsp;";
  } else {
    $html .= "<a href=\"interface.php\">&lt;&lt; First</a>&nbsp;<a href=\"interface.php?page=" . ($realpage-1) . "\">&lt; Prev</a>&nbsp;";
  }
  $html .= "[Page $realpage/$nbpages]&nbsp;";
  if($realpage >= $nbpages) {
    $html .= "Next &gt;&nbsp;<b>Last &gt;&gt;</b>&nbsp";
  } else {
    $html .= "<a href=\"interface.php?page=" . ($realpage+1) . "\">Next &gt;</a>&nbsp;<a href=\"interface.php?page=$nbpages\">Last &gt;&gt;</a>&nbsp;";
  }
  $html .= "</div>";
  return $html;
}


function autoscaling_get_client() {
  global $CFG_aws_credentials, $autoscaling_client;
  if(!isset($autoscaling_client)) {
    $autoscaling_client = AutoScalingClient::factory(array_merge($CFG_aws_credentials, ['version' => "2011-01-01"]));
  }
  return $autoscaling_client;
}

function cloudwatch_get_client() {
  global $CFG_aws_credentials, $cloudwatch_client;
  if(!isset($cloudwatch_client)) {
    $cloudwatch_client = CloudWatchClient::factory(array_merge($CFG_aws_credentials, ['version' => "2010-08-01"]));
  }
  return $cloudwatch_client;
}

function autoscaling_get_infos() {
  global $CFG_aws_autoscaling_group;
  $client = autoscaling_get_client();
  $desc = $client->describeAutoScalingGroups(['AutoScalingGroupNames' => [$CFG_aws_autoscaling_group]])['AutoScalingGroups'][0];
  return [
    'min' => $desc['MinSize'],
    'max' => $desc['MaxSize'],
    'cur' => $desc['DesiredCapacity']
    ];
}

function autoscaling_set_desired($new) {
  global $CFG_aws_autoscaling_group;
  $client = autoscaling_get_client();
  //try {
    $client->setDesiredCapacity([
      'AutoScalingGroupName' => $CFG_aws_autoscaling_group,
      'DesiredCapacity' => $new,
      'HonorCooldown' => true
      ]);
  //} catch(Exception $e) {}
}

function autoscaling_get_instances() {
  global $CFG_aws_autoscaling_group;
  $client = autoscaling_get_client();
  $instances = $client->describeAutoScalingInstances()['AutoScalingInstances'];
  $ret = [];
  foreach($instances as $instance) {
    if($instance['AutoScalingGroupName'] == $CFG_aws_autoscaling_group && $instance['LifecycleState'] == 'InService') {
      $ret[] = $instance;
    }
  }
  return $ret;
}

function autoscaling_get_instance_metrics() {
  global $CFG_aws_autoscaling_group;
  $client = cloudwatch_get_client();
  $instances = autoscaling_get_instances();
  $zones = [];
  $metricDataQueries = [];
  foreach($instances as $instance) {
    $zones[$instance['InstanceId']] = $instance['AvailabilityZone'];

    $metricDataQueries[] = [
      'Id' => 'credit_' . str_replace('-', '_', $instance['InstanceId']),
      'MetricStat' => [
        'Metric' => [
          'Namespace' => 'AWS/EC2',
          'MetricName' => 'CPUCreditBalance',
          'Dimensions' => [
            [
              'Name' => 'InstanceId',
              'Value' => $instance['InstanceId']
            ]
          ]
        ],
        'Period' => 300,
        'Stat' => 'Average'
      ]
    ];
    $metricDataQueries[] = [
      'Id' => 'surplus_' . str_replace('-', '_', $instance['InstanceId']),
      'MetricStat' => [
        'Metric' => [
          'Namespace' => 'AWS/EC2',
          'MetricName' => 'CPUSurplusCreditBalance',
          'Dimensions' => [
            [
              'Name' => 'InstanceId',
              'Value' => $instance['InstanceId']
            ]
          ]
        ],
        'Period' => 300,
        'Stat' => 'Average'
      ]
    ];
  }
  $stats = $client->getMetricData([
    'MetricDataQueries' => $metricDataQueries,
    'StartTime' => (new DateTime())->sub(new DateInterval('PT300S'))->format('c'),
    'EndTime' => (new DateTime())->format('c'),
    'ScanBy' => 'TimestampDescending'
  ])['MetricDataResults'];

  $ret = [];
  foreach($stats as $stat) {
    $type = explode('_', $stat['Id'])[0];
    $instanceId = str_replace('_', '-', explode('_', $stat['Id'], 2)[1]);
    if(!isset($ret[$instanceId])) {
      $ret[$instanceId] = ['instanceId' => $instanceId];
    }
    $ret[$instanceId][$type] = isset($stat['Values'][0]) ? $stat['Values'][0] : 1;
    $ret[$instanceId]['zone'] = $zones[$instanceId];
  }
  usort($ret, function($a, $b) {
    return $a['credit'] - $b['credit'];
  });
  return $ret;
}

function autoscaling_terminate_instances($instanceIds) {
  global $CFG_aws_autoscaling_group;
  $client = autoscaling_get_client();
  foreach($instanceIds as $instanceId) {
    $client->terminateInstanceInAutoScalingGroup([
      'InstanceId' => $instanceId,
      'ShouldDecrementDesiredCapacity' => true
    ]);
  }
}

function autoscaling_get_stoppable($toBeStopped) {
  $instances = autoscaling_get_instance_metrics();
  
  $zoneCounts = [];
  $stoppableByZone = [];
  foreach($instances as $instance) {
    $zone = $instance['zone'];
    if(!isset($zoneCounts[$zone])) {
      $zoneCounts[$zone] = 0;
      $stoppableByZone[$zone] = [];
    }
    $zoneCounts[$zone]++;
    if($instance['surplus'] > 0 || $instance['credit'] < 3) {
      continue;
    }
    $stoppableByZone[$zone][] = $instance['instanceId'];
  }
  
  $stoppable = [];
  while(count($stoppable) < $toBeStopped) {
    $maxZone = null;
    $maxCount = 0;
    foreach($zoneCounts as $zone => $count) {
      if($count > $maxCount) {
        $maxZone = $zone;
        $maxCount = $count;
      }
    }
    if(!$maxZone) {
      break;
    }
    if(count($stoppableByZone[$maxZone]) > 0) {
      $stoppable[] = array_shift($stoppableByZone[$maxZone]);
      $zoneCounts[$maxZone]--;
    } else {
      break;
    }
  }
  return $stoppable;
}
  
?>
