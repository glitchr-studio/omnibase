# syntax=docker/dockerfile:1
#
# glitchr/omnibase with every omnibase/* plugin, as one runnable Symfony
# application: this checkout installed as the bundle, the plugins from
# Packagist (their development branches), a SQLite database, a demo page
# that seeds members, a shop and signs you in - then the shop (/boutiques),
# the forum (/bbs), the mailbox (/messagerie), the forge (/support,
# /compte/licences), the repository viewer (/git) and the back office
# (/admin). Built from the repository root, see docker/compose.yml.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git openssh-client unzip libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev libxslt1-dev libmagickwand-dev libgit2-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl zip gd bcmath ftp xsl exif \
    && pecl install imagick igbinary \
    && docker-php-ext-enable imagick igbinary

# php-git2 (libgit2 bindings): git/git-bundle, the forge's repository viewer,
# reads every repository in-process through it.
RUN git clone --depth 1 https://github.com/RogerGee/php-git2.git /usr/src/php-git2 \
    && cd /usr/src/php-git2 \
    && phpize \
    && ./configure --with-libgit2=/usr \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable git2 \
    && rm -rf /usr/src/php-git2

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- bare Symfony skeleton ---------------------------------------------------
WORKDIR /srv/demo
RUN composer create-project symfony/skeleton . --no-interaction --no-progress

# The glitchr packages are branch releases (N.x-dev); plugins patch vendor
# code at install time (doctrine-dc2type restores DC2Type column comments on
# DBAL 4, base-plugin hooks third-party packages).
RUN composer config minimum-stability dev \
    && composer config prefer-stable true \
    && composer config extra.symfony.allow-contrib true \
    && composer config allow-plugins.glitchr/base-plugin true \
    && composer config allow-plugins.glitchr/doctrine-dc2type true \
    && composer config allow-plugins.endroid/installer true \
    && composer config allow-plugins.php-http/discovery true

# --- this checkout becomes the installed bundle, the plugins come from Packagist
# (.dockerignore keeps .git, node_modules, var and vendor out of the context)
COPY . /srv/omnibase
ARG PLUGINS="omnibase/admin omnibase/marketplace omnibase/forge omnibase/forum omnibase/mailbox omnibase/docs"
# --no-scripts: Flex recipes and cache:clear would compile the container
# against recipe defaults BEFORE the demo config overlay below exists. The
# overlay ships every config file the demo needs; the container is compiled
# at first run instead. symfony/translation is required by the bundle's
# Translator without being declared by it.
# The shop pays through glitchr/omnitrade (its Stripe provider here) and
# ships through glitchr/omnibus (two carriers here): the sibling families,
# from Packagist, their development branches - the marketplace follows
# omnitrade's 1.x (its catalogue: Model\Product, Stock, Registry::options()).
# A layer cached from an older build keeps an older 1.x: `docker compose
# build --build-arg OMNI_REFRESH=$(date +%s)` refetches it.
ARG OMNI_REFRESH=0
RUN echo "omni families as of ${OMNI_REFRESH}" \
    && composer config repositories.omnibase '{"type": "path", "url": "/srv/omnibase", "options": {"symlink": false, "versions": {"glitchr/omnibase": "3.x-dev"}}}' \
    && composer require "glitchr/omnibase:3.x-dev as 3.0.x-dev" $(for p in $PLUGINS; do printf '%s:*@dev ' "$p"; done) \
        "glitchr/omnitrade:1.x-dev" "omnitrade/stripe:1.x-dev" "glitchr/omnibus:2.x-dev" "omnibus/offline:2.x-dev" "omnibus/mondial-relay:2.x-dev" \
        omnipay/stripe symfony/translation symfony/messenger symfony/lock \
        dompdf/dompdf horstoeko/zugferd --no-interaction --no-progress --no-scripts \
    # The guard of the forms (Base\Service\FormGuard): glitchr/omniguard and three of its gateways,
    # from their repositories - not on Packagist yet; cloned over https (no-api: no GitHub token here).
    && for r in omniguard omniguard-akismet omniguard-altcha omniguard-disposable; do composer config "repositories.$r" "{\"type\": \"vcs\", \"url\": \"https://github.com/glitchr-studio/$r.git\", \"no-api\": true}"; done \
    && composer require "glitchr/omniguard:1.x-dev" "omniguard/akismet:1.x-dev" "omniguard/altcha:1.x-dev" "omniguard/disposable:1.x-dev" --no-interaction --no-progress --no-scripts \
    # PHPUnit, for `demo test <plugin>`: a plugin's suite run against this
    # very application (its checkout mounted by compose.checkouts.yml).
    && composer require --dev "phpunit/phpunit:^9.6" --no-interaction --no-progress --no-scripts \
    # Recipe leftovers for bundles the demo does not register: the
    # google/recaptcha contrib recipe references a class recaptcha 1.5 no
    # longer ships, and webauthn's expects credential repositories the demo
    # has no use for.
    && rm -f config/packages/google_recaptcha.yaml config/packages/webauthn.yaml config/routes/webauthn_routes.yaml config/routes/api_platform.yaml

# --- demo app overlay: config, entities, one controller, two templates --------
COPY docker/app/ ./
COPY docker/demo.sh /usr/local/bin/demo

# composer's recipe scripts compiled the container BEFORE the overlay was
# copied - drop that stale cache so the first warmup starts clean. The
# bundle's warmers need more than the 128M default.
RUN rm -rf var/cache \
    && echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/zz-demo.ini \
    && chmod +x /usr/local/bin/demo

EXPOSE 8000
ENTRYPOINT ["demo"]
CMD ["serve"]
