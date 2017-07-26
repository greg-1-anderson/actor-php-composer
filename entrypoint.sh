#!/bin/sh
set -e  # exit if command fails
set +x  # print commands

# cd to the given path within the repo
cd "$(pwd)/$1"

composer install --no-interaction --ignore-platform-reqs --no-scripts

php /usr/src/actor/act.php $1
