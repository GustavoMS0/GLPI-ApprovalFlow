# Approval Flow

> [!WARNING]
> **Status: Em Desenvolvimento (Beta / Pré-homologação)**
> Este plugin teve a arquitetura, a segurança e os fluxos validados por revisão de código, auditoria de segurança e testes unitários automatizados em CI (`PHP 7.4–8.3` com mocks de GLPI 10/11/12), mas **ainda não foi testado em uma instância GLPI ao vivo**. Valide em ambiente de homologação antes de usar em produção e reporte eventuais ajustes via Issues.

**Motor automático de regras de aprovação para chamados do GLPI com sincronização nativa em `TicketValidation` e aprovação por link seguro em duas etapas.**

O **Approval Flow** avalia regras por entidade (com herança recursiva), categoria, tipo de chamado e urgência mínima, cria validações nativas compatíveis com GLPI 10, 11 e 12, gera links de uso único protegidos por hash SHA-256 e bloqueia a solução/fechamento enquanto houver aprovações pendentes.

| Versão do GLPI | Versão mínima do PHP | Suporte |
|---|---|---|
| **12.0.x** | PHP **8.3+** | ✅ |
| **11.0.x** | PHP **8.2+** | ✅ |
| **10.0.x** | PHP **7.4+** | ✅ |

---

## Funcionalidades

- **Regras por entidade com herança recursiva:**
  - defina múltiplas regras por entidade com prioridade configurável;
  - entidades filhas herdam regras recursivas do ancestral mais próximo até a entidade raiz;
  - filtre por **Categoria** (`0` = qualquer), **Tipo de chamado** (`Qualquer`, `Incidente` ou `Requisição`) e **Urgência mínima** (`1` a `5`).
- **Disparo automático com deduplicação:**
  - avaliado na criação do chamado (`Ticket` `item_add`) e na alteração de entidade, categoria, tipo ou urgência (`Ticket` `item_update`);
  - deduplicado por `(rules_id, tickets_id)` para evitar duplicidades em atualizações subsequentes e protegido contra reentrância.
- **Compatibilidade nativa com `TicketValidation` (GLPI 10 / 11 / 12):**
  - no **GLPI 11 e 12** (`itemtype_target` + `items_id_target`): envia aprovação nativa para `User` ou `Group` (ou expande todos os membros ativos do grupo quando **Exigir todos os membros do grupo** estiver marcado);
  - no **GLPI 10** (`users_id_validate`): envia diretamente ao usuário ou expande automaticamente os membros ativos do grupo (`glpi_groups_users`);
  - sincroniza em mão dupla: aprovações/recusas feitas na aba nativa **Aprovações / Validações** atualizam o fluxo do plugin, e decisões no endpoint do plugin atualizam `TicketValidation`.
- **Link seguro de aprovação em duas etapas (`front/approve.php`):**
  - gera token aleatório de 32 bytes (`base64url`) e armazena no banco apenas `SHA-256(token)` com validade configurável (padrão 7 dias);
  - requisição `GET` exibe resumo do chamado e formulário de confirmação sem alterar estado (proteção contra pré-leitores/scanners de e-mail);
  - requisição `POST` valida origem (`requireSafePost`), token/sessão, executa `approve`/`refuse`, invalida `token_hash` (uso único) e envia cabeçalhos `Cache-Control: no-store` e `Referrer-Policy: no-referrer`.
- **Bloqueio de solução e fechamento enquanto pendente:**
  - impede criação de `ITILSolution` (`pre_item_add`) e transição direta do `Ticket` para **Solucionado (5)** ou **Fechado (6)** (`pre_item_update`) enquanto houver solicitação pendente bloqueante;
  - ignora automaticamente execuções não interativas (CLI/cron).
- **Aba dedicada no chamado (`Aprovações`):**
  - exibe status (`Pendente`, `Aprovado`, `Recusado`, `Cancelado`), link para a validação nativa e exibição única (flash) da URL de aprovação logo após o disparo.

---

## Instalação

### Pelo terminal (Linux)

```bash
GLPI_DIR=/var/www/glpi

cd /tmp
unzip -q approvalflow-1.0.0.zip -d "$GLPI_DIR/plugins/"
sudo chown -R www-data:www-data "$GLPI_DIR/plugins/approvalflow"

cd "$GLPI_DIR"
sudo -u www-data php bin/console plugin:install --username=glpi approvalflow
sudo -u www-data php bin/console plugin:activate approvalflow
sudo -u www-data php bin/console cache:clear
```

### Pela interface web

1. Baixe `approvalflow-<versão>.zip` em [Releases](https://github.com/GustavoMS0/GLPI-ApprovalFlow/releases).
2. Extraia em `plugins/` do GLPI, resultando em `plugins/approvalflow/setup.php`.
3. Acesse **Configurar › Plugins**, clique em **Instalar** e depois em **Ativar** no **Approval Flow**.

---

## Configuração

1. Abra **Configurar › Plugins › Approval Flow** (`front/config.form.php`).
2. Selecione a **Entidade** no topo da tela.
3. Cadastre regras definindo nome, prioridade, categoria, tipo, urgência mínima, aprovador (**Usuário** ou **Grupo**) e comportamento (validação nativa, expansão de grupo, token de uso único, exigência de login no token e bloqueio de solução).
4. Gerencie regras existentes (editar, ativar/desativar ou excluir) diretamente pela tabela administrativa.

---

## Atualização e desinstalação

- **Atualização:** substitua a pasta `plugins/approvalflow` pelos arquivos da nova versão e execute `plugin:install` seguido de `cache:clear`.
- **Desinstalação:** ao desinstalar em **Configurar › Plugins** (ou via `php bin/console plugin:uninstall approvalflow`), as tabelas `glpi_plugin_approvalflow_requests` e `glpi_plugin_approvalflow_rules` são removidas de forma limpa.

---

## Licença

Distribuído sob a licença **GNU General Public License v3.0 ou posterior (GPLv3+)**. Veja [LICENSE](LICENSE).

Copyright (C) 2026 by G. Martins.
