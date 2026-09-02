#!/bin/sh
# Wrapper for MySQL client/server in user-space sandbox.
BASE=/home/node/.openclaw/workspace
TOOLS=$BASE/tools
MYSQL_ROOT=$TOOLS/mysql/mysql-8.4.5-linux-glibc2.28-x86_64-minimal
export LD_LIBRARY_PATH="$TOOLS/libaio/usr/lib/x86_64-linux-gnu:$TOOLS/libnuma/usr/lib/x86_64-linux-gnu:$TOOLS/libncurses/lib/x86_64-linux-gnu:$LD_LIBRARY_PATH"

case "$(basename "$0")" in
  mysqld.sh) exec "$MYSQL_ROOT/bin/mysqld" --no-defaults \
      --basedir="$MYSQL_ROOT" \
      --datadir="$TOOLS/mysql-data" \
      --socket="$TOOLS/mysql-run/mysqld.sock" \
      --port=3307 --bind-address=127.0.0.1 \
      --pid-file="$TOOLS/mysql-run/mysqld.pid" \
      --log-error="$TOOLS/mysql-run/error.log" "$@" ;;
  mysql.sh) exec "$MYSQL_ROOT/bin/mysql" --no-defaults --socket="$TOOLS/mysql-run/mysqld.sock" "$@" ;;
  mysqladmin.sh) exec "$MYSQL_ROOT/bin/mysqladmin" --no-defaults --socket="$TOOLS/mysql-run/mysqld.sock" "$@" ;;
esac
