<?php
/**
 * Healthcheck de INFRAESTRUTURA.
 *
 * Responde 200 "ok" desde que Apache + PHP estejam a funcionar.
 * NÃO inclui config/database.php de propósito: não depende do banco de dados
 * nem do estado da licença (que responde 403 até ser ativada). Serve para o
 * HEALTHCHECK do Docker / EasyPanel confirmarem que o container está de pé.
 *
 * Para verificar o estado real da aplicação (banco, licença), use /admin/login.php.
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
http_response_code(200);
echo 'ok';
