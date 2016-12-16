<h1>Servers</h1>

<table class="table table-bordered">
    <thead>
        <tr>
            <td>id</td>
            <td>name</td>
            <td>status</td>
            <td>ssl info</td>
            <td>wakeup_url</td>
            <td>type</td>
            <td>jobs</td>
            <td>last_poll_time</td>
        </tr>
    </thead>

    <tbody>
        <?php foreach($servers as $row) { ?>
            <tr>
                <td><?=$row['id']?></td>
                <td><?=$row['name']?></td>
                <td>
                    <?php if($row['nbjobs'] > 0) { ?>
                        <font color="darkorange">busy (<?=$row['nbjobs']?> jobs)</font>
                    <?php } elseif(time()-strtotime($row['last_poll_time']) > 60) { ?>
                        <font color="darkblue">sleeping <i>(<?=deltatime($row['last_poll_time'], 'now')?>)</i></font>
                    <?php } else { ?>
                        <font color="darkgreen">polling</font>
                    <?php } ?>
                </td>
                <td>
                    <?php if($row['ssl_serial'] . $row['ssl_dn'] != '') { ?>
                        serial: <span class="hint" title="<?=$row['ssl_dn']?>"><?=$row['ssl_serial']?></span>
                    <?php } ?>
                </td>
                <td>
                    <?=HTML::hint('URL', $row['wakeup_url'])?>
                    <a href="#" onclick="wakeupServer(<?=$row['id']?>)"\>Wake-up</a>
                    <?=($row['wakeup_fails'] > 0 ? HTML::error('('.$row['wakeup_fails'].' wake-up failures)'): '')?>
                </td>
                <td>
                    #<?=$row['type']?>
                    <?=HTML::hint($row['typename'], $row['tags'])?>
                </td>
                <td><?=$row['nbjobs']?>/<?=$row['max_concurrent_jobs']?></td>
                <td><?=$row['last_poll_time']?></td>
            </tr>
        <?php } ?>
    </tbody>
</table>

<section id="message"></section>


<script type="text/javascript">
    function wakeupServer(sid) {
        $.ajax({
            url: "../api.php",
            type: 'POST',
            data: {"request": "wakeup", "serverid": sid, "token": "<?=$token ?>"},
            cache: true,
            success: function(data) {
                $("#message").empty().append(data);
            }
        });
    };
</script>