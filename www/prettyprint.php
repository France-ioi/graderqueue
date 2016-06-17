<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

if(isset($_POST['jsondata'])) {
  try {
    echo "<pre>" . htmlspecialchars(json_encode(json_decode($_POST['jsondata']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "</pre>";
  } catch (Exception $e) {
    die("JSON data invalid.");
  }
} else {
  die("No JSON data sent.");
}
?>
