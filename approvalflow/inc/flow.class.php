<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Motor de avaliação de regras, validações e tokens
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

interface PluginApprovalflowFlowConstants
{
    public const REQUESTS_TABLE         = 'glpi_plugin_approvalflow_requests';
    public const STATUS_PENDING         = 'pending';
    public const STATUS_APPROVED        = 'approved';
    public const STATUS_REFUSED         = 'refused';
    public const STATUS_CANCELLED       = 'cancelled';
    public const VALIDATION_NONE        = 1;
    public const VALIDATION_WAITING     = 2;
    public const VALIDATION_ACCEPTED    = 3;
    public const VALIDATION_REFUSED     = 4;
    public const TICKET_SOLVED          = 5;
    public const TICKET_CLOSED          = 6;
    public const DEFAULT_TOKEN_TTL_DAYS = 7;
}

/**
 * Motor de fluxo de aprovação automática, sincronização nativa e bloqueio de solução.
 */
class PluginApprovalflowFlow implements PluginApprovalflowFlowConstants
{
    /** @var array<int, bool> */
    private static $dispatchingTickets = [];

    /** @var array<int, bool> */
    private static $syncingValidations = [];

    /**
     * Indica se a execução atual é não interativa (CLI, cron ou sem sessão de usuário).
     */
    public static function isNonInteractiveContext(): bool
    {
        if (function_exists('isCommandLineInterface') && isCommandLineInterface()) {
            return true;
        }
        if (class_exists('Session') && method_exists('Session', 'isCron') && Session::isCron()) {
            return true;
        }
        if (isset($_SESSION['glpicronuserrunning']) && $_SESSION['glpicronuserrunning'] !== '') {
            return true;
        }
        if (class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            if ((int) Session::getLoginUserID(true) <= 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hook item_add em Ticket: avalia e dispara aprovações aplicáveis.
     *
     * @param CommonDBTM $item
     */
    public static function onTicketAdd($item): void
    {
        if (!($item instanceof CommonDBTM)) {
            return;
        }
        self::evaluateTicket($item);
    }

    /**
     * Hook item_update em Ticket: reavalia somente se entidade, categoria, tipo ou urgência mudarem.
     *
     * @param CommonDBTM $item
     */
    public static function onTicketUpdate($item): void
    {
        if (!($item instanceof CommonDBTM)) {
            return;
        }

        $updates = isset($item->updates) && is_array($item->updates) ? $item->updates : [];
        if (empty($updates)) {
            return;
        }

        $watched = ['entities_id', 'itilcategories_id', 'type', 'urgency'];
        if (empty(array_intersect($watched, $updates))) {
            return;
        }

        self::evaluateTicket($item);
    }

    /**
     * Hook item_purge em Ticket: remove solicitações de aprovação vinculadas ao chamado.
     *
     * @param CommonDBTM $item
     */
    public static function purgeTicketRequests($item): void
    {
        global $DB;

        if (!($item instanceof CommonDBTM)) {
            return;
        }
        $ticketsId = (int) $item->getID();
        if ($ticketsId <= 0 || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return;
        }

        $DB->delete(self::REQUESTS_TABLE, ['tickets_id' => $ticketsId]);
        if (isset($_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId])) {
            unset($_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId]);
        }
    }

    /**
     * Hook item_update em TicketValidation: sincroniza decisão nativa com glpi_plugin_approvalflow_requests.
     *
     * @param CommonDBTM $item
     */
    public static function onTicketValidationUpdate($item): void
    {
        global $DB;

        if (!($item instanceof CommonDBTM)) {
            return;
        }

        $tvId = (int) $item->getID();
        if ($tvId <= 0 || isset(self::$syncingValidations[$tvId])) {
            return;
        }

        if (!isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return;
        }

        $tvStatus = (int) ($item->fields['status'] ?? 0);
        $mapped   = self::mapValidationStatusToRequest($tvStatus);
        if ($mapped === null) {
            return;
        }

        $it = $DB->request([
            'FROM'  => self::REQUESTS_TABLE,
            'WHERE' => [
                'ticketvalidations_id' => $tvId,
                'status'               => self::STATUS_PENDING,
            ],
        ]);

        $now        = date('Y-m-d H:i:s');
        $comment    = PluginApprovalflowCompat::fromCore((string) ($item->fields['comment_validation'] ?? ''));
        $decidedAt  = (string) ($item->fields['validation_date'] ?? '');
        $deciderUid = class_exists('Session') && method_exists('Session', 'getLoginUserID')
            ? max(0, (int) Session::getLoginUserID(true))
            : 0;
        if ($deciderUid <= 0 && isset($item->fields['users_id_validate'])) {
            $deciderUid = max(0, (int) $item->fields['users_id_validate']);
        }
        if (
            $deciderUid <= 0
            && (string) ($item->fields['itemtype_target'] ?? '') === 'User'
            && isset($item->fields['items_id_target'])
        ) {
            $deciderUid = max(0, (int) $item->fields['items_id_target']);
        }

        foreach ($it as $req) {
            $reqId = (int) ($req['id'] ?? 0);
            if ($reqId <= 0) {
                continue;
            }

            $updateData = [
                'status'   => $mapped,
                'comment'  => $comment,
                'date_mod' => $now,
            ];

            if ($mapped === self::STATUS_APPROVED || $mapped === self::STATUS_REFUSED) {
                $updateData['token_hash']          = '';
                $updateData['decided_at']          = $decidedAt !== '' ? $decidedAt : $now;
                $updateData['decided_by_users_id'] = $deciderUid;
            }

            $DB->update(
                self::REQUESTS_TABLE,
                PluginApprovalflowCompat::dbValues($updateData),
                ['id' => $reqId]
            );

            if ($mapped === self::STATUS_APPROVED) {
                self::cancelSiblingGroupRequestsIfAnyOne((int) ($req['rules_id'] ?? 0), (int) ($req['tickets_id'] ?? 0), $reqId);
            }
        }
    }

    /**
     * Hook item_purge em TicketValidation: cancela solicitações pendentes vinculadas.
     *
     * @param CommonDBTM $item
     */
    public static function onTicketValidationPurge($item): void
    {
        global $DB;

        if (!($item instanceof CommonDBTM)) {
            return;
        }
        $tvId = (int) $item->getID();
        if ($tvId <= 0 || isset(self::$syncingValidations[$tvId]) || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return;
        }

        $DB->update(
            self::REQUESTS_TABLE,
            [
                'status'     => self::STATUS_CANCELLED,
                'token_hash' => '',
                'date_mod'   => date('Y-m-d H:i:s'),
            ],
            [
                'ticketvalidations_id' => $tvId,
                'status'               => self::STATUS_PENDING,
            ]
        );
    }

    /**
     * Hook pre_item_add em ITILSolution: impede criação de solução enquanto houver aprovação pendente bloqueante.
     *
     * @param CommonDBTM $item
     */
    public static function preItemAddSolution($item): void
    {
        if (!($item instanceof CommonDBTM) || !is_array($item->input) || empty($item->input)) {
            return;
        }
        if (self::isNonInteractiveContext()) {
            return;
        }

        $itemtype = (string) ($item->input['itemtype'] ?? 'Ticket');
        if ($itemtype !== 'Ticket') {
            return;
        }

        $ticketsId = (int) ($item->input['items_id'] ?? 0);
        if ($ticketsId <= 0) {
            return;
        }

        if (self::hasBlockingPendingRequests($ticketsId)) {
            $item->input = false;
            Session::addMessageAfterRedirect(
                __('Não é possível solucionar o chamado enquanto houver aprovações pendentes no fluxo de aprovação.', 'approvalflow'),
                true,
                ERROR
            );
        }
    }

    /**
     * Hook pre_item_update em Ticket: impede transição para Solucionado/Fechado enquanto houver aprovação pendente bloqueante.
     *
     * @param CommonDBTM $item
     */
    public static function preItemUpdateTicket($item): void
    {
        if (!($item instanceof CommonDBTM) || !is_array($item->input) || empty($item->input)) {
            return;
        }
        if (self::isNonInteractiveContext()) {
            return;
        }
        if (!array_key_exists('status', $item->input)) {
            return;
        }

        $newStatus = (int) $item->input['status'];
        if ($newStatus !== self::TICKET_SOLVED && $newStatus !== self::TICKET_CLOSED) {
            return;
        }

        $currentStatus = (int) ($item->fields['status'] ?? 0);
        if ($currentStatus === $newStatus) {
            return;
        }

        $ticketsId = (int) ($item->fields['id'] ?? ($item->input['id'] ?? 0));
        if ($ticketsId <= 0) {
            return;
        }

        if (self::hasBlockingPendingRequests($ticketsId)) {
            $item->input = false;
            Session::addMessageAfterRedirect(
                __('Não é possível solucionar ou fechar o chamado enquanto houver aprovações pendentes.', 'approvalflow'),
                true,
                ERROR
            );
        }
    }

    /**
     * Verifica se o chamado possui ao menos uma solicitação pendente com bloqueio ativo.
     */
    public static function hasBlockingPendingRequests(int $ticketsId): bool
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return false;
        }

