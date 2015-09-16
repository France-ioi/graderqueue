# Grader queue
This service provides a queue system for jobs. It handles receiving jobs from
various sources and sending jobs to taskgrader servers. It was made for use
with the [taskgrader](https://github.com/France-ioi/taskgrader) but can work
with any program using JSON to describe jobs and results.

## How does it work?
* Platforms send jobs through the API `api.php`
* Grading servers poll the queue through `poll.php`
* Tasks whose tags can be handled by a given server are sent to this server
* Grading server executes the job then sends back the results through `sendresults.php`
* Platforms get the results through the API

## Installation
* install [composer](https://getcomposer.org/) and run `composer install`
* In the `www` folder, copy `config.inc.php.template` to `config.inc.php` and edit it to suit your needs.
* Execute `install.sh` in the root folder, it will set up the database and create the graderqueue certificate.
* Create keys for the platforms and the servers using `clientcert.sh` in the `certs` folder.
* Add platforms and servers to the database in the tables `platforms` and `servers`.
* Configure your web server to point to the `www` folder; for Apache2 and Nginx, you can use the example config files in the `examples` folder.
* Secure or disable access to `interface.php` with your favorite method, for instance with the `htaccess` file in the `examples` folder.

## Configuration
The file `config.inc.php` contains various configuration options.

The script `cron.php` needs to be executed regularly to delete old tasks and
send warnings to the admin.

Once the database is set up, platforms, servers, server types and tags need to
be configured.

### Setting up a platform
Platforms send jobs through the API. They need to be registered first in the table `platforms`:
* `id` and `name` are internal identifiers (name is also the key id used in token communication)
* `public_key` is the public key of the platform (used in token communications)
* `return_url` is the url where the result of the evaluation will be sent
* `restrict_paths` means, if not empty, that the jobs sent by this platform will have access restricted to these paths during their execution
* `force_tag` means, if not `-1`, that the tag with this id will be added to all jobs sent by this platform

Platforms need to call the API with their request encoded in a token, passed in a post variable name `token`. This token ([jwt](http://jwt.io/)) has the following characteristics:

* it is encrypted (JWE) with the following parameters (all names are standard):
   * *algorithm:* `RSA-OAEP-256`
   * *encoding:* `A256CBC-HS512`
   * *compression:* `DEF`
   * *key id:* the configuration option `$CFG_key_name` of `www/config.inc.php`
* its payload is a signed token (JWS) with the following parameters:
   * *algorithm:* `RS512`
   * *key id*: the sql field `platforms.name`

The jws token can be verified with the platform public key (`platforms.public_key`) and the jwe can be decrypted with `$CFG_private_key` of `www/config.inc.php`.

Tokens used in the communication with the platform's return url are one the exact same principle.

### Setting up a server
Servers poll the graderqueue for jobs, execute them, then send back the results. They need to be registered as well in the table `servers`:
* `id` and `name` are internal identifiers
* `ssl_serial` and `ssl_dn` are the serial and issuer DN of the client SSL certificate used by the platform
* `wakeup_url` is an URL to call to wake-up the server: if this server hasn't been polling for a minute and the graderqueue has a job for it, it will call the URL
* `type` is the type of the server
* `max_concurrent_jobs` is the maximum number of concurrent jobs this server is allowed to ask for

Servers need to access the graderqueue through HTTPS, using a client SSL
certificate issued by the platform. The script `certs/clientcert.sh` easily
creates such a certificate.

### Setting up tags
Tags are capabilities of a server. If a job is assigned tags, then only
servers who can handle these tags will be given the job.

They're defined in the table `tags`; the `id` is the internal identifier, and
the `name` is the name of the tag used by platforms when sending jobs.

### Setting up server types
A server type corresponds to a set of tags: servers of this type can handle all
tags of this set (and only these tags).

Their name is defined in the table `server_types`, and the tags which can be
handled are defined with pairs in the table `type_tags`.

## API
The API at `api.php` is made for platforms to send jobs and request the
results. It needs to be access through HTTPS with a client SSL certificate, and
parameters must be sent in a POST request.

Note that the interface uses an extra parameter, `token`, allowing it to bypass
the SSL client certificate validation (thus why the interface needs to be
secured in some way).

### test
`test` request allows a platform to test the connection and authentication. No
parameters.

### sendjob
`sendjob` request allows a platform to send a job directly with the JSON data. Parameters:

* `request`: `'sendjob'`
* `jobdata`: (JSON) data of the job to be executed
* `jobname`: (string) name for the job
* `priority`: (integer) priority of the job in the queue
* `tags`: (comma-separated list of strings) tags associated with the job

### sendsolution
`sendsolution` request allows a platform to send the parameters of a job; the
JSON data of that job will in that case be generated with standard parameters
by the API. It is mainly meant for use by the interface. Parameters:

* `request`: `'sendsolution'`
* `jobname`: (string) name for the job
* `priority`: (integer) priority of the job in the queue
* `tags`: (comma-separated list of strings) tags associated with the job
* `solfile` or `solpath` or `solcontent`: solution, either as an uploaded file (`solfile`), as a path local to the grading server (`solpath`) or directly as the content of the solution (`solcontent`)
* `taskpath`: (string) path to the problem
* `memlimit`: (integer) memory limit for the execution of the solution
* `timelimit`: (integer) time limit for the execution of the solution
* `lang`: (string) language of the solution

### getjob
`getjob` requests allows a platform to fetch the status of a job. Parameters:

* `request`: `'getjob'`
* `jobid`: (integer) the ID of the job

It returns a JSON array corresponding to the contents of the table for that
job.

## Interface
Users can access a simple interface at `interface.php`; this interface allows
to send jobs, check the current status of servers and of jobs.

## Graderserver
The folder `graderserver` contains an implementation of a grading server using
this graderqueue. It was made for use with the
[taskgrader](https://github.com/France-ioi/taskgrader), but can be used with
any program accepting the job on its standard input and sending back the
results on its standard output.

Before using it, you need to edit `config.py`, using the template from
`config.py.template`, and supply the client SSL certificate for this server.

Once configured, you can execute `server.py -t` to test the connection and
authentication to the graderqueue.

The command `server.py -s` will launch it in server-mode: it will daemonize and
listen on UDP for wake-up signals from the graderqueue.
