# ACHADINHOS — Implantação em VPS + EasyPanel + Docker + MariaDB 11 + Cloudflare

> Objetivo: rodar a aplicação **como ela rodaria na Hostinger/cPanel** (Apache + mod_php,
> DocumentRoot na raiz), apenas com o ambiente reproduzido dentro do Docker.
>
> **Esta preparação NÃO faz deploy.** Nada foi enviado para a VPS, nenhum banco foi importado,
> nenhum DNS foi configurado, nenhum cron foi ativado. Os passos abaixo são o procedimento a
> executar **após a sua autorização**.

---

## 0. O que mudou no projeto

### Arquivos criados

| Arquivo | Função |
|---|---|
| `Dockerfile` | Imagem `php:8.2-apache` + extensões `pdo_mysql` e `gd`; Apache com `mod_rewrite`/`headers`/`remoteip`; timezone `America/Sao_Paulo`; `HEALTHCHECK`. |
| `.dockerignore` | Mantém `.git`, `*.log`, `*.sql`, `.cursor/`, `.env` fora da imagem. |
| `docker/apache/achadinhos.conf` | Vhost (vira `000-default.conf`): `DocumentRoot /var/www/html`, `AllowOverride All`, bloqueio de arquivos/diretórios sensíveis, `X-Forwarded-Proto` → HTTPS, não-execução em `uploads/`. |
| `docker/php/achadinhos.ini` | Timezone, limites, `expose_php=Off`, hardening de sessão. |
| `docker/entrypoint.sh` | Prepara `storage/` e `uploads/` (volumes) + permissões, depois `apache2-foreground`. |
| `docker/uploads.htaccess` | Template de restauração do `uploads/.htaccess`. |
| `uploads/.htaccess` | Nega execução de scripts na pasta de uploads (2ª camada, portável). |
| `healthz.php` | Healthcheck de infraestrutura (200 "ok", sem tocar em banco/licença). |
| `.env.example` | Documenta as variáveis de ambiente. |
| `docker-compose.yml` | Validação **local** (app + `mariadb:11`), credenciais placeholder. |
| `DEPLOY-EASYPANEL.md` | Este documento. |

### Arquivo modificado (único código tocado)

**`config/database.php`** — as constantes `DB_*` passam a aceitar variáveis de ambiente, com
**fallback exato para os valores originais**:

```php
DB_HOST     <- getenv('DB_HOST')      ?: 'localhost'
DB_PORT     <- getenv('DB_PORT')      ?: '3306'      (novo; adicionado também ao DSN)
DB_NAME     <- getenv('DB_NAME')      ?: 'achadinhos'
DB_USER     <- getenv('DB_USER')      ?: 'root'
DB_PASS     <- getenv('DB_PASSWORD')  (ou getenv('DB_PASS'))  ?: ''
```

Sem variáveis definidas, o comportamento é **idêntico** ao original. Nenhuma credencial real
foi colocada no código. Nada mais foi alterado (schema, licença, cron, integrações, UI: intactos).

---

## 1. Criar o serviço da aplicação (App)

**Fluxo recomendado: GitHub + EasyPanel (build por Dockerfile).**

1. Suba este repositório para um repositório **privado** no GitHub
   (o `Banco de Dados.sql` fica no repo, mas o Apache bloqueia o download e o `.dockerignore`
   o mantém fora da imagem — ainda assim, mantenha o repo privado).
2. No EasyPanel: **Create Service → App**.
3. **Source:** GitHub → selecione o repositório e a branch.
4. **Build:** `Dockerfile` (o EasyPanel detecta o `Dockerfile` na raiz).
   - Build context: raiz do repositório.
5. **Port (interna):** `80` (o Apache do container escuta na 80).
6. Deixe para depois: domínio (passo 6) e variáveis (passo 3).
7. **Deploy** — o primeiro build instala `pdo_mysql` e `gd` (~2–4 min).

> Alternativa sem GitHub: **Create Service → App → Source: Docker Compose** e cole o
> `docker-compose.yml` adaptado (troque as senhas placeholder por variáveis do EasyPanel).
> O fluxo GitHub+Dockerfile é mais simples e previsível.

---

## 2. Criar / conectar o MariaDB