        $it = $DB->request([
            'SELECT' => ['id', 'rules_id'],
            'FROM'   => self::REQUESTS_TABLE,
            'WHERE'  => [
                'tickets_id' => $ticketsId,
                'status'     => self::STATUS_PENDING,
            ],
        ]);

        foreach ($it as $row) {
            $ruleId = (int) ($row['rules_id'] ?? 0);
            if ($ruleId <= 0) {
                continue;
            }
            $rule = PluginApprovalflowConfig::getRule($ruleId);
            if ($rule !== null && !empty($rule['block_solve_while_pending'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Avalia regras aplicáveis a um chamado e dispara as aprovações ainda não emitidas.
     *
     * @param CommonDBTM $ticket
     * @return array<int, array<string, mixed>> Solicitações criadas nesta avaliação
     */
    public static function evaluateTicket($ticket): array
    {
        global $DB;

        if (!($ticket instanceof CommonDBTM) || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return [];
        }

        $fields    = is_array($ticket->fields) ? $ticket->fields : [];
        $ticketsId = (int) ($fields['id'] ?? $ticket->getID());
        if ($ticketsId <= 0) {
            return [];
        }
        if (!empty($fields['is_deleted'])) {
            return [];
        }

        $status = (int) ($fields['status'] ?? 0);
        if ($status === self::TICKET_SOLVED || $status === self::TICKET_CLOSED) {
            return [];
        }

        if (isset(self::$dispatchingTickets[$ticketsId])) {
            return [];
        }

        self::$dispatchingTickets[$ticketsId] = true;
        $created                              = [];

        try {
            $entitiesId = (int) ($fields['entities_id'] ?? 0);
            $categoryId = (int) ($fields['itilcategories_id'] ?? 0);
            $ticketType = (int) ($fields['type'] ?? 0);
            $urgency    = max(1, (int) ($fields['urgency'] ?? 3));

            $rules = PluginApprovalflowConfig::getApplicableRules($entitiesId);
            foreach ($rules as $rule) {
                $ruleId = (int) ($rule['id'] ?? 0);
                if ($ruleId <= 0 || empty($rule['is_active'])) {
                    continue;
                }
                if ((int) $rule['approver_id'] <= 0) {
                    continue;
                }
                if ((int) $rule['itilcategories_id'] > 0 && (int) $rule['itilcategories_id'] !== $categoryId) {
                    continue;
                }
                if ((int) $rule['ticket_type'] > 0 && (int) $rule['ticket_type'] !== $ticketType) {
                    continue;
                }
                if ($urgency < (int) $rule['min_urgency']) {
                    continue;
                }
                if (self::hasExistingRuleRequest($ruleId, $ticketsId)) {
                    continue;
                }

                $ruleCreated = self::dispatchRuleForTicket($rule, $ticket, $fields);
                foreach ($ruleCreated as $entry) {
                    $created[] = $entry;
                }
            }
        } finally {
            unset(self::$dispatchingTickets[$ticketsId]);
        }

        return $created;
    }

    /**
     * Verifica se já existe solicitação ativa ou concluída para o par (rules_id, tickets_id).
     */
    public static function hasExistingRuleRequest(int $rulesId, int $ticketsId): bool
    {
        global $DB;

        if ($rulesId <= 0 || $ticketsId <= 0 || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return false;
        }

        $it = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::REQUESTS_TABLE,
            'WHERE'  => [
                'rules_id'   => $rulesId,
                'tickets_id' => $ticketsId,
                'status'     => [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REFUSED],
            ],
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            return true;
        }

        return false;
    }

    /**
     * Dispara validações nativas e registra solicitações no fluxo para uma regra casada.
     *
     * @param array<string, mixed> $rule
     * @param CommonDBTM           $ticket
     * @param array<string, mixed> $ticketFields
     * @return array<int, array<string, mixed>>
     */
    private static function dispatchRuleForTicket(array $rule, $ticket, array $ticketFields): array
    {
        global $DB;

        $ticketsId    = (int) ($ticketFields['id'] ?? $ticket->getID());
        $entitiesId   = (int) ($ticketFields['entities_id'] ?? 0);
        $ruleId       = (int) $rule['id'];
        $approverType = (string) ($rule['approver_type'] ?? 'user');
        $approverId   = (int) ($rule['approver_id'] ?? 0);

        $hasTargetCols = $DB->fieldExists('glpi_ticketvalidations', 'itemtype_target');
        $targets       = [];

        if ($approverType === 'group') {
            if ($hasTargetCols && empty($rule['require_all_group_members'])) {
                $targets[] = [
                    'type'      => 'Group',
                    'users_id'  => 0,
                    'groups_id' => $approverId,
                ];
            } else {
                $members = self::getActiveGroupMembers($approverId);
                foreach ($members as $memberUid) {
                    $targets[] = [
                        'type'      => 'User',
                        'users_id'  => $memberUid,
                        'groups_id' => $approverId,
                    ];
                }
            }
        } else {
            $targets[] = [
                'type'      => 'User',
                'users_id'  => $approverId,
                'groups_id' => 0,
            ];
        }

        if (empty($targets)) {
            return [];
        }

        $submitterId = self::resolveSubmitterUserId($ticketsId, $ticketFields);
        $useNative   = !empty($rule['use_native_validation']) && class_exists('TicketValidation');
        $genToken    = !empty($rule['generate_token_link']);
        $ttlDays     = max(1, (int) ($rule['token_ttl_days'] ?? self::DEFAULT_TOKEN_TTL_DAYS));
        $now         = date('Y-m-d H:i:s');
        $created     = [];

        foreach ($targets as $target) {
            $tvId = 0;
            if ($useNative) {
                $commentSubmission = sprintf(
                    function_exists('__') ? __('Aprovação automática pela regra: %s', 'approvalflow') : 'Aprovação automática pela regra: %s',
                    (string) ($rule['name'] ?? '')
                );
                $tvInput = [
                    'tickets_id'         => $ticketsId,
                    'users_id'           => $submitterId,
                    'status'             => self::VALIDATION_WAITING,
                    'comment_submission' => $commentSubmission,
                ];
                if ($hasTargetCols) {
                    $tvInput['itemtype_target'] = $target['type'];
                    $tvInput['items_id_target'] = ($target['type'] === 'Group') ? $target['groups_id'] : $target['users_id'];
                } else {
                    $tvInput['users_id_validate'] = $target['users_id'];
                }

                $tv  = new TicketValidation();
                $res = $tv->add(PluginApprovalflowCompat::coreInput($tvInput));
                if ($res !== false && (int) $res > 0) {
                    $tvId = (int) $res;
                }
            }

            $rawToken  = '';
            $tokenHash = '';
            $expiresAt = null;

            if ($genToken) {
                $rawToken  = self::generateRawToken();
                $tokenHash = self::hashToken($rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + ($ttlDays * 86400));
            }

            $reqPayload = [
                'rules_id'             => $ruleId,
                'tickets_id'           => $ticketsId,
                'entities_id'          => $entitiesId,
                'ticketvalidations_id' => $tvId,
                'approver_type'        => $approverType,
                'approver_users_id'    => (int) $target['users_id'],
                'approver_groups_id'   => (int) $target['groups_id'],
                'token_hash'           => $tokenHash,
                'status'               => self::STATUS_PENDING,
                'comment'              => '',
                'decided_by_users_id'  => 0,
                'expires_at'           => $expiresAt,
                'decided_at'           => null,
                'date_creation'        => $now,
                'date_mod'             => $now,
            ];

            $inserted = $DB->insert(self::REQUESTS_TABLE, PluginApprovalflowCompat::dbValues($reqPayload));
            if (!$inserted) {
                continue;
            }

            $reqId = method_exists($DB, 'insertId') ? (int) $DB->insertId() : 0;
            if ($rawToken !== '' && $reqId > 0) {
                $sessionUid = (class_exists('Session') && method_exists('Session', 'getLoginUserID'))
                    ? max(0, (int) Session::getLoginUserID(true))
                    : 0;
                if (self::canViewFlashTokenForRequest($ticketsId, (int) $target['users_id'], $sessionUid, $ticketFields)) {
                    if (!isset($_SESSION['plugin_approvalflow_flash_tokens']) || !is_array($_SESSION['plugin_approvalflow_flash_tokens'])) {
                        $_SESSION['plugin_approvalflow_flash_tokens'] = [];
                    }
                    if (!isset($_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId]) || !is_array($_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId])) {
                        $_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId] = [];
                    }
                    $_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId][$reqId] = $rawToken;
                }
            }

            $created[] = [
                'id'                   => $reqId,
                'rules_id'             => $ruleId,
                'tickets_id'           => $ticketsId,
                'ticketvalidations_id' => $tvId,
                'approver_type'        => $approverType,
                'approver_users_id'    => (int) $target['users_id'],
                'approver_groups_id'   => (int) $target['groups_id'],
                'raw_token'            => $rawToken,
                'expires_at'           => $expiresAt,
            ];
        }

