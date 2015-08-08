#!/bin/bash
# Check configuration file has been written
if ! [ -f www/config.inc.php ]
then
  echo "Please configure the graderqueue in the file config.inc.php first,"
  echo "using the template provided in config.inc.php.template."
#  exit 1
fi

# Create DB
echo "*** Setting up database..."

DB_HOST=`grep CFG_db_hostname www/config.inc.php | head -n 1 | sed 's/^.*\"\(.*\)\".*$/\1/'`
DB_USER=`grep CFG_db_user www/config.inc.php | head -n 1 | sed 's/^.*\"\(.*\)\".*$/\1/'`
DB_PASS=`grep CFG_db_password www/config.inc.php | head -n 1 | sed 's/^.*\"\(.*\)\".*$/\1/'`
DB_DB=`grep CFG_db_database www/config.inc.php | head -n 1 | sed 's/^.*\"\(.*\)\".*$/\1/'`

mysql --host=$DB_HOST --user=$DB_USER --password=$DB_PASS --database=$DB_DB < schema.sql
