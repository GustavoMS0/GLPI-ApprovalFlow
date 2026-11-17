# Changelog

## [1.0.0] - 2026-11-17

### Adicionado
- Motor de regras de aprovação automática por entidade (com herança recursiva), categoria, tipo de chamado e urgência mínima na criação e atualização de chamados (`Ticket` `item_add` / `item_update`) com deduplicação por `(rules_id, tickets_id)`.
- Suporte unificado ao GLPI 10.0.x, 11.0.x e 12.0.x em pacote único (PHP 7.4+ para 10.0.x, 8.2+ para 11.0.x, 8.3+ para 12.0.x), com declaração condicional de `CommonGLPI::$rightname` via reflexão e camada de compatibilidade `PluginApprovalflowCompat`.
- Disparo e sincronização bidirecional com `TicketValidation` nativo utilizando `users_id_validate` no GLPI 10 e `itemtype_target` / `items_id_target` (`User` e `Group`) no GLPI 11/12, com expansão opcional de membros ativos de grupo e guarda anti-reentrância.
- Endpoint seguro em duas etapas (`front/approve.php`) com token aleatório de 32 bytes armazenado apenas via `SHA-256`, exibição de resumo sem mutação no `GET`, validação de origem e uso único no `POST`, e cabeçalhos `Cache-Control: no-store` e `Referrer-Policy: no-referrer`.
- Bloqueio automático de `ITILSolution` (`pre_item_add`) e transição de `Ticket` para Solucionado/Fechado (`pre_item_update`) enquanto houver solicitações pendentes bloqueantes, preservando execuções CLI/cron.
- Aba **Aprovações** no chamado (`PluginApprovalflowTab`), página administrativa (`front/config.form.php`), limpeza automática em `Ticket` `item_purge`, tradução `pt_BR` compilada (`.pot`/`.po`/`.mo`) e suíte de testes unitários standalone em `tests/`.
