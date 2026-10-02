#!/bin/sh
# serve (default): the schema into SQLite, the bundles' public files, the
# container compiled, then a plain PHP dev server on :8000.
# check: the container and the templates linted, the plugins' routes counted.
# console ...: bin/console.
# test <plugin> [args]: the plugin's PHPUnit suite (vendor/omnibase/<plugin>/phpunit.xml.dist),
# or this bundle's with no plugin named.
set -e
cd /srv/demo
case "${1:-serve}" in
    serve)
        rm -rf var/cache/*
        php bin/console doctrine:schema:create --no-interaction 2>/dev/null || php bin/console doctrine:schema:update --force --complete --no-interaction
        php bin/console assets:install public --no-interaction
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
            ""|-*) exec vendor/bin/phpunit -c vendor/glitchr/omnibase/phpunit.xml.dist "$@" ;;
            *) plugin=$1; shift; exec vendor/bin/phpunit -c "vendor/omnibase/$plugin/phpunit.xml.dist" "$@" ;;
        esac ;;
    *) exec "$@" ;;
esac
