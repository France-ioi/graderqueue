#!/usr/bin/env python3
# -*- coding: utf-8 -*-

# Copyright (c) 2015-2016 France-IOI, MIT license
#
# http://opensource.org/licenses/MIT

# This script starts a server, fetching jobs from the graderqueue and sending
# them to the taskgrader.
# See https://github.com/France-ioi/graderqueue .


import argparse, json, logging, os, socket, ssl, sys, subprocess, threading
import time, xml.dom.minidom
import urllib.request, urllib.parse, urllib2_ssl
from config import *

# subprocess.DEVNULL is only present in python 3.3+.
DEVNULL = open(os.devnull, 'w')

def listenWakeup(ev):
    """Listening loop: listen on TCP, set the event ev each time we get a
    wake-up signal."""
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind((CFG_WAKEUP_IP, CFG_WAKEUP_PORT))
    logging.info('Started listening for wake-up signals on udp://%s:%s' % (CFG_WAKEUP_IP, CFG_WAKEUP_PORT))

    while True:
        (data, addr) = sock.recvfrom(1024)
        # TODO :: Replace this by a real authentication
        logging.debug('Wakeup listener received `%s` from %s' % (data, addr))
        if data == b'wakeup':
            logging.info('Received valid wake-up signal.')
            sock.sendto(b'ok', addr)
            ev.set()
        else:
            logging.info('Received invalid wake-up signal.')
            sock.sendto(b'no', addr)


def communicateWithTimeout(subProc, timeout=0, input=None):
    """Communicates with subProc until its completion or timeout seconds,
    whichever comes first."""
    if timeout > 0:
        to = threading.Timer(timeout, subProc.kill)
        try:
            to.start()
            return subProc.communicate(input=input)
        finally:
            to.cancel()
    else:
        return subProc.communicate(input=input)


class IdleWorker(object):
    """Class handling actions to execute when the server is not evaluating any
    task."""

    def __init__(self, repoHand, genJson):
        self.repoHand = repoHand
        self.genJson = genJson
        self.nextExecute = 0

    def execute(self):
        """Execute idle actions."""
        if time.time() > self.nextExecute:
            if self.genJson is not None:
                self.genJson.getVersion()
            self.repoHand.refresh()
            self.nextExecute = time.time() + CFG_IDLEWORKER_INTERVAL


class GenJsonHandler(object):
    """Class handling genJson-related functions."""

    def __init__(self):
        self.genJsonVer = None
        self.getVersion()

    def getVersion(self):
        """Load genJson version id."""
        curVer = self.genJsonVer
        try:
            self.genJsonVer = subprocess.check_output([CFG_GENJSON, '--version'], universal_newlines=True).strip()
        except:
            self.genJsonVer = 'unknown'
        if self.genJsonVer != curVer:
            logging.debug("Local genJson at (new) version '%s'." % self.genJsonVer)

        return self.genJsonVer

    def update(self, taskPath, repoUp=False):
        """Update defaultParams.json for taskPath.
        repoUp means that we update because the repository was updated.
        Returns -1 if no update was needed, else the exit code of genJson."""
        if taskPath == '/':
            return -1

        if repoUp:
            # Repository was updated
            logging.info("Regenerating defaultParams with updated repository...")
        else:
            # Try to get version of the last defaultParams.json
            try:
                taskJsonVer = json.load(open(os.path.join(taskPath, 'defaultParams.json'), 'r'))['genJsonVersion']
            except:
                taskJsonVer = 'none'
            if taskJsonVer == self.genJsonVer:
                # No update needed
                return -1
            logging.info("Regenerating defaultParams with new genJson version...")
            logging.debug("taskJsonVer='%s', genJsonVer='%s'" % (taskJsonVer, self.genJsonVer))

        # Do the update
        gjCode = subprocess.call([CFG_GENJSON, taskPath], stdout=DEVNULL, stderr=DEVNULL)

        return gjCode


