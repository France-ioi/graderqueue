#!/bin/bash

if [ -f serial ]
then
  SERIAL=$((`cat serial`+1))
  SERIAL="`printf %02d $SERIAL`"
else
  SERIAL="01"
fi

echo "*** Creating client certificate..."

openssl genrsa -out client$SERIAL.key 2048
openssl req -new -key client$SERIAL.key -out client$SERIAL.csr
openssl x509 -req -days 7300 -in client$SERIAL.csr -CA graderqueueCA.crt -CAkey graderqueueCA.key -set_serial $SERIAL -out client$SERIAL.crt
tar c graderqueueCA.crt client$SERIAL.key client$SERIAL.crt > client$SERIAL.tar

echo $SERIAL > serial

DN=`openssl x509 -in client$SERIAL.crt -text -noout | grep Issuer | sed 's/^.*: /\//' | sed 's/, /\//g'`

echo "Done! Send the archive client$SERIAL.tar to the client and add him to"
echo "the relevant table with ssl_serial='$SERIAL' and ssl_dn='$DN'."
