<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("config.inc.php");

# Token for the API
$token = md5(microtime());

$stmt = $db->prepare("INSERT INTO `tokens` VALUES(:token, NOW()+INTERVAL 10 MINUTE);");
$stmt->execute(array(':token' => $token));

$tid = 0;
?>
<html>
<head>
  <title>graderqueue interface</title>
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.4/jquery.min.js"></script>
  <style type="text/css">
    .tooltip {
      border-bottom: 1px dotted #000000; color: #000000; outline: none;
      cursor: help; text-decoration: none;
      position: relative;
    }
  </style>
</head>
<body>
<a name="form" />
<h2>Send task</h2>
<div>
<form enctype="multipart/form-data" action="api.php" id="taskSend">
Solution : <input type="file" name="solfile" /> <i>or</i> path <input type="text" name="solpath" /> <i>or</i> <a onclick="$('#solcontentarea').toggle();" href="#form">content</a><br />
<span id="solcontentarea" style="display:none;"><textarea id="solcontent" name="solcontent"></textarea><br /></span>
Task path : <input type="text" name="taskpath" size="150" value="$ROOT_PATH/FranceIOI/Contests/..." /><br />
Memory limit (KB) : <input type="text" name="memlimit" value="131072" /><br />
Time limit (ms) : <input type="text" name="timelimit" value="60000" /><br />
Language : <input type="text" name="lang" value="c" /><br />
Priority : <input type="text" name="priority" value="10" /><br />
Tags : <input type="text" name="tags" value="" /><br />
Token : <input type="text" name="token" value="<?=$token ?>" />
<input type="submit" value="Submit" />
</form></div>
<div>Test tasks :
<?php
$bid = 0;
$buttonsData = "";
foreach($CFG_buttons as $bname => $bdata) {
  $bmergeddata = array_merge($CFG_defaultbutton, $bdata);
  echo " <button type=\"button\" onclick=\"sendPath(" . $bid . ")\">" . $bname . "</button>";
  $buttonsData .= "buttonsData[" . $bid . "] = {";
  $buttonsData .= "request: \"sendsolution\",";
  foreach($bmergeddata as $idx => $val) {
    $buttonsData .= $idx . ": \"" . $val . "\",";
  }
  $buttonsData .= "};\n";
  $bid += 1;
}
?>
</div>
<div id="taskSendResult"></div>
<?php
echo "<h2>Servers</h2>";
echo "<table border=1><tr><td><b>id</b></td><td><b>name</b></td><td><b>status</b></td><td><b>ssl_serial</b></td><td><b>ssl_dn</b></td><td><b>wakeup_url</b></td><td><b>type</b></td><td><b>tasks</b></td><td><b>last_poll_time</b></td></tr>";
$res = $db->query("
  SELECT servers.*,
    server_types.name AS typename,
    GROUP_CONCAT(tags.name SEPARATOR ',') AS tags,
    COUNT(queue.id) AS nbtasks
  FROM `servers`
  LEFT JOIN type_tags ON type_tags.typeid=servers.type
  LEFT JOIN tags ON type_tags.tagid=tags.id
  LEFT JOIN server_types ON server_types.id=servers.type
  LEFT JOIN queue ON queue.sent_to=servers.id
  GROUP BY servers.id
  ORDER BY servers.id ASC;");
while($row = $res->fetch()) {
  echo "<tr>";
  echo "<td>" . $row['id'] . "</td>";
  echo "<td>" . $row['name'] . "</td>";
  if($row['nbtasks'] > 0) {
    echo "<td><font color=\"darkorange\">busy (" . $row['nbtasks'] . " tasks)</font></td>";
  } elseif(time()-strtotime($row['last_poll_time']) > 60) {
    echo "<td><font color=\"darkblue\">sleeping <i>(" . deltatime($row['last_poll_time'], 'now') . ")</i></font></td>";
  } else {
    echo "<td><font color=\"darkgreen\">polling</font></td>";
  }
  echo "<td>" . $row['ssl_serial'] . "</td>";
  echo "<td>" . $row['ssl_dn'] . "</td>";
  echo "<td>" . $row['wakeup_url'] . "</td>";
  echo "<td>#" . $row['type'] . " : <span class=\"tooltip\" title=\"supports tags " . $row['tags'] . "\">" . $row['typename'] . "</span></td>";
  echo "<td>" . $row['nbtasks'] . " / " . $row['max_concurrent_tasks'] . "</td>";
  echo "<td>" . $row['last_poll_time'] . "</td>";
  echo "</tr>";
}

echo "</table>";
echo "<h2>Tasks done</h2>";
echo "<table border=1><tr><td><b>id</b></td><td><b>name</b></td><td><b>priority</b></td><td><b>timeout_sec</b></td><td><b>servers</b></td><td><b>times</b></td><td><b>summary</b></td><td><b>taskdata</b></td><td><b>resultdata</b></td></tr>";

$res = $db->query("SELECT * FROM `done` ORDER BY done_time DESC;");
while($row = $res->fetch()) {
  echo "<tr>";
  echo "<td>" . $row['id'] . "</td>";
  echo "<td>" . $row['name'] . "</td>";
  echo "<td>" . $row['priority'] . "</td>";
  echo "<td>" . $row['timeout_sec'] . "s<br />";
  if($row['nb_fails'] > 0)
  {
    echo "<font color=\"darkred\">(" . $row['nb_fails'] . " fails)</font></td>";
  } else {
    echo "<i>(" . $row['nb_fails'] . " fails)</i></td>";
  }
  echo "<td>Received&nbsp;from&nbsp;#" . $row['received_from'] . "<br />";
  echo "Sent&nbsp;to&nbsp;#" . $row['sent_to'] . "</td>";
  echo "<td>Received&nbsp;:&nbsp;" . $row['received_time'] . "<br />";
  echo "sent&nbsp;in&nbsp;<span class=\"tooltip\" title=\"" . $row['sent_time'] . "\">" . deltatime($row['received_time'], $row['sent_time']) . "</span><br />";
  echo "done&nbsp;in&nbsp;<span class=\"tooltip\" title=\"" . $row['done_time'] . "\">" . deltatime($row['sent_time'], $row['done_time']) . "</span></td>";
  echo "<td>";
  # Summary
  $resultdata = json_decode($row['resultdata'], true);
  if($resultdata['errorcode'] > 0) {
    echo "<a id=\"toggle" . $tid . "\" />";
    echo "<font color=\"darkred\">Error #" . $resultdata['errorcode'] . " received from server.</font><br />";
    echo "<a href=\"#toggle" . $tid . "\" onclick=\"togglePre(" . $tid . ")\">Toggle message</a><br />";
    echo "<pre class=\"toggle" . $tid . "\" style=\"display:none;\">" . $resultdata['errormsg'] . "</pre>";
    $tid += 1;
  } elseif(!isset($resultdata['taskdata'])) {
    echo "Unrecognized resultdata, taskdata field missing.";
  } else {
    foreach($resultdata['taskdata']['executions'] as $execution) {
      echo "*&nbsp;Execution&nbsp;" . $execution['name'] . "&nbsp;:<br />";
      foreach($execution['testsReports'] as $report) {
        if(isset($report['checker'])) {
          echo "<a id=\"toggle" . $tid . "\" />";
          echo "<font color=\"darkgreen\">Solution executed successfully.</font><br />";
          echo "<a href=\"#toggle" . $tid . "\" onclick=\"togglePre(" . $tid . ")\">Toggle checker report</a><br />";
          echo "<pre class=\"toggle" . $tid . "\" style=\"display:none;\">" . $report['checker']['stdout']['data'] . "</pre>";
          $tid += 1;
        } elseif(isset($report['execution'])) {
          echo "Solution returned an error. Check JSON data for details.";
        } else {
          echo "Test rejected by sanitizer. Check JSON data for details.";
        }
      }
    }
  }
  echo "</td>";
  echo "<td><textarea width=\"100px\" height=\"100px\" id=\"json" . $tid . "\">" . $row['taskdata'] . "</textarea><a href=\"#pretty\" onclick=\"prettyPrint(" . $tid . ")\"><br />Pretty-print</td>";
  $tid += 1;
  echo "<td><textarea width=\"100px\" height=\"100px\" id=\"json" . $tid . "\">" . $row['resultdata'] . "</textarea><a href=\"#pretty\" onclick=\"prettyPrint(" . $tid . ")\"><br />Pretty-print</td>";
  $tid += 1;
  echo "</tr>";
}

echo "</table>";
echo "<h2>Tasks</h2>";
echo "<table border=1><tr><td><b>id</b></td><td><b>name</b></td><td><b>status</b></td><td><b>priority</b></td><td><b>timeout_sec</b></td><td><b>servers</b></td><td><b>times</b></td><td><b>taskdata</b></td></tr>";
$res = $db->query("
  SELECT queue.*,
         GROUP_CONCAT(server_types.name SEPARATOR ',') AS types
  FROM `queue`
  LEFT JOIN task_types ON task_types.taskid=queue.id
  LEFT JOIN server_types ON server_types.id=task_types.typeid
  GROUP BY queue.id
  ORDER BY priority DESC, received_time ASC;");
while($row = $res->fetch()) {
  echo "<tr>";
  echo "<td>" . $row['id'] . "</td>";
  echo "<td>" . $row['name'] . "</td>";
  echo "<td>" . $row['status'] . "</td>";
  echo "<td>" . $row['priority'] . "</td>";
  echo "<td>" . $row['timeout_sec'] . "s<br />";
  if($row['nb_fails'] > 0)
  {
    echo "<font color=\"darkred\">(" . $row['nb_fails'] . " fails)</font></td>";
  } else {
    echo "<i>(" . $row['nb_fails'] . " fails)</i></td>";
  }
  echo "<td>Received&nbsp;from&nbsp;#" . $row['received_from'] . "<br />";
  if($row['sent_to'] > 0) {
    echo "Sent&nbsp;to&nbsp;#" . $row['sent_to'] . "</td>";
  } else {
    echo "<span class=\"tooltip\" title=\"Can be sent to server types " . $row['types'] . "\">Not sent yet</span></td>";
  }
  echo "<td>Received&nbsp;:&nbsp;" . $row['received_time'];
  if($row['sent_to'] > 0) {
    echo "<br />Sent&nbsp;in&nbsp;<span class=\"tooltip\" title=\"" . $row['sent_time'] . "\">" . deltatime($row['received_time'], $row['sent_time']) . "</span></td>";
  } else {
    echo "</td>";
  }
  echo "<td><textarea width=\"100px\" height=\"100px\" id=\"json" . $tid . "\">" . $row['taskdata'] . "</textarea><a href=\"#pretty\" onclick=\"prettyPrint(" . $tid . ")\"><br />Pretty-print</td>";
  $tid += 1;
  echo "</tr>";
}

echo "</table>";
echo "<h2>Log</h2>";
echo "<table border=1><tr><td><b>id</b></td><td><b>datetime</b></td><td><b>log_type</b></td><td><b>task_id</b></td><td><b>server_id</b></td><td><b>message</b></td></tr>";
$res = $db->query("SELECT * FROM `log` ORDER BY datetime DESC;");
while($row = $res->fetch()) {
  echo "<tr>";
  echo "<td>" . $row['id'] . "</td>";
  echo "<td>" . $row['datetime'] . "</td>";
  echo "<td>" . $row['log_type'] . "</td>";
  echo "<td>" . $row['task_id'] . "</td>";
  echo "<td>" . $row['server_id'] . "</td>";
  echo "<td>" . $row['message'] . "</td>";
  echo "</tr>";
}

echo "</table>";

?>
<a name="pretty" />
<h2>Pretty-printed JSON</h2>
<div id="prettydata"><i>Pretty-printed JSON will go here.</i></div>
<script>
buttonsData = new Array();
<?php
echo $buttonsData;
?>

$( "#taskSend" ).submit(function( event ) {
  event.preventDefault();
  var $form = $( this ),
    url = $form.attr( "action" );

  $( "#taskSendResult" ).empty().append("<img src=\"res/loading.gif\" />");
  fdata = new FormData(this);
  fdata.append("request", "sendsolution");
  $.ajax({
    url: url,
    type: 'POST',
    data: fdata,
    cache: false,
    processData: false,
    contentType: false,
    success: function( data ) { $( "#taskSendResult" ).empty().append(data); }
  });
});

function sendPath( pathid ) {
    $("#taskSend").find('input').val(function(idx, val) {
      if(this.name in buttonsData[pathid]) {
        return buttonsData[pathid][this.name];
      } else {
        return val;
      }
    });
    $("#solcontent").val(function(idx, val) {
      if(this.name in buttonsData[pathid]) {
        if(buttonsData[pathid][this.name] != '') {
          $("#solcontentarea").toggle(true);
        } else {
          $("#solcontentarea").toggle(false);
        }
        return buttonsData[pathid][this.name];
      } else {
        return val;
      }
    });
};

function prettyPrint(tid) {
  $.ajax({
    url: "prettyprint.php",
    type: 'POST',
    data: {jsondata: $( "textarea#json" + tid ).val()},
    cache: true,
    success: function( data ) {
      $( "#prettydata" ).empty().append(data);
      document.location.hash = "#pretty";
    }
  });
};

function togglePre(tid) {
  $( ".toggle" + tid ).toggle();
  return false;
};
</script>
</body></html>
