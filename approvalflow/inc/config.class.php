<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Regras e esquema de banco do plugin
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Approval Flow.
 *
 * Approval Flow is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Approval Flow is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

interface PluginApprovalflowConfigConstants
{
    public const RULES_TABLE    = 'glpi_plugin_approvalflow_rules';
    public const REQUESTS_TABLE = 'glpi_plugin_approvalflow_requests';
    public const TYPE_ANY       = 0;
    public const TYPE_INCIDENT  = 1;
    public const TYPE_REQUEST   = 2;
}

trait PluginApprovalflowConfigBody
{
    /**
     * Nome legível do item no GLPI.
     */
    public static function getTypeName($nb = 0)
    {
        return function_exists('__') ? __('Approval Flow', 'approvalflow') : 'Approval Flow';
    }

    /**
     * Tabela associada às regras de aprovação.
     */
    public static function getTable($classname = null)
    {
        return self::RULES_TABLE;
    }

    /**
     * Cria as tabelas do plugin na instalação ou atualização.
     */
    public static function installSchema(): void
    {
        global $DB;

        if (!$DB->tableExists(self::RULES_TABLE)) {
            $sqlRules = "CREATE TABLE `" . self::RULES_TABLE . "` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `entities_id` int unsigned NOT NULL DEFAULT '0',
                    `is_recursive` tinyint NOT NULL DEFAULT '1',
                    `name` varchar(255) NOT NULL DEFAULT '',
                    `is_active` tinyint NOT NULL DEFAULT '1',
                    `priority` int NOT NULL DEFAULT '10',
                    `itilcategories_id` int unsigned NOT NULL DEFAULT '0',
                    `ticket_type` tinyint unsigned NOT NULL DEFAULT '0',
                    `min_urgency` tinyint unsigned NOT NULL DEFAULT '1',
                    `approver_type` varchar(16) NOT NULL DEFAULT 'user',
                    `approver_id` int unsigned NOT NULL DEFAULT '0',
                    `use_native_validation` tinyint NOT NULL DEFAULT '1',
                    `require_all_group_members` tinyint NOT NULL DEFAULT '0',
                    `generate_token_link` tinyint NOT NULL DEFAULT '1',
                    `block_solve_while_pending` tinyint NOT NULL DEFAULT '1',
                    `require_login_for_token` tinyint NOT NULL DEFAULT '1',
                    `token_ttl_days` int unsigned NOT NULL DEFAULT '7',
                    `date_creation` timestamp NULL DEFAULT NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_entity_active` (`entities_id`, `is_active`),
                    KEY `idx_priority` (`priority`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
            PluginApprovalflowCompat::execDdl($DB, $sqlRules);
        }

        if (!$DB->tableExists(self::REQUESTS_TABLE)) {
            $sqlRequests = "CREATE TABLE `" . self::REQUESTS_TABLE . "` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `rules_id` int unsigned NOT NULL DEFAULT '0',
                    `tickets_id` int unsigned NOT NULL DEFAULT '0',
                    `entities_id` int unsigned NOT NULL DEFAULT '0',
                    `ticketvalidations_id` int unsigned NOT NULL DEFAULT '0',
                    `approver_type` varchar(16) NOT NULL DEFAULT 'user',
                    `approver_users_id` int unsigned NOT NULL DEFAULT '0',
                    `approver_groups_id` int unsigned NOT NULL DEFAULT '0',
                    `token_hash` char(64) NOT NULL DEFAULT '',
                    `status` varchar(20) NOT NULL DEFAULT 'pending',
                    `comment` text DEFAULT NULL,
                    `decided_by_users_id` int unsigned NOT NULL DEFAULT '0',
                    `expires_at` timestamp NULL DEFAULT NULL,
                    `decided_at` timestamp NULL DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_rule_ticket` (`rules_id`, `tickets_id`),
                    KEY `idx_ticket_status` (`tickets_id`, `status`),
                    KEY `idx_validation` (`ticketvalidations_id`),
                    KEY `idx_token_hash` (`token_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
            PluginApprovalflowCompat::execDdl($DB, $sqlRequests);
        }
    }

    /**
     * Remove as tabelas do plugin na desinstalação.
     */
    public static function uninstallSchema(): void
    {
        global $DB;

        if ($DB->tableExists(self::REQUESTS_TABLE)) {
            PluginApprovalflowCompat::execDdl($DB, 'DROP TABLE IF EXISTS `' . self::REQUESTS_TABLE . '`');
        }
        if ($DB->tableExists(self::RULES_TABLE)) {
            PluginApprovalflowCompat::execDdl($DB, 'DROP TABLE IF EXISTS `' . self::RULES_TABLE . '`');
        }
    }

    /**
     * Valores padrão de uma nova regra de aprovação.
     */
    public static function defaultRule(int $entities_id = 0): array
    {
        return [
            'id'                        => 0,
            'entities_id'               => $entities_id,
            'is_recursive'              => 1,
            'name'                      => '',
            'is_active'                 => 1,
            'priority'                  => 10,
            'itilcategories_id'         => 0,
            'ticket_type'               => self::TYPE_ANY,
            'min_urgency'               => 1,
            'approver_type'             => 'user',
            'approver_id'               => 0,
            'use_native_validation'     => 1,
            'require_all_group_members' => 0,
            'generate_token_link'       => 1,
            'block_solve_while_pending' => 1,
            'require_login_for_token'   => 1,
            'token_ttl_days'            => 7,
        ];
    }

    /**
     * Normaliza os campos de entrada de uma regra antes da persistência.
     */
    public static function normalizeRuleInput(int $entities_id, array $raw): array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $name = function_exists('__') ? __('Regra de aprovação', 'approvalflow') : 'Regra de aprovação';
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 255, 'UTF-8');
        } else {
            $name = substr($name, 0, 255);
        }

        $priority = isset($raw['priority']) ? (int) $raw['priority'] : 10;
        if ($priority < 1) {
            $priority = 1;
        } elseif ($priority > 9999) {
            $priority = 9999;
        }

        $ticketType = isset($raw['ticket_type']) ? (int) $raw['ticket_type'] : self::TYPE_ANY;
        if (!in_array($ticketType, [self::TYPE_ANY, self::TYPE_INCIDENT, self::TYPE_REQUEST], true)) {
            $ticketType = self::TYPE_ANY;
        }

        $minUrgency = isset($raw['min_urgency']) ? (int) $raw['min_urgency'] : 1;
        if ($minUrgency < 1) {
            $minUrgency = 1;
        } elseif ($minUrgency > 5) {
            $minUrgency = 5;
        }

        $approverType = strtolower(trim((string) ($raw['approver_type'] ?? 'user')));
        if ($approverType !== 'group') {
            $approverType = 'user';
        }

        $approverId = 0;
        if (isset($raw['approver_id'])) {
            $approverId = max(0, (int) $raw['approver_id']);
        } elseif ($approverType === 'user' && isset($raw['approver_users_id'])) {
            $approverId = max(0, (int) $raw['approver_users_id']);
        } elseif ($approverType === 'group' && isset($raw['approver_groups_id'])) {
            $approverId = max(0, (int) $raw['approver_groups_id']);
        }

        $ttlDays = isset($raw['token_ttl_days']) ? (int) $raw['token_ttl_days'] : 7;
        if ($ttlDays < 1) {
            $ttlDays = 1;
        } elseif ($ttlDays > 90) {
            $ttlDays = 90;
        }

        return [
            'entities_id'               => max(0, $entities_id),
            'is_recursive'              => !empty($raw['is_recursive']) ? 1 : 0,
            'name'                      => $name,
            'is_active'                 => array_key_exists('is_active', $raw) ? (!empty($raw['is_active']) ? 1 : 0) : 1,
            'priority'                  => $priority,
            'itilcategories_id'         => max(0, (int) ($raw['itilcategories_id'] ?? 0)),
            'ticket_type'               => $ticketType,
            'min_urgency'               => $minUrgency,
            'approver_type'             => $approverType,
            'approver_id'               => $approverId,
            'use_native_validation'     => array_key_exists('use_native_validation', $raw) ? (!empty($raw['use_native_validation']) ? 1 : 0) : 1,
            'require_all_group_members' => !empty($raw['require_all_group_members']) ? 1 : 0,
            'generate_token_link'       => array_key_exists('generate_token_link', $raw) ? (!empty($raw['generate_token_link']) ? 1 : 0) : 1,
            'block_solve_while_pending' => array_key_exists('block_solve_while_pending', $raw) ? (!empty($raw['block_solve_while_pending']) ? 1 : 0) : 1,
            'require_login_for_token'   => array_key_exists('require_login_for_token', $raw) ? (!empty($raw['require_login_for_token']) ? 1 : 0) : 1,
            'token_ttl_days'            => $ttlDays,
        ];
    }

    /**
     * Normaliza uma linha vinda do banco decodificando HTML legado do GLPI < 11.
     */
    public static function hydrateRule(array $row): array
    {
        return [
            'id'                        => (int) ($row['id'] ?? 0),
            'entities_id'               => (int) ($row['entities_id'] ?? 0),
            'is_recursive'              => (int) ($row['is_recursive'] ?? 0),
            'name'                      => PluginApprovalflowCompat::fromCore((string) ($row['name'] ?? '')),
            'is_active'                 => (int) ($row['is_active'] ?? 0),
            'priority'                  => (int) ($row['priority'] ?? 10),
            'itilcategories_id'         => (int) ($row['itilcategories_id'] ?? 0),
            'ticket_type'               => (int) ($row['ticket_type'] ?? 0),
            'min_urgency'               => (int) ($row['min_urgency'] ?? 1),
            'approver_type'             => (string) ($row['approver_type'] ?? 'user'),
            'approver_id'               => (int) ($row['approver_id'] ?? 0),
            'use_native_validation'     => (int) ($row['use_native_validation'] ?? 1),
            'require_all_group_members' => (int) ($row['require_all_group_members'] ?? 0),
            'generate_token_link'       => (int) ($row['generate_token_link'] ?? 1),
            'block_solve_while_pending' => (int) ($row['block_solve_while_pending'] ?? 1),
            'require_login_for_token'   => array_key_exists('require_login_for_token', $row) ? (int) $row['require_login_for_token'] : 1,
            'token_ttl_days'            => max(1, (int) ($row['token_ttl_days'] ?? 7)),
            'date_creation'             => $row['date_creation'] ?? null,
            'date_mod'                  => $row['date_mod'] ?? null,
        ];
    }

    /**
     * Cria ou atualiza uma regra de aprovação. Retorna o ID da regra ou 0 em caso de falha.
     */
    public static function saveRule(int $entities_id, array $data, int $ruleId = 0): int
    {
        global $DB;

        if (!isset($DB) || !is_object($DB) || !$DB->tableExists(self::RULES_TABLE)) {
            return 0;
        }

        $normalized = self::normalizeRuleInput($entities_id, $data);
        if ($normalized['approver_id'] <= 0) {
            return 0;
        }

        $now                    = date('Y-m-d H:i:s');
        $normalized['date_mod'] = $now;

        if ($ruleId > 0) {
            $existing = self::getRule($ruleId);
            if ($existing === null || (int) $existing['entities_id'] !== $normalized['entities_id']) {
                return 0;
            }
            $payload = PluginApprovalflowCompat::dbValues($normalized);
            $ok      = $DB->update(self::RULES_TABLE, $payload, ['id' => $ruleId]);
            return $ok ? $ruleId : 0;
        }

        $normalized['date_creation'] = $now;
        $payload                     = PluginApprovalflowCompat::dbValues($normalized);
        $inserted                    = $DB->insert(self::RULES_TABLE, $payload);
        if (!$inserted) {
            return 0;
        }

        return method_exists($DB, 'insertId') ? (int) $DB->insertId() : 0;
    }

    /**
     * Alterna o status ativo/inativo de uma regra.
     */
    public static function toggleRule(int $ruleId, int $entities_id = -1): bool
    {
        global $DB;

        if ($ruleId <= 0 || !isset($DB) || !$DB->tableExists(self::RULES_TABLE)) {
            return false;
        }

        $rule = self::getRule($ruleId);
        if ($rule === null) {
            return false;
        }
        if ($entities_id >= 0 && (int) $rule['entities_id'] !== $entities_id) {
            return false;
        }

        $newActive = !empty($rule['is_active']) ? 0 : 1;
        return (bool) $DB->update(
            self::RULES_TABLE,
            ['is_active' => $newActive, 'date_mod' => date('Y-m-d H:i:s')],
            ['id' => $ruleId]
        );
    }

    /**
     * Remove uma regra pelo ID (opcionalmente validando a entidade proprietária).
     */
    public static function deleteRule(int $ruleId, int $entities_id = -1): bool
    {
        global $DB;

        if ($ruleId <= 0 || !isset($DB) || !$DB->tableExists(self::RULES_TABLE)) {
            return false;
        }

        $rule = self::getRule($ruleId);
        if ($rule === null) {
            return false;
        }
        if ($entities_id >= 0 && (int) $rule['entities_id'] !== $entities_id) {
            return false;
        }

        return (bool) $DB->delete(self::RULES_TABLE, ['id' => $ruleId]);
    }

    /**
     * Obtém uma regra pelo ID.
     */
    public static function getRule(int $ruleId): ?array
    {
        global $DB;

        if ($ruleId <= 0 || !isset($DB) || !$DB->tableExists(self::RULES_TABLE)) {
            return null;
        }

        $it = $DB->request([
            'FROM'  => self::RULES_TABLE,
            'WHERE' => ['id' => $ruleId],
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            return self::hydrateRule($row);
        }

        return null;
    }

    /**
     * Lista todas as regras configuradas diretamente em uma entidade.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getRulesForEntity(int $entities_id): array
    {
        global $DB;

        if ($entities_id < 0 || !isset($DB) || !$DB->tableExists(self::RULES_TABLE)) {
            return [];
        }

        $it = $DB->request([
            'FROM'  => self::RULES_TABLE,
            'WHERE' => ['entities_id' => $entities_id],
            'ORDER' => ['priority ASC', 'id ASC'],
        ]);

        $rules = [];
        foreach ($it as $row) {
            $rules[] = self::hydrateRule($row);
        }

        return $rules;
    }

    /**
     * Retorna regras ativas aplicáveis à entidade do chamado (próprias + recursivas dos ancestrais,
     * ordenadas da entidade mais próxima para a raiz e por prioridade).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getApplicableRules(int $entities_id): array
    {
        global $DB;

        if ($entities_id < 0 || !isset($DB) || !$DB->tableExists(self::RULES_TABLE)) {
            return [];
        }

        $ancestors = PluginApprovalflowCompat::getEntitiesAncestors($entities_id);
        $chain     = [$entities_id];
        foreach ($ancestors as $ancestorId) {
            if (!in_array($ancestorId, $chain, true)) {
                $chain[] = $ancestorId;
            }
        }

        $it = $DB->request([
            'FROM'  => self::RULES_TABLE,
            'WHERE' => [
                'entities_id' => $chain,
                'is_active'   => 1,
            ],
            'ORDER' => ['priority ASC', 'id ASC'],
        ]);

        $matched = [];
        foreach ($it as $row) {
            $rule      = self::hydrateRule($row);
            $ruleEid   = (int) $rule['entities_id'];
            $isDirect  = ($ruleEid === $entities_id);
            $isInherit = !empty($rule['is_recursive']);
            if (!$isDirect && !$isInherit) {
                continue;
            }
            $distance = array_search($ruleEid, $chain, true);
            $rule['_entity_distance'] = ($distance !== false) ? (int) $distance : 999;
            $matched[]                = $rule;
        }

        usort($matched, static function (array $a, array $b): int {
            if ($a['_entity_distance'] !== $b['_entity_distance']) {
                return $a['_entity_distance'] <=> $b['_entity_distance'];
            }
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            return $a['id'] <=> $b['id'];
        });

        return $matched;
    }
}

if (!class_exists('PluginApprovalflowConfig', false)) {
    if (PluginApprovalflowCompat::typedRightname()) {
        class PluginApprovalflowConfig extends CommonDBTM implements PluginApprovalflowConfigConstants
        {
            use PluginApprovalflowConfigBody;

            public static string $rightname = 'config';
        }
    } else {
        class PluginApprovalflowConfig extends CommonDBTM implements PluginApprovalflowConfigConstants
        {
            use PluginApprovalflowConfigBody;

            public static $rightname = 'config';
        }
    }
}
