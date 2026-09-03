# Guia de produção — Crons Achadinhos (HTTP + PHP)

## Fluxo de execução (auditoria)

1. **Disparo externo** — cron-job.org, crontab ou equivalente faz **GET** a `/cron/rodar-tudo.php?token=…` (global) ou `/cron/rodar-loja.php?loja=…&token=…` (loja com cron individual).
2. **`rodar-tudo.php`** — valida token (`achadinhosCronHttpExigirToken`), adquire **lock** em `storage/cron_locks/`, percorre lojas:
   - Se **cron individual** ativo para essa loja → **não executa** nesse script (vai só pelo `rodar-loja.php`).
   - Se **fora da janela** horária (e sem `forcar`) → **não executa** automação dessa loja.
   - Caso contrário chama `runAutomacao*()`; limpeza de produtos antigos corre sempre que o bloco try principal completa.
3. **Dentro de cada automação** — envios WhatsApp/Telegram respeitam `grupoPodeReceberEnvio` (intervalo por grupo) e, se ativo, dispatches (`dispatch_ativo_producao`).
4. **Registo** — `registrarExecucaoCron` grava `cron_execucoes` (+ `detalhes_json` com trace), actualiza `cron_global_last_*`, faz **ndjson** em `storage/logs/cron-runs-AAAA-MM-DD.ndjson`.

## Pontos de falha (lista)

| Risco | Mitigação no código |
|--------|---------------------|
| URL não pública | `cron_public_base_url` ou `*_site_url` + doc em `CronJobService.php`; opcional `cron_sync_allow_non_public_url=1` ou base manual preenchida para **permitir** sync na API (execução externa continua a exigir host alcançável — túnel ngrok/Cloudflare em dev). |
| Token ausente/errado | 403 em `rodar-tudo.php` (`achadinhosCronHttpExigirToken`); cabeçalho `X-Achadinhos-Cron-Error`: `token_not_configured` / `token_missing` / `token_invalid`. Ver `debug-cron-auth.log` (sem segredos). |
| Job loja com modo global | `rodar-loja.php` responde **403** + `X-Achadinhos-Cron-Error: cron_individual_off` se `cron_individual_ativo=0`; usar `rodar-tudo.php` ou reativar horário exclusivo. |
| Timezone | Schedule API `America/Sao_Paulo`; PHP: configurar `date.timezone` no servidor |
| Fora da janela | Trace `fora_janela_ou_forcar` / `fora_janela` em `rodar-loja` |
| Intervalo de grupo | Trace inclui nota; lógica em `grupoPodeReceberEnvio` |
| Execução concorrente | `flock` em `cronMonitorAdquirirLock` → HTTP 409 |
| Falhas silenciosas | NDJSON + `detalhes_json` + `error_log` em stale |
| Cron “morto” | `health.php` + `cronMonitorRegistrarStaleSeAtrasoGlobal` (alerta ≤1×/h no log) |

## Endpoints

| URL | Uso |
|-----|-----|
| `GET /cron/rodar-tudo.php?token=…` | Produção (global) |
| `GET /cron/rodar-tudo.php?token=…&debug=1` | Trace JSON/texto (mesmo token) |
| `php cron/rodar-tudo.php` | CLI sem token |
| `php cron/rodar-tudo.php --debug --forcar` | CLI diagnóstico |
| `GET /cron/health.php?token=…` | Monitor (usa `cron_health_token` se definido, senão `cron_token`) |
| `GET /cron/rodar-loja.php?loja=ml&token=…&debug=1` | JSON com decisão de janela |

## Exemplo cron-job.org (real)

1. Criar job **cron global** ou usar **sincronização via API** no painel (recomendado): o job fica com **URL incluindo** `?token=…` para o PHP receber o segredo mesmo quando o agendador **não repassa** cabeçalhos HTTP personalizados (causa típica de 403 `token_missing`).
2. **Address (URL):**  
   `https://SEU_DOMINIO.COM/cron/rodar-tudo.php?token=SEU_CRON_TOKEN`  
   (mesmo formato que **Configurações → Crons → URL do job** após sincronizar). Opcionalmente pode acrescentar `X-Cron-Token` nos headers, mas **não é necessário** se o token já está na query.
3. **Request method:** GET.
4. O horário do job no cron-job.org deve seguir o intervalo desejado (ex. cada 5 minutos). O painel sincroniza **janela** e **intervalo** com a API.
5. **Teste manual:** abrir a URL completa (com `?token=`) no navegador — deve responder **200** e corpo com linhas `loja: ✓/✗ …` (não 403). Se alterar o token no painel, **volte a sincronizar** o job para atualizar a URL na cron-job.org.
6. Validar no histórico do job: respostas **200** (e não 403/409/500). Em **403**, ver `X-Achadinhos-Cron-Error` e `debug-cron-auth.log` na raiz do projeto.

## Teste local com cron-job.org

A cron-job.org não alcança `localhost` nem `*.test` sem rota pública. Para testar: (1) expor o Laragon com **túnel HTTPS** e gravar essa base em **Configurações → Crons → URL base pública**; ou (2) apontar o job para o **domínio de produção/staging** já acessível.

## Checklist antes de subir

- [ ] `cron_token` definido e igual ao da URL do agendador.
- [ ] `cron_public_base_url` ou URL de loja pública HTTPS correcta (não `localhost`).
- [ ] `storage/cron_locks` e `storage/logs` graváveis pelo PHP.
- [ ] Timezone PHP = esperado (ex. `America/Sao_Paulo`).
- [ ] Janelas horárias (`*_hora_inicio` / `*_hora_fim`) alinhadas à operação.
- [ ] Intervalo global `cron_intervalo_minutos` coerente com o job externo.
- [ ] Se usam dispatches em produção: `dispatch_ativo_producao=1` e linhas em `dispatches`.
- [ ] Healthcheck num monitor externo apontando para `/cron/health.php?token=…`.
- [ ] Após deploy, uma execução manual com `debug=1` e revisão de `cron_execucoes.detalhes_json`.

## Evolução (nível SaaS) — não implementado

- **Fila:** Redis/Rabbit + biblioteca PHP (ex. Symfony Messenger com `redis://`) ou serviço gerido.
- **Worker:** processo CLI longevo (`while` + `pop`) ou supervisão (systemd, Supervisor).
- **Scheduler:** envia apenas `EnqueueRunGlobal` jobs; worker executa automações e reenvia com **backoff** em falha transitória.
- **Paralelismo:** vários workers com **lock por loja** (ou fila particionada por `loja`).
- **Retry:** política exponencial; dead-letter após N falhas.

Este repositório mantém o modelo **stateless HTTP + cron externo**, adequado à maioria das hospedagens partilhadas.
