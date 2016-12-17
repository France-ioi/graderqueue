<h1>Task statistic</h1>

<section>
    <form method="GET" action="?" class="form-inline">
        <input type="hidden" name="controller" value="task_statistic"/>
        <div class="form-group">
            <label for="interval">Interval</label>
            <select name="interval" id="interval" class="form-control">
                <?php for($i=0; $i<count($CFG_stat_intervals); $i++) { ?>
                    <option value="<?=$i?>" <?=($i==$interval_idx ? 'selected="selected"' : '')?>"><?=$CFG_stat_intervals[$i]['caption']?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group">
            <label for="group_by">Group by</label>
            <select name="group_by" id="group_by" class="form-control">
                <?php for($i=0; $i<count($group_by_options); $i++) { ?>
                    <option value="<?=$i?>" <?=($i==$group_by_idx ? 'selected="selected"' : '')?>"><?=$group_by_options[$i]['caption']?></option>
                <?php } ?>
            </select>
        </div>
        <button type="submit" class="btn btn-default">Apply</button>
    </form>
</section>


<? if($result) { ?>
    <section>
        <table class="table table-nofluid">
            <thead>
                <tr>
                    <th><?= $group_by['caption'] ?></th>
                    <th># of submissions</th>
                    <th># of successful</th>
                    <th>Avg waiting time</th>
                    <th>Avg time to send score back</th>
                    <th>Total CPU time</th>
                    <th>Total real time</th>
                    <th>Max CPU time by successful</th>
                </tr>
            </thead>
            <tbody>
                <? foreach($result as $row) { ?>
                    <tr>
                        <td><?=isset($row[$group_by['field']]) ? $row[$group_by['field']] : ''?></td>
                        <td><?=$row['total']?></td>
                        <td><?=$row['total_success']?></td>
                        <td><?=$row['avg_waiting_time']?>s</td>
                        <td><?=$row['avg_time_per_task']?>s</td>
                        <td><?=$row['sum_cpu_time_ms']?>ms</td>
                        <td><?=$row['sum_real_time_ms']?>ms</td>
                        <td><?=$row['total_success'] > 0 ? $row['max_cpu_time'].'ms' : 'n/a'?></td>
                    </tr>
                <? } ?>
            </tbody>
        </table>
    </section>
<? } else { ?>
    No data
<? } ?>