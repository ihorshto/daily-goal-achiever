#!/bin/sh
# Runs once on first init of the data volume. Creates an isolated database + role for Mem0
# and installs pgvector there (as superuser), so the mem0 role needs no elevated privileges.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE ROLE mem0 LOGIN PASSWORD '${MEM0_PG_PASSWORD}';
CREATE DATABASE mem0 OWNER mem0;
REVOKE ALL ON DATABASE mem0 FROM PUBLIC;
REVOKE ALL ON DATABASE ${POSTGRES_DB} FROM PUBLIC;
SQL
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname mem0 -c "CREATE EXTENSION IF NOT EXISTS vector;"
