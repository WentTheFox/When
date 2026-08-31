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

    # On a brand new branch (first-ever push into this repo), $oldrev is the
    # all-zero SHA — `git diff` against it fails ("fatal: bad object"), which
    # would make every diff-based check below silently skip, leaving vendor/
    # and built assets missing entirely. Treat that case as "everything
    # changed" instead of trying to diff against a SHA that doesn't exist.
    if [[ "$oldrev" =~ ^0+$ ]]; then
        DIFFED_FILES=""
        FIRST_PUSH=1
    else
        DIFFED_FILES="$($GIT diff --name-only $oldrev $newrev)"
    fi

    if [[ -n "$FIRST_PUSH" ]] || grep -q "^composer.lock" <<< "$DIFFED_FILES"; then
        echo "$ $CMD_COMPOSER"; eval ${CMD_COMPOSER}
    else
        echo "# Skipping composer install, lockfile not modified"
    fi

    # Maintenance mode only around the parts that touch running state.
    echo "$ $CMD_LARAVEL_DOWN"; eval ${CMD_LARAVEL_DOWN}
    echo "$ $CMD_MIGRATE"; eval ${CMD_MIGRATE}

    if [[ -n "$FIRST_PUSH" ]] || grep -q "^pnpm-lock.yaml" <<< "$DIFFED_FILES"; then
        echo "$ $CMD_NPM"; eval ${CMD_NPM}
    else
        echo "# Skipping pnpm install, lockfile not modified"
    fi

    if [[ -n "$FIRST_PUSH" ]] || grep -qE "^resources/|^pnpm-lock.yaml" <<< "$DIFFED_FILES"; then
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
