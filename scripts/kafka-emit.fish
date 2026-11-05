#!/usr/bin/env fish
# Usage: kafka-emit.fish <topic> <json>

if test (count $argv) -lt 2
  echo "Usage: kafka-emit.fish <topic> <json>"
  exit 1
end

set topic $argv[1]
set json $argv[2]

echo $json | docker compose -f ~/OmniTix/docker-compose.yml exec -T kafka \
  /opt/kafka/bin/kafka-console-producer.sh \
  --bootstrap-server localhost:9092 \
  --topic $topic