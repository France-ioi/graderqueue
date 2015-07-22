## Usage with the graderqueue

The `server.py` script is made for use with the [graderqueue](https://github.com/France-ioi/graderqueue). To use its wakeup feature, you can use `inetd`, and add a config line to a file named `/etc/inetd.d/taskgrader` with:

    [port]   stream  tcp nowait  [user]  /path/to/server.py /path/to/server.py --server
