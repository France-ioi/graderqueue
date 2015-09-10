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
<h2>Send job</h2>
<div>
<form enctype="multipart/form-data" action="api.php" id="jobSend">
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
<div>Test jobs :
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
<div id="jobSendResult"></div>
<a name="servers" />
<?php

if(isset($_GET['page'])) {
  $curpage = max(1, intval($_GET['page']));
} else {
  $curpage = 1;
}


##### Servers

echo "<h2>Servers</h2>";
echo "<table border=1><tr><td><b>id</b></td><td><b>name</b></td><td><b>status</b></td><td><b>ssl_serial</b></td><td><b>ssl_dn</b></td><td><b>wakeup_url</b></td><td><b>type</b></td><td><b>jobs</b></td><td><b>last_poll_time</b></td></tr>";
$res = $db->query("
  SELECT servers.*,
    server_types.name AS typename,
    GROUP_CONCAT(tags.name SEPARATOR ',') AS tags,
    COUNT(queue.id) AS nbjobs
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
  if($row['nbjobs'] > 0) {
    echo "<td><font color=\"darkorange\">busy (" . $row['nbjobs'] . " jobs)</font></td>";
  } elseif(time()-strtotime($row['last_poll_time']) > 60) {
    echo "<td><font color=\"darkblue\">sleeping <i>(" . deltatime($row['last_poll_time'], 'now') . ")</i></font></td>";
  } else {
    echo "<td><font color=\"darkgreen\">polling</font></td>";
  }
  echo "<td>" . $row['ssl_serial'] . "</td>";
  echo "<td>" . $row['ssl_dn'] . "</td>";
  echo "<td>" . $row['wakeup_url'] . "&nbsp;<a href=\"#servers\" onclick=\"wakeupServer(" . $row['id'] . ")\">Wake-up</a></td>";
  echo "<td>#" . $row['type'] . " : <span class=\"tooltip\" title=\"supports tags " . $row['tags'] . "\">" . $row['typename'] . "</span></td>";
  echo "<td>" . $row['nbjobs'] . " / " . $row['max_concurrent_jobs'] . "</td>";
  echo "<td>" . $row['last_poll_time'] . "</td>";
  echo "</tr>";
}

echo "</table>";
echo "<div id=\"serverResult\"></div>";


##### Tasks done

echo "<h2>Tasks done</h2>";
echo "<table border=1><tr><td><b>id</b></td><td><b>name</b></td><td><b>priority</b></td><td><b>timeout_sec</b></td><td><b>servers</b></td><td><b>times</b></td><td><b>summary</b></td><td><b>jobdata</b></td><td><b>resultdata</b></td></tr>";

$res = $db->query("SELECT COUNT(*) FROM `done`;");
$nbpages_done = max(1, ceil($res->fetch()[0] / $CFG_res_per_page));

