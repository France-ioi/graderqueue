<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require("funcs.inc.php");

### Configuration

# Database parameters
$CFG_db_hostname = "";
$CFG_db_user = "";
$CFG_db_password = "";
$CFG_db_database = "";

# Default language extensions
$CFG_defaultexts = array(
    "[default]" => ".sh",
    "c" => ".c",
    "cpp" => ".cpp",
    "java" => ".java",
    "javascool" => ".jvs",
    "ocaml" => ".ml",
    "pascal" => ".pas",
    "py" => ".py",
    "sh" => ".sh"
);

# Interface buttons
# Keys of the array are the name of the fields in the interface.php form
$CFG_defaultbutton = array(
    "solfile" => '',
    "solpath" => '',
    "solcontent" => '',
    "tags" => "");
$CFG_buttons = array(
#    "Example Button" => array(
#        "solpath" => "path_to_solution/solution.c",
#        "taskpath" => "path_to/task/",
#        "lang" => "c")
);

### End of configuration

$db = new mysqli($CFG_db_hostname, $CFG_db_user, $CFG_db_password, $CFG_db_database);
if($db->connect_errno) {
  die(jsonerror(2, "Failed to connect to database: (" . $db->connect_errno . ") " . $db->connect_error));
}
?>