class RepositoryHandler(object):
    """Class handling the various repositories and storing data about them in
    memory to improve performance."""

    def _loadRepository(self, repo):
        """Load information about a repository into memory."""
        repo['path'] = os.path.realpath(repo['path']) + '/'

        if 'lastCommit' in repo:
            lastLc = repo['lastCommit']
        else:
            lastLc = None

        # Get last commit of the master repository
        if repo['type'] == 'svn':
            try:
                svnInfo = subprocess.check_output(self.svnCmd + ['info', '--xml', repo['remote']], universal_newlines=True)
                svnInfoXml = xml.dom.minidom.parseString(svnInfo)
                repo['lastCommit'] = svnInfoXml.getElementsByTagName('entry')[0].getAttribute('revision')
            except:
                repo['lastCommit'] = 'unknown'
        else:
            # git lastCommit: git ls-remote [REMOTE]
            # git curCommit: git rev-parse HEAD
            raise Exception("Repository type '%s' not supported.")

        updated = (repo['lastCommit'] != lastLc)
        if updated:
            logging.info("Loaded repository `%s`, lastCommit=%s." % (repo['path'], repo['lastCommit']))

        return updated

    def __init__(self, repositories, commonFolders):
        """Initialize the class, loading information from the repositories and
        updating each commonFolder to the latest version."""
        # SVN command
        self.svnCmd = ['/usr/bin/svn']
        if CFG_SVN_USER:
            self.svnCmd.extend(['--username', CFG_SVN_USER])
        if CFG_SVN_PASS:
            self.svnCmd.extend(['--password', CFG_SVN_PASS])

        # Repositories
        self.repositories = repositories
        # Folders which are always at the latest version
        self.commonFolders = commonFolders
        # Cache for subfolders revisions
        self.subFolders = {}

        self.refresh()

    def refresh(self):
        """Load information from repositories and update commonFolders if
        needed."""
        # Load local repositories
        repoChanged = False
        for repo in self.repositories:
            repoChanged = repoChanged or self._loadRepository(repo)

        # Update commonFolders only if a repository version changed
        if repoChanged:
            for folder in self.commonFolders:
                self.update(folder)

    def update(self, folder, rev='HEAD'):
        """Update a folder to revision 'rev'."""
        foldPath = os.path.realpath(folder.replace('$ROOT_PATH', CFG_GRADERQUEUE_ROOT)) + '/'
        curRepo = None
        for repo in self.repositories:
            if os.path.commonprefix([repo['path'], foldPath]) == repo['path']:
                curRepo = repo
                break

        # This folder is not part of a repository
        if not curRepo:
            # return False if a specific revision was asked, as it means it was
            # supposed to be part of a repository
            logging.info("No repository found for folder `%s`." % folder)
            if rev == 'HEAD':
                return False
            else:
                raise Exception("Failure updating task `%s` to revision `%s`, no repository found." % (fold, rev))

        logging.debug("Repository found for folder `%s`, at path `%s`." % (folder, repo['path']))

        if foldPath in self.subFolders:
            # Load revision from runtime cache
            foldRev = self.subFolders[foldPath]
        else:
            if curRepo['type'] == 'svn':
                # Get current version of folder
                try:
                    svnv = subprocess.check_output(['/usr/bin/svnversion', foldPath], universal_newlines=True)
                except:
                    svnv = ''
                foldRev = ''
                # Check the revision number is all digits (and maybe a 'P' for
                # people who don't have access to the whole repository)
                for c in svnv.strip():
                    if c.isdigit():
                        foldRev += c
                    elif c != 'P':
                        # It's not all digits, it indicates that not all files
                        # are in the same revision
                        foldRev = None
                        break

        # Check if revision is the target revision
        if rev == 'HEAD' and repo['lastCommit'] != 'unknown':
            targetRev = repo['lastCommit']
        else:
            targetRev = rev

        if foldRev != targetRev:
            logging.info("Folder revision='%s', target='%s'" % (foldRev, targetRev))
            if curRepo['type'] == 'svn':
                # Update folder to rev
                logging.info("Updating folder to target revision...")
                svnup = subprocess.call(self.svnCmd + ['update', '-r', rev, foldPath], stdout=DEVNULL, stderr=DEVNULL)
                if svnup > 0:
                    # Failure, we return without updating
                    logging.warning("Failure updating task `%s`." % foldPath)
                    raise Exception("Failure updating task `%s` to revision `%s`." % (foldPath, rev))
                else:
                    # Success, we check if the revision is further than the
                    # recorded lastCommit
                    try:
                        if int(targetRev) > int(repo['lastCommit']):
                            self._loadRepository(repo)
                    except:
                        pass

        else:
            return False

        # Save new revision into runtime cache
        self.subFolders[foldPath] = targetRev
        return True


