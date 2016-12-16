<h1>Tasks</h1>

<section>
    <form class="form-inline" id="search">
        <div class="form-group">
            <label>ID</label>
            <input type="text" name="id" className="form-control"/>
        </div>
        <div class="form-group">
            <label>Date start</label>
            <input type="text" name="date_start" className="form-control"/>
        </div>
        <div class="form-group">
            <label>Date end</label>
            <input type="text" name="date_end" className="form-control"/>
        </div>
        <button type="submit" class="btn btn-default">Search</button>
    </form>
</section>

<table id="table" class="table table-bordered">
     <thead>
        <tr>
            <td>id</td>
            <td>name</td>
            <td>status</td>
            <td>priority</td>
            <td>timeout_sec</td>
            <td>servers</td>
            <td>times</td>
            <td>jobdata</td>
        </tr>
    </thead>
</table>

<script type="text/javascript">
    $(document).ready(function() {
        $('input[name=date_start]').datepicker({ format: "yyyy-mm-dd" });
        $('input[name=date_end]').datepicker({ format: "yyyy-mm-dd" });

        var table = $('#table').DataTable({
            bFilter: false,
            ordering: false,
            processing: true,
            serverSide: true,
            ajax: {
                url: '?controller=tasks_queued_ajax',
                data: function(d) {
                    d.filter = $('form#search').serializeArray().reduce(function(obj, item) {
                        if(item.value != '') obj[item.name] = item.value;
                        return obj;
                    }, {});
                }
            }
        });

        $('form#search').submit(function(e) {
            e.preventDefault();
            table.ajax.reload();
        })
    });
</script>