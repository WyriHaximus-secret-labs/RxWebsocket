cd test/ab

docker run --rm \
      -d \
      -v ${PWD}:/config \
      -v ${PWD}/reports:/reports \
      -p 9001:9001 \
      --name rxwsfuzzingserver \
      crossbario/autobahn-testsuite wstest -m fuzzingserver -s /config/fuzzingserver.json
sleep 10
php -d memory_limit=256M clientRunner.php

docker stop rxwsfuzzingserver


sleep 5

php testServer.php 600 &
sleep 3

docker run --rm \
       \
      -v ${PWD}:/config \
      -v ${PWD}/reports:/reports \
      -p 9001:9001 \
      --name rxwsfuzzingclient \
      crossbario/autobahn-testsuite wstest -m fuzzingclient -s /config/fuzzingclient.json
sleep 12
