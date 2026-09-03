# Tarefas agendadas (CRON)

**Produção / estabilidade:** ver [GUIA-PRODUCAO.md](./GUIA-PRODUCAO.md) (healthcheck, logs, debug, checklist).

Site: **https://ofertas.digitalavance.com.br/**

## limpar-produtos-antigos.php

Remove produtos com **mais de 30 dias** (configurável) e apaga as imagens em `uploads/`.

- **Web (recomendado na hospedagem):**  
  `https://ofertas.digitalavance.com.br/cron/limpar-produtos-antigos.php`  
  (adicione `?token=SEU_TOKEN` se `produtos_cron_token` estiver em configurações)
- **CLI:** `php cron/limpar-produtos-antigos.php`
- **Configurações (tabela `configuracoes`):**
  - `produtos_dias_expiracao`: dias para expirar (padrão 30).
  - `produtos_cron_token`: (opcional) exige `?token=` na chamada web.

**Como agendar no painel da hospedagem (cPanel, etc.):**
- Crie uma tarefa CRON que chame a **URL** 1x por dia (ex.: 3h da manhã):
  - `https://ofertas.digitalavance.com.br/cron/limpar-produtos-antigos.php`
- Ou, se tiver acesso SSH, use:  
  `0 3 * * * curl -s "https://ofertas.digitalavance.com.br/cron/limpar-produtos-antigos.php" > /dev/null 2>&1`

---

## rodar-automacao-ml.php

Automação Mercado Livre (ofertas → afiliado → OpenAI → WhatsApp).  
Se `ml_cron_token` estiver definido, use `?token=` na URL.

**Anti-repetição:** a automação não republica o mesmo produto no site nem no WhatsApp. Antes de usar, execute a migration:  
`migrations/add_produtos_ja_publicados.sql`

## rodar-automacao-shopee.php / rodar-automacao-magalu.php

Automações Shopee e Magalu (se instaladas).