1. No EasyPanel: **Create Service → MariaDB** (ou **Database → MariaDB 11**).
2. Anote o que o EasyPanel gerar:
   - **Service name / host interno** (ex.: `achadinhos-db` ou `<projeto>_db`) → será o `DB_HOST`.
   - **Porta interna:** `3306`.
   - **Database, User, Password.**
3. **Privilégios:** a aplicação executa `ALTER TABLE` / `CREATE TABLE IF NOT EXISTS` em runtime
   (auto-migração defensiva em `core/db/SchemaHelper.php`). O utilizador do banco precisa de
   **ALL PRIVILEGES no schema** (o padrão do EasyPanel já concede isso ao user do database criado):

   ```sql
   GRANT ALL PRIVILEGES ON `achadinhos`.* TO 'achadinhos'@'%';
   FLUSH PRIVILEGES;
   ```

4. **Rede:** o serviço App e o MariaDB devem estar no **mesmo projeto/rede interna** do EasyPanel.
   O banco **não precisa** de porta pública.

### Valores a CONFIRMAR antes da importação do SQL

> **Não importe o banco antes de confirmar comigo estes 5 itens.**

| Item | Valor proposto | Observação |
|---|---|---|
| Nome do banco (`DB_NAME`) | `achadinhos` | pode ser outro; só precisa bater com a env |
| Utilizador (`DB_USER`) | `achadinhos` | idem |
| Host (`DB_HOST`) | nome do serviço MariaDB no EasyPanel | ex.: `achadinhos-db` |
| Porta (`DB_PORT`) | `3306` | interna |
| Arquivo SQL | `Banco de Dados.sql` (raiz do projeto) | dump phpMyAdmin, nativo MariaDB 11.8 |

O dump **cria as tabelas e insere seeds**, incluindo:
- conta admin `admin` (hash bcrypt; **trocar a senha logo após o 1º login**);
- linhas da tabela `configuracoes` com **segredos vazios/NULL** (nenhuma chave real no dump).

---

## 3. Variáveis de ambiente (no serviço App)

| Variável | Valor | Obrigatória? |
|---|---|---|
| `DB_HOST` | host interno do MariaDB (ex.: `achadinhos-db`) | Sim |
| `DB_PORT` | `3306` | Não (default `3306`) |
| `DB_NAME` | `achadinhos` | Sim |
| `DB_USER` | `achadinhos` | Sim |
| `DB_PASSWORD` | senha do utilizador do banco | Sim |

Nada mais é necessário. **Não** existe `APP_ENV` por env neste código — a aplicação já opera em
modo `production` por padrão (`config/config.php`).

---

## 4. Volumes persistentes

Criar **2 volumes** no serviço App (sobrevivem a rebuild/redeploy):

| Volume (mount path no container) | Conteúdo | Por quê |
|---|---|---|
| `/var/www/html/storage` | `licence.key`, `cron_locks/`, `logs/`, `reports/` | **`storage/licence.key` NÃO pode desaparecer** — é a licença ativada. Também guarda locks de cron e logs NDJSON. |
| `/var/www/html/uploads` | `produtos/`, `banners/`, `admin_avatars/`, logo, favicon, popup | imagens enviadas no painel e baixadas pelas automações. |

Notas:
- O `entrypoint.sh` recria a estrutura de pastas e o `storage/.htaccess` / `uploads/.htaccess`
  caso o volume venha vazio, e ajusta o dono para `www-data`.
- **Logs na raiz do projeto:** a aplicação escreve `debug-cron-auth.log` (falhas de auth de cron)
  e, só fora de produção, `debug-cca25f.log`. O entrypoint cria esses ficheiros com dono
  `www-data`. Eles ficam **dentro da imagem** (camada gravável do container), não precisam de
  volume; se quiser preservá-los, adicione um 3º volume em `/var/www/html` — **não recomendado**
  (mascararia o código). O Apache bloqueia o download deles (`.log`).

---

## 5. Porta interna e Healthcheck

- **Porta interna:** `80`.
- **Healthcheck (já embutido no `Dockerfile`):**
  `curl -fsS http://127.0.0.1/healthz.php` → espera `200 ok`.
- No EasyPanel, se pedir healthcheck HTTP: **Path** `/healthz.php`, **Port** `80`,
  **Expected status** `200`.
- **Importante:** não use `/` nem `/admin/` como healthcheck — enquanto a licença não estiver
  ativada, essas rotas respondem **403** (comportamento correto do sistema de licença).
  `/healthz.php` responde 200 independentemente do estado da licença e do banco.

