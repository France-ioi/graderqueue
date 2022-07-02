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

# Wake-up strategy: decides which server to wake-up
# "first"   wakes up the first idle server when needed; good to keep the number of
#   servers active low
# "last"    wakes up the server which didn't poll for the longest time; rotates
#   effectively between servers, more reactive to bursts
$CFG_wakeup_strategy = 'first';


# public/private key for communication with the platforms
# Communicate the key name and public key to the platforms you want to communicate with
$CFG_key_name = "graderqueue.pem.dev";
$CFG_private_key = "";
$CFG_public_key = "";
# Debug password; allows platforms to send plaintext requests, leave empty to disable
$CFG_debug_password = "";

## cron.php
# E-mail address to send warnings to
$CFG_admin_email = '';
# URL to the interface (to include link in the mail)
$CFG_interface_url = "https://example.com/interface.php";
# Number of days to keep old tasks and logs for
# All tasks and logs older than this setting will be deleted, regardless or their status
$CFG_keep_old_days = 7;

# Number of seconds before warning about a task stuck or in error
$CFG_warn_seconds = 30;
# Number of tasks exceeding that threshold before sending a warning
$CFG_warn_nb = 10;


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


$CFG_stat_intervals = array(
    array(
        'caption' => '15 Minutes',
        'duration' => 15*60, // sec
        'tick' => 60 // sec
    ),
    array(
        'caption' => '1 Hour',
        'duration' => 60*60, // sec
        'tick' => 5*60 // sec
    ),
    array(
        'caption' => '3 Hours',
        'duration' => 3*60*60, // sec
        'tick' => 15*60 // sec
    ),
    array(
        'caption' => '12 Hours',
        'duration' => 12*60*60, // sec
        'tick' => 60*60 // sec
    ),
    array(
        'caption' => '1 Day',
        'duration' => 24*60*60, // sec
        'tick' => 2*60*60 // sec
    ),
    array(
        'caption' => '1 Week',
        'duration' => 7*24*60*60, // sec
        'tick' => 12*60*60 // sec
    ),
    array(
        'caption' => '2 Weeks',
        'duration' => 14*24*60*60, // sec
        'tick' => 24*60*60 // sec
    )
);

$CFG_aws_credentials = [
    'credentials' => [
        'key' => '',
        'secret' => ''
        ],
    'region' => ''
    ];
$CFG_aws_autoscaling_group = '';

$CFG_autoscaling_ratio_burst = 1.3;
$CFG_autoscaling_ratio_long = 1.6;
$CFG_autoscaling_minutes_downscale = 30;


### End of configuration

try {
  $db = connect_pdo($CFG_db_hostname, $CFG_db_database, $CFG_db_user, $CFG_db_password);
} catch(PDOException $e) {
  die(jsonerror(2, "Failed to connect to database: " . $e->getMessage()));
}
?>
