<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Aba "Aprovações" exibida nos chamados do GLPI
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

require_once __DIR__ . '/compat.class.php';
require_once __DIR__ . '/config.class.php';
require_once __DIR__ . '/flow.class.php';

interface PluginApprovalflowTabConstants
{
    public const TAB_NUM = 1;
}

trait PluginApprovalflowTabBody
{
    /**
     * Nome exibido na aba do chamado.
     */
    public static function getTypeName($nb = 0): string
    {
        return __('Aprovações', 'approvalflow');
    }

    /**
     * Verifica direito de leitura de chamados para exibição da aba.
     */
    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::$rightname, READ);
    }

    /**
     * Escapa valores para saída segura em HTML.
     */
    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Verifica se o usuário atual pode acessar a aba neste chamado.
     */
    private static function canAccessTicket(CommonGLPI $item, $withtemplate = 0): bool
    {
        if ($withtemplate || !($item instanceof Ticket)) {
            return false;
        }
        $ticketsId = (int) $item->getID();
        if ($ticketsId <= 0) {
            return false;
        }
        if ((int) Session::getLoginUserID(true) <= 0) {
            return false;
        }
        if (!$item->canViewItem()) {
            return false;
        }
        if (
            method_exists($item, 'getEntityID')
            && method_exists('Session', 'haveAccessToEntity')
            && !Session::haveAccessToEntity((int) $item->getEntityID())
        ) {
            return false;
        }
        return true;
    }

    /**
     * Retorna o rótulo da aba com o contador de aprovações vinculadas.
     *
     * @return string|array
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!self::canAccessTicket($item, $withtemplate)) {
            return '';
        }

        $count = PluginApprovalflowFlow::countRequestsForTicket((int) $item->getID(), false);
        return self::createTabEntry(__('Aprovações', 'approvalflow'), $count);
    }

    /**
     * Renderiza o conteúdo da aba de aprovações no chamado.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!self::canAccessTicket($item, $withtemplate)) {
            return false;
        }

        global $CFG_GLPI;

        $ticketsId    = (int) $item->getID();
        $requests     = PluginApprovalflowFlow::getRequestsForTicket($ticketsId);
        $rawFlash     = PluginApprovalflowFlow::consumeFlashTokens($ticketsId);
        $loginUid     = (int) Session::getLoginUserID(true);
        $ticketFields = is_array($item->fields) ? $item->fields : [];
        $rootDoc      = isset($CFG_GLPI['root_doc']) ? rtrim((string) $CFG_GLPI['root_doc'], '/') : '';
        $baseWeb      = PluginApprovalflowCompat::webPath(false);

        $requestsById = [];
        $pendingCount = 0;
        foreach ($requests as $req) {
            $requestsById[(int) $req['id']] = $req;
            if ($req['status'] === PluginApprovalflowFlowConstants::STATUS_PENDING) {
                $pendingCount++;
            }
        }

        $flashTokens = [];
        foreach ($rawFlash as $reqId => $rawToken) {
            $reqIdInt    = (int) $reqId;
            $approverUid = isset($requestsById[$reqIdInt]) ? (int) ($requestsById[$reqIdInt]['approver_users_id'] ?? 0) : 0;
            if (PluginApprovalflowFlow::canViewFlashTokenForRequest($ticketsId, $approverUid, $loginUid, $ticketFields)) {
                $flashTokens[$reqIdInt] = (string) $rawToken;
            }
        }

        echo '<div class="approvalflow-tab">';
        echo '<div class="card border-0 shadow-none">';
        echo '<div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">';
        echo '<h3 class="card-title mb-0">'
            . '<i class="ti ti-shield-check me-2" aria-hidden="true"></i>'
            . self::escapeHtml(__('Fluxo de aprovação do chamado', 'approvalflow'))
            . '</h3>';
        echo '<div class="d-flex align-items-center gap-2">';
        if ($pendingCount > 0) {
            echo '<span class="approvalflow-badge approvalflow-badge-pending">'
                . self::escapeHtml(sprintf(__('%d pendente(s)', 'approvalflow'), $pendingCount))
                . '</span>';
        }
        echo '<span class="approvalflow-badge approvalflow-badge-neutral">'
            . self::escapeHtml((string) count($requests))
            . '</span>';
        echo '</div></div>';

        if (!empty($flashTokens)) {
            echo '<div class="card-body pb-0">';
            echo '<div class="alert alert-warning approvalflow-flash-box mb-3">';
            echo '<div class="fw-bold mb-1">'
                . self::escapeHtml(__('Link(s) de aprovação gerados agora (exibição única)', 'approvalflow'))
                . '</div>';
            echo '<p class="small mb-2">'
                . self::escapeHtml(__('Copie e compartilhe com o aprovador. Por segurança, o token bruto não é armazenado no banco de dados.', 'approvalflow'))
                . '</p>';
            foreach ($flashTokens as $reqId => $rawToken) {
                $approveUrl = PluginApprovalflowFlow::buildApproveUrl((string) $rawToken, true);
                echo '<div class="mb-2">';
                echo '<label class="form-label small mb-1">'
                    . self::escapeHtml(sprintf(__('Solicitação #%d', 'approvalflow'), (int) $reqId))
                    . '</label>';
                echo '<input type="text" class="form-control form-control-sm font-monospace" readonly value="'
                    . self::escapeHtml($approveUrl)
                    . '">';
                echo '</div>';
            }
            echo '</div></div>';
        }

        if ($requests === []) {
            echo '<div class="card-body">';
            echo '<div class="alert alert-info mb-0">'
                . self::escapeHtml(__('Nenhuma solicitação de aprovação disparada para este chamado até o momento.', 'approvalflow'))
                . '</div>';
            echo '</div>';
        } else {
            echo '<div class="table-responsive">';
            echo '<table class="table table-hover table-striped card-table mb-0 approvalflow-table">';
            echo '<thead><tr>';
            echo '<th scope="col">' . self::escapeHtml(__('Regra', 'approvalflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Aprovador', 'approvalflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Status', 'approvalflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Validação nativa', 'approvalflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Decisão / Observação', 'approvalflow')) . '</th>';
            echo '<th scope="col" class="text-end">' . self::escapeHtml(__('Ação', 'approvalflow')) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($requests as $req) {
                $reqId       = (int) $req['id'];
                $tvId        = (int) $req['ticketvalidations_id'];
                $status      = (string) $req['status'];
                $statusInfo  = self::formatStatusBadge($status);
                $approverTxt = self::resolveApproverLabel($req);
                $commentTxt  = trim((string) ($req['comment'] ?? ''));
                $decidedRaw  = (string) ($req['decided_at'] ?? '');
                $expiresRaw  = (string) ($req['expires_at'] ?? '');

                $nativeHtml = '-';
                if ($tvId > 0) {
                    $ticketUrl  = $rootDoc . '/front/ticket.form.php?id=' . $ticketsId . '&forcetab=TicketValidation$1';
                    $nativeHtml = '<a href="' . self::escapeHtml($ticketUrl) . '">'
                        . self::escapeHtml(sprintf(__('Validação #%d', 'approvalflow'), $tvId))
                        . '</a>';
                }

                $decisionHtml = '-';
                if ($status === PluginApprovalflowFlowConstants::STATUS_PENDING) {
                    if ($expiresRaw !== '') {
                        $expText      = class_exists('Html') && method_exists('Html', 'convDateTime')
                            ? (string) Html::convDateTime($expiresRaw)
                            : $expiresRaw;
                        $decisionHtml = '<span class="text-muted small">'
                            . self::escapeHtml(sprintf(__('Expira em: %s', 'approvalflow'), $expText))
                            . '</span>';
                    }
                } else {
                    $parts = [];
                    if ($decidedRaw !== '') {
                        $decText = class_exists('Html') && method_exists('Html', 'convDateTime')
                            ? (string) Html::convDateTime($decidedRaw)
                            : $decidedRaw;
                        $parts[] = self::escapeHtml($decText);
                    }
                    if ($commentTxt !== '') {
                        $parts[] = '<div class="small text-muted">' . self::escapeHtml($commentTxt) . '</div>';
                    }
                    if (!empty($parts)) {
                        $decisionHtml = implode(' ', $parts);
                    }
                }

                $actionHtml = '-';
                if ($status === PluginApprovalflowFlowConstants::STATUS_PENDING) {
                    if (isset($flashTokens[$reqId])) {
                        $url        = PluginApprovalflowFlow::buildApproveUrl((string) $flashTokens[$reqId], false);
                        $actionHtml = '<a class="btn btn-sm btn-primary" href="' . self::escapeHtml($url) . '">'
                            . self::escapeHtml(__('Abrir link', 'approvalflow'))
                            . '</a>';
                    } elseif (PluginApprovalflowFlow::canUserDecideRequest($req, $loginUid)) {
                        $url        = $baseWeb . '/front/approve.php?request_id=' . $reqId;
                        $actionHtml = '<a class="btn btn-sm btn-outline-primary" href="' . self::escapeHtml($url) . '">'
                            . self::escapeHtml(__('Decidir', 'approvalflow'))
                            . '</a>';
                    }
                }

                echo '<tr>';
                echo '<td><strong>' . self::escapeHtml((string) $req['rule_name']) . '</strong></td>';
                echo '<td>' . self::escapeHtml($approverTxt) . '</td>';
                echo '<td><span class="approvalflow-badge ' . self::escapeHtml($statusInfo['class']) . '">'
                    . self::escapeHtml($statusInfo['label'])
                    . '</span></td>';
                echo '<td>' . $nativeHtml . '</td>';
                echo '<td>' . $decisionHtml . '</td>';
                echo '<td class="text-end">' . $actionHtml . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }

        echo '</div></div>';
        return true;
    }

    /**
     * Formata rótulo e classe CSS do status da solicitação.
     *
     * @return array{label: string, class: string}
     */
    private static function formatStatusBadge(string $status): array
    {
        if ($status === PluginApprovalflowFlowConstants::STATUS_APPROVED) {
            return ['label' => __('Aprovado', 'approvalflow'), 'class' => 'approvalflow-badge-approved'];
        }
        if ($status === PluginApprovalflowFlowConstants::STATUS_REFUSED) {
            return ['label' => __('Recusado', 'approvalflow'), 'class' => 'approvalflow-badge-refused'];
        }
        if ($status === PluginApprovalflowFlowConstants::STATUS_CANCELLED) {
            return ['label' => __('Cancelado', 'approvalflow'), 'class' => 'approvalflow-badge-cancelled'];
        }
        return ['label' => __('Pendente', 'approvalflow'), 'class' => 'approvalflow-badge-pending'];
    }

    /**
     * Resolve o nome legível do usuário ou grupo aprovador.
     *
     * @param array<string, mixed> $req
     */
    private static function resolveApproverLabel(array $req): string
    {
        $uid = (int) ($req['approver_users_id'] ?? 0);
        $gid = (int) ($req['approver_groups_id'] ?? 0);

        if ($uid > 0) {
            if (class_exists('Dropdown') && method_exists('Dropdown', 'getDropdownName')) {
                $name = trim(PluginApprovalflowCompat::fromCore((string) Dropdown::getDropdownName('glpi_users', $uid)));
                if ($name !== '' && $name !== '&nbsp;') {
                    return $name;
                }
            }
            return sprintf(__('Usuário #%d', 'approvalflow'), $uid);
        }

        if ($gid > 0) {
            if (class_exists('Dropdown') && method_exists('Dropdown', 'getDropdownName')) {
                $name = trim(PluginApprovalflowCompat::fromCore((string) Dropdown::getDropdownName('glpi_groups', $gid)));
                if ($name !== '' && $name !== '&nbsp;') {
                    return sprintf(__('Grupo: %s', 'approvalflow'), $name);
                }
            }
            return sprintf(__('Grupo #%d', 'approvalflow'), $gid);
        }

        return '-';
    }
}

if (!class_exists('PluginApprovalflowTab', false)) {
    if (PluginApprovalflowCompat::typedRightname()) {
        class PluginApprovalflowTab extends CommonGLPI implements PluginApprovalflowTabConstants
        {
            use PluginApprovalflowTabBody;

            public static string $rightname = 'ticket';
        }
    } else {
        class PluginApprovalflowTab extends CommonGLPI implements PluginApprovalflowTabConstants
        {
            use PluginApprovalflowTabBody;

            public static $rightname = 'ticket';
        }
    }
}
