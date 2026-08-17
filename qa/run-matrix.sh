#!/usr/bin/env sh
set -eu

QA_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
DATA_DIR=$(CDPATH= cd -- "$QA_DIR/.." && pwd)
MONGO_DIR=$(CDPATH= cd -- "$DATA_DIR/../phpaml-data-mongodb" && pwd)

docker compose -f "$QA_DIR/compose.yml" up -d --wait
trap 'docker compose -f "$QA_DIR/compose.yml" down -v' EXIT INT TERM

wait_for_port() {
    port=$1
    attempts=0
    until nc -z 127.0.0.1 "$port"; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "Port $port is not reachable from the host." >&2
            return 1
        fi
        sleep 1
    done
}

wait_for_port 13306
wait_for_port 33061
wait_for_port 54320
wait_for_port 27017

AML_DATA_MYSQL_DSN='mysql:host=127.0.0.1;port=13306;dbname=phpaml_data_test;charset=utf8mb4' \
AML_DATA_MYSQL_USER='phpaml' AML_DATA_MYSQL_PASSWORD='phpaml_test' \
AML_DATA_MARIADB_DSN='mysql:host=127.0.0.1;port=33061;dbname=phpaml_data_test;charset=utf8mb4' \
AML_DATA_MARIADB_USER='phpaml' AML_DATA_MARIADB_PASSWORD='phpaml_test' \
AML_DATA_PGSQL_DSN='pgsql:host=127.0.0.1;port=54320;dbname=phpaml_data_test' \
AML_DATA_PGSQL_USER='phpaml' AML_DATA_PGSQL_PASSWORD='phpaml_test' \
php "$DATA_DIR/tests/databases.php"

AML_DATA_MONGODB_URI='mongodb://127.0.0.1:27017/?replicaSet=rs0&directConnection=true' \
AML_DATA_MONGODB_DATABASE='phpaml_data_test' \
php "$MONGO_DIR/tests/server.php"
