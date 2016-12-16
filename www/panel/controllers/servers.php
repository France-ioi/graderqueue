<?php

    $servers = $db->query("
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
        ORDER BY servers.id ASC;
    ")->fetchAll();

    include('views/servers/content.php');