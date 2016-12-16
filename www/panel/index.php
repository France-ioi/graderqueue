<?php

    require("../config.inc.php");
    require("libs/html.php");

    $default_controller = 'servers';

    if($CFG_accept_interface_tokens) {
        session_start();
        $token = $_SESSION['token'];
        $stmt = $db->prepare("SELECT * FROM `tokens` WHERE expiration_time >= NOW() AND token = :token;");
        $stmt->execute(array(':token' => $token));
        if(!$stmt->fetch()) {
            $token = md5(microtime());
            $stmt = $db->prepare("INSERT INTO `tokens` VALUES(:token, NOW()+INTERVAL 10 MINUTE);");
            $stmt->execute(array(':token' => $token));
            $_SESSION['token'] = $token;
        }
    }

    $controller = 'controllers/'.(isset($_REQUEST['controller']) ? $_REQUEST['controller'] : $default_controller).'.php';

    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    if($is_ajax) {
        header("Content-Type: application/json");
        require_once($controller);
    } else {
        include('views/layout/header.php');
        require_once($controller);
        include('views/layout/footer.php');
    }