#!/bin/sh
# ACHADINHOS — entrypoint do container.
# Roda como root, prepara storage/ e uploads/ (que são volumes persistentes e podem
# vir vazios ou com dono errado), depois entrega o controlo ao Apache (apache2-foreground),
# que baixa privilégios para www-data nos workers.
set -e

APP_DIR="/var/www/html"

# 1) Estrutura mínima de pastas graváveis (idempotente).
mkdir -p \
  "$APP_DIR/storage/cron_locks" \
  "$APP_DIR/storage/logs" \
  "$APP_DIR/storage/reports" \
  "$APP_DIR/uploads/produtos" \
  "$APP_DIR/uploads/banners" \
  "$APP_DIR/uploads/admin_avatars"

# 2) Se o volume de storage/ vier vazio, recria as proteções que normalmente vêm no repo.
if [ ! -f "$APP_DIR/storage/.htaccess" ]; then
  printf 'Require all denied\n' > "$APP_DIR/storage/.htaccess"
fi
if [ ! -f "$APP_DIR/uploads/.htaccess" ] && [ -f "$APP_DIR/docker/uploads.htaccess" ]; then
  cp "$APP_DIR/docker/uploads.htaccess" "$APP_DIR/uploads/.htaccess"
fi

# 3) Logs que a aplicação escreve na raiz do projeto: garantir que existem e são graváveis.
for f in debug-cron-auth.log debug-cca25f.log; do
  [ -f "$APP_DIR/$f" ] || : > "$APP_DIR/$f" 2>/dev/null || true
done

# 4) Permissões das áreas graváveis (não recursivo no projeto todo — só storage/ e uploads/).
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/uploads" 2>/dev/null || true
chown www-data:www-data "$APP_DIR/debug-cron-auth.log" "$APP_DIR/debug-cca25f.log" 2>/dev/null || true

exec "$@"
