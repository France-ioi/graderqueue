<h1>Log</h1>

<table id="table" class="table table-bordered">
     <thead>
        <tr>
            <td>id</td>
            <td>datetime</td>
            <td>log_type</td>
            <td>job_id</td>
            <td>server_id</td>
            <td>message</td>
        </tr>
    </thead>
</table>

<script type="text/javascript">
    $(document).ready(function() {
        $('#table').DataTable({
            bFilter: false,
            ordering: false,

            processing: true,
            serverSide: true,
            ajax: '?controller=log_ajax'
        });
    });
</script>