---

## 6. Domínio

O domínio **ainda não precisa estar definido** para preparar o ambiente. Quando definir:

1. EasyPanel → serviço App → **Domains → Add Domain** → `ofertas.seudominio.com.br`
   (ou o domínio final escolhido).
2. EasyPanel termina TLS (Let's Encrypt) **ou** você deixa o Cloudflare terminar (ver passo 7).
3. O container **não** tem domínio hardcoded. A aplicação lê o host de `$_SERVER['HTTP_HOST']`
   em runtime — que é o que o sistema de licença usa. Portanto:
   - **defina o domínio ANTES de ativar a licença** (passo 10);
   - o proxy do EasyPanel **preserva o Host** por padrão (Traefik) — nada a fazer no código.

---

## 7. Cloudflare

1. DNS → registo `A`/`CNAME` do subdomínio apontando para o IP/endpoint do EasyPanel,
   **proxy ligado (nuvem laranja)**.
2. SSL/TLS → modo **Full (strict)** (EasyPanel serve HTTPS válido via Let's Encrypt).
   - Se preferir TLS só no Cloudflare: modo **Full**, e no EasyPanel exponha HTTP.
3. O vhost já trata o cabeçalho `X-Forwarded-Proto` → `HTTPS=on`, então a aplicação gera
   URLs `https://` e marca cookies de sessão como `Secure` corretamente.
4. **Não** ative "Always Use HTTPS" antes do primeiro teste HTTP interno, se for validar por IP.
5. Regras opcionais (depois de tudo funcionando):
   - Bloquear `/cron/*` e `/api/*` a nível de Cloudflare WAF, liberando apenas as origens
     legítimas (o agendador de cron e o n8n). **Não obrigatório agora.**
   - Cache: deixe "Standard"; a loja é HTML dinâmico.

---

## 8. Importar o `Banco de Dados.sql`

> **Só após confirmar os 5 itens da secção 2.** A importação é feita **uma vez**, no MariaDB
> do EasyPanel — **não** em nenhum banco de produção pré-existente (não há).

**Opção A — terminal do EasyPanel (serviço MariaDB):**
```bash
mariadb -u achadinhos -p achadinhos < "Banco de Dados.sql"
```

**Opção A2 — a partir do container do App (se o SQL estiver lá; por padrão o `.dockerignore` o exclui):**
```bash
# copie o arquivo para o container ou use o do repositório no host
mariadb -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "Banco de Dados.sql"
```

**Opção B — phpMyAdmin/Adminer** (se você subir um, temporário): importar o arquivo pela UI.

Verificação pós-import:
```sql
USE achadinhos;
SHOW TABLES;                       -- deve listar 16 tabelas
SELECT id, username, email FROM admins;   -- deve ter 1 linha (admin)
SELECT COUNT(*) FROM configuracoes;       -- deve ter dezenas de linhas
```

---

## 9. Testar a aplicação ANTES de ativar cron e integrações

1. **Container de pé:** `GET https://DOMINIO/healthz.php` → `200 ok`.
2. **PHP + Apache OK, licença a barrar (esperado):**
   `GET https://DOMINIO/` → **403** com a tela "🔒 AfiliadosPRO / Chave de licença".
   Isso **confirma** que o código roda e que o sistema de licença está intacto.
3. **Banco conectado:** a tela de licença só aparece se o `config/database.php` passou —
   e ele conecta ao banco logo depois. Para checar a conexão isolada, veja os logs do App:
   ausência de `Erro na conexão com o banco de dados` = OK.
4. **Ativar a licença** (passo 10) e então:
   - `GET https://DOMINIO/` → loja carrega (pode estar sem produtos).
   - `GET https://DOMINIO/admin/login.php` → tela de login.
   - Login com `admin` + senha do fornecedor → **trocar a senha** em seguida.
5. **Uploads:** no painel, editar um produto e enviar uma imagem → deve aparecer em
   `uploads/produtos/`. Testar que `https://DOMINIO/uploads/produtos/<arquivo>.jpg` abre
   e que `https://DOMINIO/uploads/qualquer.php` responde **403**.
6. **Arquivos sensíveis bloqueados** (devem responder 403/404):
   - `https://DOMINIO/Banco%20de%20Dados.sql`
   - `https://DOMINIO/debug-cron-auth.log`
   - `https://DOMINIO/config/database.php`
   - `https://DOMINIO/core_licenca.php`
   - `https://DOMINIO/Implementação%20cirúrgica%20achadinhos.txt`
7. Só depois disso: configurar integrações no painel e o cron (passo 12).

---

## 10. Ativação legítima da licença

> **A chave é obtida com o fornecedor**, gerada para o **domínio de produção definitivo**.
> Nada de gerar chave, alterar `LICENCA_SECRET`, expiry ou HMAC — o mecanismo permanece intacto.

1. Definir o domínio final (passo 6) e confirmar que ele responde.
2. Solicitar ao fornecedor a **chave de licença** para esse domínio
   (formato `base64url(dominio|expiry).hmac`).
3. Ativar de uma destas formas:
   - **Pelo navegador:** acessar `https://DOMINIO/`, colar a chave no campo "Chave de licença",
     clicar em **Ativar**. A aplicação grava `storage/licence.key` (no volume) e libera o acesso.
   - **Pelo volume:** criar o arquivo `storage/licence.key` com o conteúdo da chave (sem quebra
     de linha extra) e ajustar dono para `www-data`.
4. Confirmar: `https://DOMINIO/` deixa de mostrar a tela de bloqueio.
5. O arquivo `storage/licence.key` está no **volume persistente** → sobrevive a redeploys.
   - `www` e sem `www` do mesmo domínio são tratados como equivalentes pelo código.
   - **Subdomínio diferente = chave inválida.** Não mude o domínio depois de ativar.
6. Observação: o `storage/licence.key` que veio no repositório está amarrado a `achadinhos.test`
   (ambiente de teste do fornecedor) e **não** valida em produção — será substituído pela chave real.

---

## 11. Cron — o que configurar DEPOIS (não agora)

A arquitetura de cron **não foi alterada**. `cron/rodar-tudo.php` e os demais scripts permanecem
como no original (execução HTTP com `?token=` ou via CLI).

Quando a aplicação estiver online, testada e com licença ativa, configurar **uma** destas opções:

- **EasyPanel → Scheduled Task / Cron** (ou um serviço "cron" separado) executando a cada 5 min:
  ```
  */5 * * * *   curl -fsS "https://DOMINIO/cron/rodar-tudo.php?token=<CRON_TOKEN>" > /dev/null
  0    3 * * *   curl -fsS "https://DOMINIO/cron/limpar-produtos-antigos.php?token=<CRON_TOKEN>" > /dev/null
  ```
- **Ou** dentro do container do App, por CLI (não exige token):
  ```
  */5 * * * *   php /var/www/html/cron/rodar-tudo.php > /dev/null 2>&1
  ```
- **Ou** cron-job.org externo apontando para a URL pública (o painel tem sincronização automática
  via API em Configurações → Crons).

O `<CRON_TOKEN>` é definido no painel (**Configurações → Crons**) e gravado na tabela
`configuracoes` (`cron_token`). **Não** deve ser colocado no código nem neste documento.

`healthcheck` do cron (opcional, monitor externo): `GET /cron/health.php?token=<CRON_TOKEN>`.

---

## 12. Checklist de teste (pré-produção)

Local (sem depender da VPS):

- [ ] `php -l config/database.php` e `php -l healthz.php` → "No syntax errors".
- [ ] `docker build -t achadinhos:test .` conclui sem erro.
- [ ] `docker run --rm -p 8080:80 achadinhos:test` sobe; `curl localhost:8080/healthz.php` → `ok`.
- [ ] `curl -I localhost:8080/` → `403` (licença) — prova que PHP roda.
- [ ] `curl -I "localhost:8080/Banco%20de%20Dados.sql"` → `403`.
- [ ] `curl -I localhost:8080/config/database.php` → `403`.
- [ ] `curl -I localhost:8080/uploads/teste.php` → `403`; `curl -I localhost:8080/uploads/` → `403` (sem listagem).
- [ ] `docker compose up --build` (app+db); importar SQL; ativar licença de teste; `/admin/login.php` abre.
- [ ] `php -m` dentro do container lista: `pdo_mysql`, `gd`, `curl`, `mbstring`, `fileinfo`, `openssl`, `dom`.
- [ ] `date` dentro do container → horário de São Paulo; `php -i | grep date.timezone` → `America/Sao_Paulo`.
- [ ] Volumes `storage` e `uploads` persistem após `docker compose down && up` (sem `-v`).

Produção (após autorização):

- [ ] Serviço App + MariaDB no mesmo projeto EasyPanel.
- [ ] 5 variáveis `DB_*` cadastradas; deploy verde; `/healthz.php` → 200.
- [ ] SQL importado; `SHOW TABLES` = 16.
- [ ] Domínio + Cloudflare (Full strict); `X-Forwarded-Proto` chega (cookies Secure).
- [ ] Licença real ativada; `/` carrega; senha do admin trocada.
- [ ] Arquivos sensíveis retornam 403 (lista da secção 9.6).
- [ ] Backup configurado: volume do MariaDB **e** volume `storage/` (licença!).
- [ ] Só então: integrações no painel + cron (secção 11).

---

## 13. Riscos que permanecem DELIBERADAMENTE sem alteração

Conforme instruído, **não** foram tocados nesta etapa (serão tratados depois, com a app já online):

| # | Risco | Situação atual | Mitigação temporária disponível |
|---|---|---|---|
| R2 | Token fixo em `api/create-product.php` (`define('API_TOKEN', '...')`), endpoint público, CORS `*` | inalterado | bloquear `/api/` no Cloudflare WAF até revisar; ou não divulgar a URL |
| R3 | Conta admin `admin` do dump | inalterado | **trocar a senha no 1º login** (procedimento, não código) |
| R4 | `LICENCA_SECRET` em texto + `core_licenca.php`/`encode_licenca.php` no pacote | inalterado no código | **Apache bloqueia o download** desses ficheiros (`.conf`/`FilesMatch` core_licenca/encode_licenca); `.dockerignore` não os remove, mas ficam inacessíveis via web |
| R7 | SSRF em `downloadImageFromUrl()` (sem allowlist de host / bloqueio de IP privado) | inalterado | rede do container tem saída à internet; sem acesso a serviços internos sensíveis se o projeto EasyPanel estiver isolado |
| R8 | Sem CSRF token nos forms do admin | inalterado | painel atrás de login; tratar na fase de hardening |
| R10 | Bypass de auth de cron para Host `localhost`/`*.test`/IP privado | inalterado | atrás do Cloudflare o `Host` é sempre o domínio público → bypass **não** dispara. Não expor o container por IP interno publicamente. |
| R11 | API 500 devolve `$e->getMessage()` (PDO) | inalterado | idem R2 (restringir `/api/`) |
| R5 | Logs de debug graváveis na raiz | mantidos | **Apache bloqueia `.log`**; conteúdo não é público |
| — | Tailwind / Google Fonts via CDN externo | inalterado | não quebra funcionamento; tratar se necessário |

Nenhum destes exige mudança na lógica de negócio; todos entram no **hardening pós-online**,
separadamente, com a sua autorização.

---

## 14. Resumo do diff de código

```
config/database.php
──────────────────────────────────────────────────────────────────────────────
- define('DB_HOST', 'localhost');
- define('DB_NAME', 'achadinhos');
- define('DB_USER', 'root');
- define('DB_PASS', '');
- define('DB_CHARSET', 'utf8mb4');
+ if (!function_exists('achadinhos_db_env')) {
+     function achadinhos_db_env(string $nome, string $padrao): string {
+         $v = getenv($nome);
+         return ($v !== false && $v !== '') ? (string) $v : $padrao;
+     }
+ }
+ if (!defined('DB_HOST')) { define('DB_HOST', achadinhos_db_env('DB_HOST', 'localhost')); }
+ if (!defined('DB_PORT')) { define('DB_PORT', achadinhos_db_env('DB_PORT', '3306')); }
+ if (!defined('DB_NAME')) { define('DB_NAME', achadinhos_db_env('DB_NAME', 'achadinhos')); }
+ if (!defined('DB_USER')) { define('DB_USER', achadinhos_db_env('DB_USER', 'root')); }
+ if (!defined('DB_PASS')) {
+     $__dbp = getenv('DB_PASSWORD');
+     if ($__dbp === false) { $__dbp = getenv('DB_PASS'); }
+     define('DB_PASS', $__dbp !== false ? (string) $__dbp : '');
+     unset($__dbp);
+ }
+ define('DB_CHARSET', 'utf8mb4');

  (getDB) DSN:
- $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
+ $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
```

Comportamento sem variáveis de ambiente = **idêntico ao original**.
