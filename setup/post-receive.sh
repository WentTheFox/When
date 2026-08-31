#!/usr/bin/env bash
echo "##### post-receive hook #####"
read oldrev newrev refname
echo "Push triggered update to revision $newrev ($refname)"

RUN_FOR_REF="refs/heads/main"
if [[ "$refname" == "$RUN_FOR_REF" ]]; then
    GIT="env -i git"
    APP_DIR="$(readlink -nf "$PWD/..")"
    cd "${APP_DIR}"

    CMD_FETCH="timeout 15 $GIT fetch"
    CMD_COMPOSER="composer install --optimize-autoloader --no-dev"
    CMD_LARAVEL_DOWN="php artisan down"
    CMD_MIGRATE="php artisan migrate --force"
    CMD_NPM="pnpm install --frozen-lockfile"
    CMD_BUILD="pnpm build"
    CMD_OPTIMIZE="php artisan optimize"
    CMD_HORIZON_RESTART="sudo systemctl restart whenthefox-horizon.service"
    CMD_LARAVEL_UP="php artisan up"

    echo "$ $CMD_FETCH"; eval ${CMD_FETCH}

    if $GIT diff --name-only $oldrev $newrev | grep -q "^composer.lock"; then
        echo "$ $CMD_COMPOSER"; eval ${CMD_COMPOSER}
    else
        echo "# Skipping composer install, lockfile not modified"
    fi

    # Maintenance mode only around the parts that touch running state.
    echo "$ $CMD_LARAVEL_DOWN"; eval ${CMD_LARAVEL_DOWN}
    echo "$ $CMD_MIGRATE"; eval ${CMD_MIGRATE}

    if $GIT diff --name-only $oldrev $newrev | grep -q "^pnpm-lock.yaml"; then
        echo "$ $CMD_NPM"; eval ${CMD_NPM}
    else
        echo "# Skipping pnpm install, lockfile not modified"
    fi

    if $GIT diff --name-only $oldrev $newrev | grep -qE "^resources/|^pnpm-lock.yaml"; then
        echo "$ $CMD_BUILD"
        if eval ${CMD_BUILD}; then
            echo "Build successful"
        else
            echo "Build failed"; BUILD_FAILED=1
        fi
    else
        echo "# Skipping build, no changes in resources/ folder"
    fi

    echo "$ $CMD_OPTIMIZE"; eval ${CMD_OPTIMIZE}
    echo "$ $CMD_HORIZON_RESTART"; eval ${CMD_HORIZON_RESTART}
    echo "$ $CMD_LARAVEL_UP"; eval ${CMD_LARAVEL_UP}

    [[ -n "$BUILD_FAILED" ]] && exit 1
else
    echo "Ref does not match $RUN_FOR_REF, exiting."
fi

echo "##### end post-receive hook #####"
