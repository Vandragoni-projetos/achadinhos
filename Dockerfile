# ACHADINHOS / AfiliadosPRO — imagem de produção
# Objetivo: reproduzir a hospedagem tradicional (Apache + mod_php) dentro do Docker,
# alterando o mínimo possível da aplicação. DocumentRoot = raiz do projeto (NÃO /public).

FROM php:8.2-apache

# ---------------------------------------------------------------------------
# Extensões PHP
#
# Já presentes e habilitadas na imagem oficial php:8.2-apache (confirmado):
#   curl, mbstring, fileinfo, openssl, dom (libxml), json, session, PDO, hash, filter
#
# A instalar (identificadas na análise):
#   pdo_mysql  -> toda a persistência (MySQL/MariaDB via PDO)
#   gd         -> processamento de imagens (imagecreatefromstring / imagejpeg / imagepng),
#                 com suporte a JPEG, PNG, WEBP e FreeType
# ---------------------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql gd; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# Apache
#   - rewrite: habilitado por precaução (os .htaccess atuais NÃO usam rewrite,
#              mas evita surpresas caso um .htaccess passe a usá-lo)
#   - headers/remoteip: úteis atrás do proxy do EasyPanel/Cloudflare
#   - autoindex: desabilitado (nunca listar diretórios)
# ---------------------------------------------------------------------------
RUN set -eux; \
    a2enmod rewrite headers remoteip; \
    a2dismod -f autoindex 2>/dev/null || true

COPY docker/apache/achadinhos.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/achadinhos.ini    /usr/local/etc/php/conf.d/zz-achadinhos.ini

# Timezone também a nível de SO (o PHP usa o ini acima; isto ajuda logs/date do Apache)
RUN set -eux; \
    ln -snf /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime; \
    echo "America/Sao_Paulo" > /etc/timezone

# ---------------------------------------------------------------------------
# Código da aplicação
# ---------------------------------------------------------------------------
COPY . /var/www/html/

RUN set -eux; \
    mkdir -p \
      /var/www/html/storage/cron_locks \
      /var/www/html/storage/logs \
      /var/www/html/uploads/produtos \
      /var/www/html/uploads/banners \
      /var/www/html/uploads/admin_avatars; \
    # A aplicação escreve alguns logs na própria raiz do projeto (ex.: debug-cron-auth.log).
    # Espelhamos o modelo de hospedagem partilhada: ficheiros pertencem ao utilizador do PHP.
    chown -R www-data:www-data /var/www/html; \
    find /var/www/html -type d -exec chmod 755 {} \; ; \
    find /var/www/html -type f -exec chmod 644 {} \;

COPY docker/entrypoint.sh /usr/local/bin/achadinhos-entrypoint
RUN chmod +x /usr/local/bin/achadinhos-entrypoint

# Healthcheck de infraestrutura (não valida licença nem banco — mede só Apache+PHP)
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/healthz.php || exit 1

EXPOSE 80

ENTRYPOINT ["achadinhos-entrypoint"]
CMD ["apache2-foreground"]
