<h1>Current queue size: <?=$queue_size?></h1>

<section>
    <form method="GET" action="?" class="form-inline">
        <input type="hidden" name="controller" value="server_statistic"/>
        <div class="form-group">
            <label for="interval">Interval</label>
            <select name="interval" id="interval" class="form-control">
                <?php for($i=0; $i<count($CFG_stat_intervals); $i++) { ?>
                    <option value="<?=$i?>" <?=($i==$interval_idx ? 'selected="selected"' : '')?>><?=$CFG_stat_intervals[$i]['caption']?></option>
                <?php } ?>
            </select>
        </div>
        <button type="submit" class="btn btn-default">Apply</button>
    </form>
</section>

<section>
    <table class="table table-nonfluid">
        <tr>
            <td>Tasks processed</td>
            <td><?=$overview['tasks_processed']?></td>
        </tr>
        <tr>
            <td>Average time per task on the server (sec)</td>
            <td><?=number_format($overview['avg_time_per_task'], 4)?></td>
        </tr>
        <tr>
            <td>Number of errors</td>
            <td><?=number_format($overview['errors_count'], 0)?></td>
        </tr>
        <tr>
            <td>Average time taken to send the score back to the platform (sec)</td>
            <td><?=number_format($overview['avg_time_per_send_back'], 4)?></td>
        </tr>
        <tr>
            <td>Average length of the queue</td>
            <td><?=number_format($overview['avg_queue_size'], 4)?></td>
        </tr>
        <tr>
            <td>Average number of busy servers</td>
            <td><?=number_format($overview['avg_server_time'], 4)?></td>
        </tr>
    </table>
</section>

<section>
    <div class="row">
        <div class="col-md-6 col-xs-12">
            <canvas id="chart1" height="150"></canvas>
        </div>
        <div class="col-md-6 col-xs-12">
            <canvas id="chart2" height="150"></canvas>
        </div>
        <div class="col-md-6 col-xs-12">
            <canvas id="chart3" height="150"></canvas>
        </div>
        <div class="col-md-6 col-xs-12">
            <canvas id="chart4" height="150"></canvas>
        </div>
    </div>
</section>



<script type="text/javascript">
    $(document).ready(function() {
        var chart_data = <?=json_encode($chart_data)?>;

        var c1 = "rgb(30, 116, 255)";
        var c2 = "rgb(255, 128, 62)";

        var p = {
            responsive: true,
            data: {
                labels: chart_data.labels,
                datasets: [
                    { data: chart_data.avg_waiting_time, label: 'Average waiting time (sec)', fill: false, borderColor: c1 },
                    { data: chart_data.max_waiting_time, label: 'Max waiting time (sec)', fill: false, borderColor: c2 }
                ]
            },
        }
        new Chart.Line($("#chart1"), p);


        var p = {
            responsive: true,
            data: {
                labels: chart_data.labels,
                datasets: [
                    { data: chart_data.sum_cpu_time_ms, label: 'CPU time (ms)', fill: false, borderColor: c1 },
                    { data: chart_data.sum_real_time_ms, label: 'Real time (ms)', fill: false, borderColor: c2 }
                ]
            }
        }
        new Chart.Line($("#chart2"), p);


        var p = {
            responsive: true,
            data: {
                labels: chart_data.labels,
                datasets: [
                    { data: chart_data.avg_queue_size, label: 'Average length of the queue', fill: false, borderColor: c1 },
                    { data: chart_data.max_queue_size, label: 'Max length of the queue', fill: false, borderColor: c2 }
                ]
            }
        }
        new Chart.Line($("#chart3"), p);


        var p = {
            responsive: true,
            data: {
                labels: chart_data.labels,
                datasets: [
                    { data: chart_data.avg_server_time, label: 'Average number of busy servers', fill: false, borderColor: c1 },
                    { data: chart_data.max_server_time, label: 'Max number of busy servers', fill: false, borderColor: c2 }
                ]
            }
        }
        new Chart.Line($("#chart4"), p);
    });
</script>
