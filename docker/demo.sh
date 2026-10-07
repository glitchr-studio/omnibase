#!/bin/sh
# serve (default): the schema into SQLite, the bundles' public files, the
# container compiled, then a plain PHP dev server on :8000.
# check: the container and the templates linted, the plugins' routes counted.
# console ...: bin/console.
# test <plugin> [args]: the plugin's PHPUnit suite (vendor/omnibase/<plugin>/phpunit.xml.dist),
# or this bundle's with no plugin named, in the test environment (.env.test)
# on a fresh SQLite database.
set -e
cd /srv/demo
case "${1:-serve}" in
    serve)
        rm -rf var/cache/*
        php bin/console doctrine:schema:create --no-interaction 2>/dev/null || php bin/console doctrine:schema:update --force --complete --no-interaction
        php bin/console assets:install public --no-interaction
        # Warmed again now the bundles' files are there: the commands above
        # booted the kernel, and the webpack warmer, before they were. The
        # shared pools too (var/share: the router's compiled routes), which
        # outlive the container in the var volume.
        rm -rf var/cache/* var/share/*
        php bin/console cache:warmup
        exec php -S 0.0.0.0:8000 -t public ;;
    check)
        rm -rf var/cache/*
        php bin/console lint:container
        php bin/console lint:twig templates
        for p in marketplace_ forum_ mailbox_ forge_ git_ admin; do printf '%-12s %s routes\n' "$p" "$(php bin/console debug:router --format=json | grep -c "\"$p")"; done ;;
    console) shift; exec php bin/console "$@" ;;
    test)
        shift
        case "${1:-}" in
            ""|-*) suite=vendor/glitchr/omnibase ;;
            *) suite="vendor/omnibase/$1"; shift ;;
        esac
        # The test environment (.env then .env.test, see tests/harness.php),
        # a fresh container and a fresh SQLite database with the schema of
        # every entity the bundles map.
        export APP_ENV=test
        export HARNESS_SUITE_BOOTSTRAP="$suite/tests/bootstrap.php"
        # The bundles' public files, linked, before the cache is made: a page
        # asked of the kernel links its scripts and stylesheets only when the
        # webpack warmer found them (bundles/base/entrypoints.json).
        php bin/console assets:install public --symlink --env=test --no-interaction --quiet
        # The demonstration's tests compile theirs without debug
        # (var/cache/<env>_demo_test): nothing refreshes it, and var/ is a
        # volume that outlives the run.
        # The pools of var/share (the router's compiled routes) last as long:
        # a route added since was never generated.
        rm -rf var/cache/test var/cache/*_demo_test var/share/test var/share/demo var/test.db
        php bin/console doctrine:schema:create --env=test --no-interaction --quiet
        exec vendor/bin/phpunit -c "$suite/phpunit.xml.dist" --bootstrap tests/harness.php "$@" ;;
    *) exec "$@" ;;
esac