$res = $db->query("SELECT * FROM `done` ORDER BY done_time DESC LIMIT " . (min($curpage, $nbpages_done)-1) * $CFG_res_per_page . ", " . $CFG_res_per_page . ";");
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

  # Summary of resultdata
  $resultdata = json_decode($row['resultdata'], true);

  if(isset($resultdata['errorcode']) && $resultdata['errorcode'] > 0) {
    # Show error
    echo "<a id=\"toggle" . $tid . "\" />";
    echo "<font color=\"darkred\">Error #" . $resultdata['errorcode'] . " received from server.</font><br />";
    echo "<a href=\"#toggle" . $tid . "\" onclick=\"togglePre(" . $tid . ")\">Toggle message</a><br />";
    echo "<pre class=\"toggle" . $tid . "\" style=\"display:none;\">" . $resultdata['errormsg'] . "</pre>";
    $tid += 1;
  } else {
    # No error
    if(isset($resultdata['jobdata'])) {
      $resultdata = $resultdata['jobdata'];
    } else {
      # Legacy code, could be deleted once development is done
      echo "<i>(old-format resultdata)</i><br />";
    }
    foreach($resultdata['executions'] as $execution) {
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
  echo "<td><textarea width=\"100px\" height=\"100px\" id=\"json" . $tid . "\">" . $row['jobdata'] . "</textarea><a href=\"#pretty\" onclick=\"prettyPrint(" . $tid . ")\"><br />Pretty-print</td>";
  $tid += 1;
  echo "<td><textarea width=\"100px\" height=\"100px\" id=\"json" . $tid . "\">" . $row['resultdata'] . "</textarea><a href=\"#pretty\" onclick=\"prettyPrint(" . $tid . ")\"><br />Pretty-print</td>";
  $tid += 1;
  echo "</tr>";
}

echo "</table>";

echo "<div>Pages: ";
for($i=1; $i <= $nbpages_done; $i++) {
  if($i == min($curpage, $nbpages_done)) {
    echo "<b>$i</b>&nbsp;";
  } else {
    echo "<a href=\"interface.php?page=$i\">$i</a>&nbsp;";
  }
}
echo "</div>";

##### Tasks

echo "<h2>Tasks</h2>";
echo "<table border=1><tr><td><b>id</b></td><td><b>name</b></td><td><b>status</b></td><td><b>priority</b></td><td><b>timeout_sec</b></td><td><b>servers</b></td><td><b>times</b></td><td><b>jobdata</b></td></tr>";

$res = $db->query("SELECT COUNT(*) FROM `queue`;");
$nbpages_queue = max(1, ceil($res->fetch()[0] / $CFG_res_per_page));

$res = $db->query("
  SELECT queue.*,
         GROUP_CONCAT(server_types.name SEPARATOR ',') AS types
  FROM `queue`
  LEFT JOIN job_types ON job_types.jobid=queue.id
  LEFT JOIN server_types ON server_types.id=job_types.typeid
  GROUP BY queue.id
  ORDER BY priority DESC, received_time ASC
  LIMIT " . (min($curpage, $nbpages_queue)-1) * $CFG_res_per_page . ", " . $CFG_res_per_page . "
  ;");
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
  echo "<td><textarea width=\"100px\" height=\"100px\" id=\"json" . $tid . "\">" . $row['jobdata'] . "</textarea><a href=\"#pretty\" onclick=\"prettyPrint(" . $tid . ")\"><br />Pretty-print</td>";
  $tid += 1;
  echo "</tr>";
}

echo "</table>";

echo "<div>Pages: ";
for($i=1; $i <= $nbpages_queue; $i++) {
  if($i == min($curpage, $nbpages_queue)) {
    echo "<b>$i</b>&nbsp;";
  } else {
    echo "<a href=\"interface.php?page=$i\">$i</a>&nbsp;";
  }
}
echo "</div>";


##### Log

echo "<h2>Log</h2>";
echo "<table border=1><tr><td><b>id</b></td><td><b>datetime</b></td><td><b>log_type</b></td><td><b>job_id</b></td><td><b>server_id</b></td><td><b>message</b></td></tr>";

$res = $db->query("SELECT COUNT(*) FROM `log`;");
$nbpages_log = max(1, ceil($res->fetch()[0] / $CFG_res_per_page));

$res = $db->query("SELECT * FROM `log` ORDER BY datetime DESC LIMIT " . (min($curpage, $nbpages_log)-1) * $CFG_res_per_page . ", " . $CFG_res_per_page . ";");
while($row = $res->fetch()) {
  echo "<tr>";
  echo "<td>" . $row['id'] . "</td>";
  echo "<td>" . $row['datetime'] . "</td>";
  echo "<td>" . $row['log_type'] . "</td>";
  echo "<td>" . $row['job_id'] . "</td>";
  echo "<td>" . $row['server_id'] . "</td>";
  echo "<td>" . $row['message'] . "</td>";
  echo "</tr>";
}

echo "</table>";

echo "<div>Pages: ";
for($i=1; $i <= $nbpages_log; $i++) {
  if($i == min($curpage, $nbpages_log)) {
    echo "<b>$i</b>&nbsp;";
  } else {
    echo "<a href=\"interface.php?page=$i\">$i</a>&nbsp;";
  }
}
echo "</div>";

##### Pretty-printed JSON
?>
<a name="pretty" />
<h2>Pretty-printed JSON</h2>
<div id="prettydata"><i>Pretty-printed JSON will go here.</i></div>
<script>
buttonsData = new Array();
<?php
echo $buttonsData;
?>

$( "#jobSend" ).submit(function( event ) {
  event.preventDefault();
  var $form = $( this ),
    url = $form.attr( "action" );

  $( "#jobSendResult" ).empty().append("<img src=\"res/loading.gif\" />");
  fdata = new FormData(this);
  fdata.append("request", "sendsolution");
  $.ajax({
    url: url,
    type: 'POST',
    data: fdata,
    cache: false,
    processData: false,
    contentType: false,
    success: function( data ) { $( "#jobSendResult" ).empty().append(data); }
  });
});

function wakeupServer(sid) {
  $.ajax({
    url: "api.php",
    type: 'POST',
    data: {"request": "wakeup", "serverid": sid, "token": "<?=$token ?>"},
    cache: true,
    success: function( data ) { $( "#serverResult" ).empty().append(data); }
  });
};

function sendPath( pathid ) {
    $("#jobSend").find('input').val(function(idx, val) {
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
