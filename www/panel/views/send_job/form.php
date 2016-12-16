<h1>Send job</h1>

<div>
    <form enctype="multipart/form-data" action="../api.php" id="jobSend">
        Solution : <input type="file" name="solfile" /> <i>or</i> path <input type="text" name="solpath" /> <i>or</i> <a onclick="$('#solcontentarea').toggle();" href="#form">content</a><br />
        <span id="solcontentarea" style="display:none;"><textarea id="solcontent" name="solcontent"></textarea><br /></span>
        Task path : <input type="text" name="taskpath" size="150" value="$ROOT_PATH/FranceIOI/Contests/..." /><br />
        Memory limit (KB) : <input type="text" name="memlimit" value="131072" /><br />
        Time limit (ms) : <input type="text" name="timelimit" value="60000" /><br />
        Language : <input type="text" name="lang" value="c" /><br />
        Priority : <input type="text" name="priority" value="10" /><br />
        Tags : <input type="text" name="tags" value="" /><br />
        Task revision : <input type="text" name="taskrevision" value="" /><br />
        Send <input type="text" name="times" value="1" /> times<br />
        <input type="hidden" name="token" value="<?=$token ?>" />
        <input type="submit" value="Submit" />
    </form>
</div>

<div>
    Test jobs:
    <span id="test-buttons"></span>
</div>

<div>
    <span id="jobSendProgress"></span><br />
    <a onclick="$('#jobSendResults').toggle();" href="#form">Details</a><br />
    <span id="jobSendResults">Nothing yet.</span>
</div>





<script type="text/javascript">
    $(document).ready(function() {

        var test_buttons = <?=json_encode($test_buttons)?>;
        $.each(test_buttons, function(caption, params) {
            var btn = $('<button>' + caption + '</button>')
            btn.click(function() {
                $("#jobSend").find('input').val(function(idx, val) {
                    return this.name in params ? params[this.name] : val;
                });
                $("#solcontent").val(function(idx, val) {
                    if(this.name in params) {
                        $("#solcontentarea").toggle(params[this.name] != '');
                        return params[this.name];
                    } else return val;
                });
            })
            $('#test-buttons').append(btn);
        });


        $("#jobSend" ).submit(function( event ) {
          event.preventDefault();
          var $form = $( this ),
            url = $form.attr( "action" );

          $( "#jobSendProgress" ).empty().append("<img src=\"../res/loading.gif\" />");
          $( "#jobSendResults" ).empty();
          fdata = new FormData(this);
          fdata.append("request", "sendsolution");
          times = parseInt(fdata.get('times'));
          for(i = 1; i <= times; i++) {
            fdata.set("jobusertaskid", 'interface-'+Math.floor(Math.random()*10000000000));
            $( "#jobSendProgress" ).empty().append("<img src=\"res/loading.gif\" /> Sending request "+i+"/"+times+"...");
            $.ajax({
              url: url,
              type: 'POST',
              data: fdata,
              cache: false,
              processData: false,
              contentType: false,
              indexValue: i,
              success: function( data ) { $( "#jobSendResults" ).append("Request "+this.indexValue+": "+data+"<br />"); }
            });
          }
          $( "#jobSendProgress" ).empty().append("Sent "+times+" requests!");
        });
    })

</script>