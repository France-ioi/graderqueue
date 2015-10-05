<?php
# Copyright (c) 2015 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

require __DIR__."/funcs.inc.php";

### Configuration

## Database parameters
$CFG_db_hostname = "";
$CFG_db_user = "";
$CFG_db_password = "";
$CFG_db_database = "";

# Number of server failures before considering a task as in error
$CFG_max_fails = 2;


# public/private key for communication with the platforms
# Communicate the key name and public key to the platforms you want to communicate with
$CFG_key_name = "graderqueue.pem.dev";
$CFG_private_key = "";
$CFG_public_key = "";


## cron.php
# E-mail address to send warnings to
$CFG_admin_email = '';
# URL to the interface (to include link in the mail)
$CFG_interface_url = "https://example.com/interface.php";
# Number of hours between two cron.php executions
$CFG_cron_interval_hours = 24;
# Number of hours before warning about a task stuck or in error
$CFG_warn_hours = 1;
# Number of days to keep old tasks and logs for
# All tasks and logs older than this setting will be deleted, regardless or their status
$CFG_keep_old_days = 7;


## interface.php
# Accepts token from interface.php? (see api.php, not suitable for production)
$CFG_accept_interface_tokens = false;

# Number of results per page in interface.php
$CFG_res_per_page = 10;

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

try {
  $db = new PDO('mysql:host=' . $CFG_db_hostname . ';dbname=' . $CFG_db_database, $CFG_db_user, $CFG_db_password);
} catch(PDOException $e) {
  die(jsonerror(2, "Failed to connect to database: " . $e->getMessage()));
}
?>
