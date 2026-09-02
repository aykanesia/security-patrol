#!/bin/sh
# MySQL user-space environment for dev sandbox (no root needed).
# Source this file:  . ./mysql-env.sh   (from repo root)
# Or run:  ./scripts/mysql.sh <args...>
#          ./scripts/mysqld.sh <args...>

BASE=/home/node/.openclaw/workspace
TOOLS=$BASE/tools
MYSQL_ROOT=$TOOLS/mysql/mysql-8.4.5-linux-glibc2.28-x86_64-minimal

export LD_LIBRARY_PATH="$TOOLS/libaio/usr/lib/x86_64-linux-gnu:$TOOLS/libnuma/usr/lib/x86_64-linux-gnu:$TOOLS/libncurses/lib/x86_64-linux-gnu:$LD_LIBRARY_PATH"
export MYSQL_BIN="$MYSQL_ROOT/bin"
export MYSQL_SOCKET="$TOOLS/mysql-run/mysqld.sock"
export MYSQL_PORT=3307
