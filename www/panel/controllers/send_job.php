<?php

    if($CFG_accept_interface_tokens) {
        $test_buttons = array();
        foreach($CFG_buttons as $bname => $bdata) {
            $test_buttons[$bname] = array_merge($CFG_defaultbutton, $bdata);
        }
        include('views/send_job/form.php');
    } else {
        include('views/send_job/disabled.php');
    }