        return $created;
    }

    /**
     * Resolve membros ativos de um grupo via glpi_groups_users (+ glpi_users quando disponível).
     *
     * @return int[]
     */
    public static function getActiveGroupMembers(int $groupsId): array
    {
        global $DB;

        if ($groupsId <= 0 || !isset($DB) || !$DB->tableExists('glpi_groups_users')) {
            return [];
        }

        $candidateIds = [];
        $it           = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['groups_id' => $groupsId],
            'ORDER'  => ['users_id ASC'],
        ]);

        foreach ($it as $row) {
            $uid = (int) ($row['users_id'] ?? 0);
            if ($uid > 0 && !in_array($uid, $candidateIds, true)) {
                $candidateIds[] = $uid;
            }
        }

        if (empty($candidateIds)) {
            return [];
        }

        if (!$DB->tableExists('glpi_users')) {
            return $candidateIds;
        }

        $userRows = [];
        $hasUsers = false;
        $uIt      = $DB->request([
            'SELECT' => ['id', 'is_active', 'is_deleted'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $candidateIds],
        ]);
        foreach ($uIt as $uRow) {
            $hasUsers                      = true;
            $userRows[(int) $uRow['id']] = $uRow;
        }

        if (!$hasUsers) {
            // Verifica se glpi_users possui registros; se vazia em mock mínimo, mantém candidatos.
            $anyIt = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'LIMIT' => 1]);
            foreach ($anyIt as $any) {
                return [];
            }
            return $candidateIds;
        }

        $active = [];
        foreach ($candidateIds as $uid) {
            if (!isset($userRows[$uid])) {
                continue;
            }
            $u = $userRows[$uid];
            if ((int) ($u['is_active'] ?? 1) === 1 && (int) ($u['is_deleted'] ?? 0) === 0) {
                $active[] = $uid;
            }
        }

        return $active;
    }

    /**
     * Resolve o usuário solicitante para atribuição no TicketValidation nativo.
     *
     * @param array<string, mixed> $ticketFields
     */
    private static function resolveSubmitterUserId(int $ticketsId, array $ticketFields): int
    {
        global $DB;

        if (class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            $uid = (int) Session::getLoginUserID(true);
            if ($uid > 0) {
                return $uid;
            }
        }

        $recipient = (int) ($ticketFields['users_id_recipient'] ?? 0);
        if ($recipient > 0) {
            return $recipient;
        }

        if ($ticketsId > 0 && isset($DB) && $DB->tableExists('glpi_tickets_users')) {
            $it = $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_tickets_users',
                'WHERE'  => ['tickets_id' => $ticketsId, 'type' => 1],
                'LIMIT'  => 1,
            ]);
            foreach ($it as $row) {
                $uid = (int) ($row['users_id'] ?? 0);
                if ($uid > 0) {
                    return $uid;
                }
            }
        }

        return 1;
    }

    /**
     * Gera um token aleatório criptográfico de 32 bytes codificado em base64url.
     */
    public static function generateRawToken(): string
    {
        $bytes = random_bytes(32);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Calcula o hash SHA-256 hexadecimal de um token bruto.
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Localiza uma solicitação de aprovação a partir do token bruto (sem armazená-lo no banco).
     */
    public static function findRequestByToken(string $rawToken): ?array
    {
        global $DB;

        $rawToken = trim($rawToken);
        if ($rawToken === '' || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return null;
        }

        $expectedHash = self::hashToken($rawToken);
        $it           = $DB->request([
            'FROM'  => self::REQUESTS_TABLE,
            'WHERE' => ['token_hash' => $expectedHash],
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            $storedHash = (string) ($row['token_hash'] ?? '');
            if ($storedHash !== '' && hash_equals($expectedHash, $storedHash)) {
                return self::hydrateRequest($row);
            }
        }

        return null;
    }

    /**
     * Obtém uma solicitação pelo ID.
     */
    public static function getRequestById(int $requestId): ?array
    {
        global $DB;

        if ($requestId <= 0 || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return null;
        }

        $it = $DB->request([
            'FROM'  => self::REQUESTS_TABLE,
            'WHERE' => ['id' => $requestId],
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            return self::hydrateRequest($row);
        }

        return null;
    }

    /**
     * Verifica se o token da solicitação ainda está pendente, não vazio e dentro da validade.
     */
    public static function isTokenValid(array $request): bool
    {
        if (($request['status'] ?? '') !== self::STATUS_PENDING) {
            return false;
        }
        if ((string) ($request['token_hash'] ?? '') === '') {
            return false;
        }

        $expiresAt = (string) ($request['expires_at'] ?? '');
        if ($expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts !== false && $ts < time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Executa a decisão (approve/refuse) em uma solicitação pendente, limpa o token (uso único)
     * e sincroniza o TicketValidation nativo no GLPI.
     */
    public static function processDecision(int $requestId, string $decision, string $comment = '', int $deciderUserId = 0): bool
    {
        global $DB;

        if ($requestId <= 0 || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return false;
        }

        $decision = strtolower(trim($decision));
        if ($decision !== 'approve' && $decision !== 'refuse') {
            return false;
        }

        $request = self::getRequestById($requestId);
        if ($request === null || $request['status'] !== self::STATUS_PENDING) {
            return false;
        }

        $now       = date('Y-m-d H:i:s');
        $newStatus = ($decision === 'approve') ? self::STATUS_APPROVED : self::STATUS_REFUSED;
        $tvStatus  = ($decision === 'approve') ? self::VALIDATION_ACCEPTED : self::VALIDATION_REFUSED;
        $comment   = trim($comment);
        if (function_exists('mb_substr')) {
            $comment = mb_substr($comment, 0, 4000, 'UTF-8');
        } else {
            $comment = substr($comment, 0, 4000);
        }

        if ($deciderUserId <= 0 && (int) ($request['approver_users_id'] ?? 0) > 0) {
            $deciderUserId = (int) $request['approver_users_id'];
        }

        $updatePayload = [
            'status'              => $newStatus,
            'comment'             => $comment,
            'token_hash'          => '',
            'decided_by_users_id' => max(0, $deciderUserId),
            'decided_at'          => $now,
            'date_mod'            => $now,
        ];

        $updated = $DB->update(
            self::REQUESTS_TABLE,
            PluginApprovalflowCompat::dbValues($updatePayload),
            [
                'id'     => $requestId,
                'status' => self::STATUS_PENDING,
            ]
        );
        if (!$updated || (method_exists($DB, 'affectedRows') && (int) $DB->affectedRows() <= 0)) {
            return false;
        }

        if ($newStatus === self::STATUS_APPROVED) {
            self::cancelSiblingGroupRequestsIfAnyOne((int) $request['rules_id'], (int) $request['tickets_id'], $requestId);
        }

        $tvId = (int) ($request['ticketvalidations_id'] ?? 0);
        if ($tvId > 0 && class_exists('TicketValidation')) {
            self::$syncingValidations[$tvId] = true;
            try {
                $tv = new TicketValidation();
                if ($tv->getFromDB($tvId)) {
                    $tv->update(PluginApprovalflowCompat::coreInput([
                        'id'                 => $tvId,
                        'status'             => $tvStatus,
                        'validation_date'    => $now,
                        'comment_validation' => $comment,
                    ]));
                }
            } finally {
                unset(self::$syncingValidations[$tvId]);
            }
        }

        return true;
    }

    /**
     * Em regras de grupo onde qualquer membro aprova (require_all_group_members = 0),
     * cancela solicitações pendentes irmãs após uma aprovação bem-sucedida.
     */
    private static function cancelSiblingGroupRequestsIfAnyOne(int $rulesId, int $ticketsId, int $acceptedRequestId): void
    {
        global $DB;

        if ($rulesId <= 0 || $ticketsId <= 0 || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return;
        }

        $rule = PluginApprovalflowConfig::getRule($rulesId);
        if ($rule === null || $rule['approver_type'] !== 'group' || !empty($rule['require_all_group_members'])) {
            return;
        }

        $it = $DB->request([
            'FROM'  => self::REQUESTS_TABLE,
            'WHERE' => [
                'rules_id'   => $rulesId,
                'tickets_id' => $ticketsId,
                'status'     => self::STATUS_PENDING,
            ],
        ]);

        $now = date('Y-m-d H:i:s');
        foreach ($it as $row) {
            $sibId = (int) ($row['id'] ?? 0);
            if ($sibId <= 0 || $sibId === $acceptedRequestId) {
                continue;
            }
            $DB->update(
                self::REQUESTS_TABLE,
                [
                    'status'     => self::STATUS_CANCELLED,
                    'token_hash' => '',
                    'date_mod'   => $now,
                ],
                ['id' => $sibId]
            );

            $sibTvId = (int) ($row['ticketvalidations_id'] ?? 0);
            if ($sibTvId > 0 && class_exists('TicketValidation')) {
                self::$syncingValidations[$sibTvId] = true;
                try {
                    $sibTv = new TicketValidation();
                    if ($sibTv->getFromDB($sibTvId)) {
                        $sibTv->delete(['id' => $sibTvId], 1);
                    }
                } finally {
                    unset(self::$syncingValidations[$sibTvId]);
                }
            }
        }
    }

    /**
     * Verifica se o usuário é solicitante (requerente) do chamado.
     *
     * @param array<string, mixed> $ticketFields
     */
    public static function isTicketRequester(int $ticketsId, int $usersId, array $ticketFields = []): bool
    {
        global $DB;

        if ($ticketsId <= 0 || $usersId <= 0) {
            return false;
        }

        if (isset($ticketFields['users_id_recipient']) && (int) $ticketFields['users_id_recipient'] === $usersId) {
            return true;
        }

        if (isset($DB) && is_object($DB)) {
            if (empty($ticketFields) && $DB->tableExists('glpi_tickets')) {
                $tIt = $DB->request([
                    'SELECT' => ['users_id_recipient'],
                    'FROM'   => 'glpi_tickets',
                    'WHERE'  => ['id' => $ticketsId],
                    'LIMIT'  => 1,
                ]);
                foreach ($tIt as $tRow) {
                    if ((int) ($tRow['users_id_recipient'] ?? 0) === $usersId) {
                        return true;
                    }
                    break;
                }
            }

            if ($DB->tableExists('glpi_tickets_users')) {
                $uIt = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_tickets_users',
                    'WHERE'  => [
                        'tickets_id' => $ticketsId,
                        'users_id'   => $usersId,
                        'type'       => 1,
                    ],
                    'LIMIT'  => 1,
                ]);
                foreach ($uIt as $uRow) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verifica se o usuário em sessão pode visualizar o token flash recém-gerado para uma solicitação.
     *
     * @param array<string, mixed> $ticketFields
     */
    public static function canViewFlashTokenForRequest(
        int $ticketsId,
        int $approverUsersId,
        int $sessionUid,
        array $ticketFields = []
    ): bool {
        if ($sessionUid <= 0) {
            return false;
        }

        if ($approverUsersId > 0 && $sessionUid === $approverUsersId) {
            return true;
        }

        if (
            class_exists('Session')
            && method_exists('Session', 'haveRight')
            && Session::haveRight('config', UPDATE)
            && !self::isTicketRequester($ticketsId, $sessionUid, $ticketFields)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Verifica se o usuário informado tem permissão para decidir uma solicitação específica.
     */
    public static function canUserDecideRequest(array $request, int $usersId): bool
    {
        global $DB;

        if ($usersId <= 0) {
            return false;
        }

        $assignedUserId = (int) ($request['approver_users_id'] ?? 0);
        if ($assignedUserId > 0) {
            if ($assignedUserId === $usersId) {
                return true;
            }
        } else {
            $groupId = (int) ($request['approver_groups_id'] ?? 0);
            if ($groupId > 0 && isset($DB) && $DB->tableExists('glpi_groups_users')) {
                if (in_array($usersId, self::getActiveGroupMembers($groupId), true)) {
                    return true;
                }
            }
        }

        if (class_exists('Session') && method_exists('Session', 'getLoginUserID') && method_exists('Session', 'haveRight')) {
            $sessionUid = (int) Session::getLoginUserID(true);
            if ($sessionUid > 0 && $sessionUid === $usersId && Session::haveRight('config', UPDATE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lista todas as solicitações de aprovação vinculadas a um chamado.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getRequestsForTicket(int $ticketsId): array
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return [];
        }

        $it = $DB->request([
            'FROM'  => self::REQUESTS_TABLE,
            'WHERE' => ['tickets_id' => $ticketsId],
            'ORDER' => ['id DESC'],
        ]);

        $ruleCache = [];
        $rows      = [];

        foreach ($it as $row) {
            $hydrated = self::hydrateRequest($row);
            $ruleId   = (int) $hydrated['rules_id'];
            if (!array_key_exists($ruleId, $ruleCache)) {
                $ruleCache[$ruleId] = PluginApprovalflowConfig::getRule($ruleId);
            }
            $rule                       = $ruleCache[$ruleId];
            $hydrated['rule_name']      = $rule !== null ? $rule['name'] : ('#' . $ruleId);
            $hydrated['blocks_closing'] = ($rule !== null && !empty($rule['block_solve_while_pending'])) ? 1 : 0;
            $rows[]                     = $hydrated;
        }

        return $rows;
    }

    /**
     * Conta solicitações de aprovação de um chamado (ou somente pendentes).
     */
    public static function countRequestsForTicket(int $ticketsId, bool $onlyPending = false): int
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !$DB->tableExists(self::REQUESTS_TABLE)) {
            return 0;
        }

        $where = ['tickets_id' => $ticketsId];
        if ($onlyPending) {
            $where['status'] = self::STATUS_PENDING;
        }

        $it = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::REQUESTS_TABLE,
            'WHERE' => $where,
        ]);

        foreach ($it as $row) {
            return (int) ($row['cpt'] ?? 0);
        }

        return 0;
    }

    /**
     * Consome os tokens recém-gerados em sessão (flash) para exibição única na aba do chamado.
     *
     * @return array<int, string> Mapa [requestId => rawToken]
     */
    public static function consumeFlashTokens(int $ticketsId): array
    {
        if (
            $ticketsId <= 0
            || !isset($_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId])
            || !is_array($_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId])
        ) {
            return [];
        }

        $tokens = $_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId];
        unset($_SESSION['plugin_approvalflow_flash_tokens'][$ticketsId]);
        return $tokens;
    }

    /**
     * Constrói a URL segura do endpoint de aprovação em duas etapas.
     */
    public static function buildApproveUrl(string $rawToken, bool $absolute = true): string
    {
        return PluginApprovalflowCompat::webPath($absolute)
            . '/front/approve.php?token='
            . rawurlencode($rawToken);
    }

    /**
     * Hidrata uma linha da tabela de solicitações.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateRequest(array $row): array
    {
        return [
            'id'                   => (int) ($row['id'] ?? 0),
            'rules_id'             => (int) ($row['rules_id'] ?? 0),
            'tickets_id'           => (int) ($row['tickets_id'] ?? 0),
            'entities_id'          => (int) ($row['entities_id'] ?? 0),
            'ticketvalidations_id' => (int) ($row['ticketvalidations_id'] ?? 0),
            'approver_type'        => (string) ($row['approver_type'] ?? 'user'),
            'approver_users_id'    => (int) ($row['approver_users_id'] ?? 0),
            'approver_groups_id'   => (int) ($row['approver_groups_id'] ?? 0),
            'token_hash'           => (string) ($row['token_hash'] ?? ''),
            'status'               => (string) ($row['status'] ?? self::STATUS_PENDING),
            'comment'              => PluginApprovalflowCompat::fromCore((string) ($row['comment'] ?? '')),
            'decided_by_users_id'  => (int) ($row['decided_by_users_id'] ?? 0),
            'expires_at'           => $row['expires_at'] ?? null,
            'decided_at'           => $row['decided_at'] ?? null,
            'date_creation'        => $row['date_creation'] ?? null,
            'date_mod'             => $row['date_mod'] ?? null,
        ];
    }

    /**
     * Converte status numérico de CommonITILValidation para o status textual do fluxo.
     */
    private static function mapValidationStatusToRequest(int $tvStatus): ?string
    {
        if ($tvStatus === self::VALIDATION_WAITING) {
            return self::STATUS_PENDING;
        }
        if ($tvStatus === self::VALIDATION_ACCEPTED) {
            return self::STATUS_APPROVED;
        }
        if ($tvStatus === self::VALIDATION_REFUSED) {
            return self::STATUS_REFUSED;
        }
        return null;
    }
}