def testConnection(opener):
    """Test the connection to the graderqueue.
    opener must be an urllib opener."""
    print("Testing connection with the graderqueue at URL `%s`..." % CFG_GRADERQUEUE_TEST)
    r = opener.open(CFG_GRADERQUEUE_TEST).read().decode('utf-8')

    logging.debug("Received: %s" % r)

    try:
        jsondata = json.loads(r)
    except:
        print("Error: received invalid JSON data. Test failed.")
        return 1

    if jsondata['errorcode'] == 0:
        print("Test successful, received answer: (#%d) %s" % (jsondata['errorcode'], jsondata['errormsg']))
        return 0
    else:
        print("Test failed, received answer: (#%d) %s" % (jsondata['errorcode'], jsondata['errormsg']))
        return 1


def checkServer():
    """Check the server is not already started.
    True means it is not already started."""
    try:
        pid = int(open(CFG_SERVER_PIDFILE, 'r').read())
    except:
        pid = 0
    if pid > 0:
        try:
            os.kill(pid, 0)
        except OSError as err:
            if err.errno == 1:
                print("Server exists as another user. Exiting.")
                return False
        else:
            print("Server already launched. Exiting.")
            return False

    return True


if __name__ == '__main__':
    # Read command line options
    argParser = argparse.ArgumentParser(description="Launches an evaluation server for use with the graderQueue.")

    argParser.add_argument('-d', '--debug', help='Shows all the JSON data in and out (implies -v)', action='store_true')
    argParser.add_argument('-D', '--daemon', help='Daemonize the process (incompatible with -v)', action='store_true')
    argParser.add_argument('-l', '--listen', help='Listen on UDP and wait for a wake-up signal', action='store_true')
    argParser.add_argument('-L', '--logfile', help='Write logs into file LOGFILE', action='store', metavar='LOGFILE')
    argParser.add_argument('-s', '--server', help='Server mode; start only if not already started (implies -D)', action='store_true')
    argParser.add_argument('-t', '--testconnection', help='Test connection with the graderqueue (exits after testing)', action='store_true')
    argParser.add_argument('-T', '--testbehavior', help='Test the graderqueue through different behaviors', default=0, type=int, choices=range(0, 7))
    argParser.add_argument('-v', '--verbose', help='Be more verbose', action='store_true')

    args = argParser.parse_args()

    # Some options imply others
    args.daemon = args.daemon or args.server
    args.verbose = args.verbose or args.debug

    # Check daemon and verbose are not enabled together
    if args.daemon and args.verbose:
        logging.critical("Can't daemonize while verbose mode is enabled.")
        argParser.print_help()
        sys.exit(1)

    # Add configuration from config.py
    if CFG_LOGFILE and not args.logfile:
        args.logfile = CFG_LOGFILE

    # Set logging options
    logLevel = getattr(logging, CFG_LOGLEVEL, logging.CRITICAL)
    if args.debug: logLevel = min(logLevel, logging.DEBUG)
    if args.verbose: logLevel = min(logLevel, logging.INFO)

    logConfig = {'level': logLevel,
        'format': '%(asctime)s - graderserver - %(levelname)s - %(message)s'}
    if args.logfile: logConfig['filename'] = args.logfile
    logging.basicConfig(**logConfig)

    if args.logfile and args.verbose:
        # Also show messages on stderr
        logStderr = logging.StreamHandler()
        logStderr.setFormatter(logging.Formatter('%(asctime)s - graderserver - %(levelname)s - %(message)s'))
        logging.getLogger().addHandler(logStderr)

    # HTTPS layer
    opener = urllib.request.build_opener(urllib2_ssl.HTTPSHandler(
            key_file=CFG_SSL_KEY,
            cert_file=CFG_SSL_CERT,
            ca_certs=CFG_SSL_CA,
            server_hostname=CFG_SSL_HOSTNAME,
            checker=CFG_SSL_CHECKER))

    # Test mode: try communicating with the graderqueue
    if args.testconnection:
        success = testConnection(opener)
        sys.exit(success)

    # Server-mode: launch only if not already started
    if args.server:
        if not checkServer():
            sys.exit(1)

    # Daemonize
    if args.daemon:
        if os.fork() > 0:
            sys.exit(0)
        devnull = os.open(os.devnull, os.O_RDWR)
        os.dup2(devnull, 0)
        os.dup2(devnull, 1)
        os.dup2(devnull, 2)
        os.chdir("/")
        os.setsid()
        os.umask(0)
        if os.fork() > 0:
            sys.exit(0)

    # Write new PID
    if args.server:
        open(CFG_SERVER_PIDFILE, 'w').write(str(os.getpid()))

    # Launch a thread to listen on the UDP port
    # The Event allows to tell when a wakeup signal has been received
    if args.listen:
        wakeupEvent = threading.Event()

        wakeupThread = threading.Thread(target=listenWakeup, kwargs={'ev': wakeupEvent})
        wakeupThread.setDaemon(True)
        wakeupThread.start()

    # Initialize RepositoryHandler, loading information from repositories
    logging.info('Server initialization...')
    repoHand = RepositoryHandler(CFG_REPOSITORIES, CFG_COMMONFOLDERS)

    # Create GenJsonHandler
    if CFG_GENJSON:
        genJson = GenJsonHandler()
    else:
        genJson = None

    # Create IdleWorker
    idleWorker = IdleWorker(repoHand, genJson)

    while(True):
        # Main polling loop
        # Will terminate after a poll without any available job or an error

        # Clear the wake-up signal
        if args.listen:
            wakeupEvent.clear()

        # Request data from the graderqueue
        logging.info('Polling the graderqueue at `%s`...' % CFG_GRADERQUEUE_POLL)
        # nbtasks=0 means we don't currently have any tasks active
        try:
            r = opener.open(CFG_GRADERQUEUE_POLL,
                data=urllib.parse.urlencode({'nbtasks': 0}).encode('utf-8')).read().decode('utf-8')
        except Exception as e:
            logging.critical('Error while polling queue: %s' % str(e))
            logging.info('Waiting 3 seconds before new poll...')
            time.sleep(3)
            continue

        try:
            jsondata = json.loads(r)
        except:
            logging.critical('Error: Taskqueue returned non-JSON data.')
            logging.debug('Received: %s' % r)
            logging.info('Waiting 3 seconds before new poll...')
            time.sleep(3)
            continue

        if 'errorcode' not in jsondata:
            logging.critical('Error: Taskqueue returned data without errorcode.')
            logging.debug('Received: %s' % r)
            logging.info('Waiting 3 seconds before new poll...')
            time.sleep(3)
            continue

        # Handle various possible errors
        if jsondata['errorcode'] == 1:
            logging.info('Taskqueue has no available job.')

            if args.listen:
                # Wait for a wake-up signal
                while not wakeupEvent.wait(3):
                    # We use a timeout to keep the main thread responsive to interruptions
                    idleWorker.execute()
                logging.info('Received wake-up signal.')
                continue
            elif args.server:
                # Poll again
                logging.info('Waiting 1 second before new poll...')
                time.sleep(1)
                continue
            else:
                # We didn't receive any job, exit
                logging.info("No job available, exiting.")
                break

        elif jsondata['errorcode'] == 2:
            logging.critical('Error: Taskqueue returned an error (%s)' % jsondata['errormsg'])
            sys.exit(1)
        elif jsondata['errorcode'] == 3:
            logging.critical('Error: Authentication failed (%s)' % jsondata['errormsg'])
            sys.exit(1)
        elif jsondata['errorcode'] != 0:
            logging.critical('Error: Taskqueue returned an unknown errorcode (%s): %s' % (jsondata['errorcode'], jsondata['errormsg']))
            sys.exit(1)
        elif not ('jobdata' in jsondata and 'jobname' in jsondata and 'jobid' in jsondata):
            logging.critical('Error: Taskqueue returned no jobdata.')
            sys.exit(1)

        jobdata = jsondata['jobdata']
        logging.info('Received job `%s` (#%d)' % (jsondata['jobname'], jsondata['jobid']))

        if args.testbehavior == 1:
            # Test behavior: don't send back any results, poll again right away
            logging.info('Test behavior 1; dropping job, starting new poll...')
            continue
        elif args.testbehavior == 2:
            # Test behavior: wait 60 seconds before executing evaluation
            logging.info('Test behavior 2; waiting 60 seconds...')
            time.sleep(60)

        # Log some information to send back to the graderqueue
        errorMsg = ""

        # Handle paths
        jobdata['rootPath'] = CFG_GRADERQUEUE_ROOT
        taskPath = jobdata['taskPath'].replace('$ROOT_PATH', CFG_GRADERQUEUE_ROOT)
        if 'restrictToPaths' in jobdata:
            jobdata['restrictToPaths'] = [Template(p).safe_substitute(CFG_GRADERQUEUE_VARS) for p in jobdata['restrictToPaths']]
            jobdata['restrictToPaths'].extend(CFG_SERVER_RESTRICT)
        elif CFG_SERVER_RESTRICT:
            jobdata['restrictToPaths'] = CFG_SERVER_RESTRICT

        # Update repository if needed
        repoUp = False
        if jsondata.get('taskrevision', ''):
            logging.info("Updating `%s` to revision '%s' if needed..." % (taskPath, jsondata['taskrevision']))
            try:
                repoUp = repoHand.update(taskPath, rev=jsondata['taskrevision'])
            except:
                logging.warning("Couldn't update task `%s` to revision '%s'." % (taskPath, jsondata['taskrevision']))
                errorMsg += "Couldn't update task `%s` to revision '%s'.\n" % (jobdata['taskPath'], jsondata['taskrevision'])
                repoUp = False
            if repoUp:
                logging.info("Updated `%s` to revision '%s' sucessfully." % (taskPath, jsondata['taskrevision']))
            else:
                logging.info("No modification.")

        # (Re)generate defaultParams.json if needed
        if CFG_GENJSON:
            gjCode = genJson.update(taskPath, repoUp=repoUp)
            if gjCode == 0:
                logging.info("Regeneration successful.")
            elif gjCode == 2:
                logging.warning("Non-fatal error while regenerating defaultParams for task `%s`, exitcode=%d." % (taskPath, gjCode))
                errorMsg += "Non-fatal error while regenerating defaultParams for task `%s`, exitcode=%d.\n" % (jobdata['taskPath'], gjCode)
            elif gjCode > 0:
                logging.warning("Error while regenerating defaultParams for task `%s`, exitcode=%d." % (taskPath, gjCode))
                errorMsg += "Error while regenerating defaultParams for task `%s`, exitcode=%d.\n" % (jobdata['taskPath'], gjCode)

        logging.debug('JSON to be sent to taskgrader: ```\n%s\n```' % json.dumps(jobdata))

        # Command-line to execute as taskgrader
        cmdline = [CFG_TASKGRADER]
        if args.testbehavior == 6:
            # Test behavior 6: set results as input JSON
            logging.info("Test behavior 6; using cat as taskgrader...")
            cmdline = ['/bin/cat']

        # Send to taskgrader
        proc = subprocess.Popen(cmdline, stdin=subprocess.PIPE,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
        (procOut, procErr) = communicateWithTimeout(proc, timeout=CFG_TIMEOUT, input=json.dumps(jobdata))
        logging.debug('* Output from taskgrader:')
        logging.debug('stdout: ```\n%s\n```' % procOut)
        logging.debug('stderr: ```\n%s\n```' % procErr)

        # Read taskgrader output
        try:
            evalJson = json.loads(procOut)
        except:
            evalJson = None

        if evalJson:
            logging.info("Taskgrader execution successful.")
            # Log a summary of the execution results
            try:
                for execution in evalJson['executions']:
                    logging.debug(' * Execution %s:' % execution['name'])
                    for report in execution['testsReports']:
                        if 'checker' in report:
                            # Everything was executed
                            logging.info('Solution executed successfully.')
                            logging.debug(report['checker']['stdout']['data'])
                        elif 'execution' in report:
                            # Solution error
                            logging.info('Solution returned an error.')
                            logging.debug(json.dumps(report['execution']))
                        else:
                            # Sanitizer error
                            logging.info('Test rejected by sanitizer.')
                            logging.debug(json.dumps(report['sanitizer']))
            except:
                pass
            if args.debug:
                logging.debug('* Full report:')
                logging.debug(json.dumps(evalJson))

            # Make data to send back
            errorMsg += "Taskgrader executed successfully.\n"
            respData = {
                'jobid': jsondata['jobid'],
                'resultdata': json.dumps({
                    'errorcode': 0,
                    'errormsg': errorMsg,
                    'jobdata': evalJson
                    })}

        else:
            logging.info("Taskgrader error.")

            # errorCode = 1 means general error, abandon // 2 means temporary error, retry
            errorCode = max(1, proc.returncode)
            if errorCode == 1:
                errorMsg += "Fatal taskgrader error.\nstdout:\n%s\nstderr:\n%s" % (procOut, procErr)
            elif errorCode == 2:
                errorMsg += "Temporary taskgrader error.\nstdout:\n%s\nstderr:\n%s" % (procOut, procErr)

            # Send back the error
            respData = {
                'jobid': jsondata['jobid'],
                'resultdata': json.dumps({
                    'errorcode': errorCode,
                    'errormsg': errorMsg
                })}

        if args.testbehavior == 3:
            # Test behavior 3: report results as a fatal error
            logging.info("Test behavior 3; reporting results as a fatal error...")
            respData['resultdata']['errorcode'] = 1
        elif args.testbehavior == 4:
            # Test behavior 4: report results as a temporary error
            logging.info("Test behavior 4; reporting results as a fatal error...")
            respData['resultdata']['errorcode'] = 2
        elif args.testbehavior == 5:
            # Test behavior 5: report erroneous results
            logging.info("Test behavior 5; removing resultdata from the results sent back...")
            respData = {'jobid': respData['jobid']}

        # Try multiple times to send back results
        respTries = 0
        while respTries < 3:
            # Send back results
            logging.info("Sending results back to `%s`..." % CFG_GRADERQUEUE_SEND)
            try:
                resp = opener.open(CFG_GRADERQUEUE_SEND, data=urllib.parse.urlencode(respData).encode('utf-8')).read().decode('utf-8')
                logging.info("Sent results.")
            except Exception as e:
                logging.critical("Error while sending back results: %s" % str(e))
                respTries += 1
                logging.debug("Waiting 3 seconds before retrying...")
                time.sleep(3)
                continue

            try:
                respJson = json.loads(resp)
                logging.info("Taskqueue response: (%d) %s" % (respJson['errorcode'], respJson['errormsg']))
                if respJson['errorcode'] == 0:
                    break
            except:
                logging.critical("Error: Taskqueue answered results with invalid data (%s)" % resp)
            respTries += 1
            logging.debug("Waiting 3 seconds before retrying...")
            time.sleep(3)
