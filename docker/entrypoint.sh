#!/bin/sh
# storage/ is a mounted volume in the cluster, so the skeleton Laravel needs may be missing on first start.
set -e
mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache
exec "$@"
