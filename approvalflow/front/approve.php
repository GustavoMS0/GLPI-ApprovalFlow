<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Endpoint seguro de aprovação em duas etapas (GET / POST)
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
    $SECURITY_STRATEGY = 'no_check';
    include_once __DIR__ . '/../../../inc/includes.php';
}

include_once __DIR__ . '/../inc/compat.class.php';
include_once __DIR__ . '/../inc/config.class.php';
include_once __DIR__ . '/../inc/flow.class.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
}

/** @var DBmysql $DB */
global $DB, $CFG_GLPI;

$self = PluginApprovalflowCompat::webPath(false) . '/front/approve.php';
$esc  = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$method     = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isPost     = ($method === 'POST');
$rawToken   = trim((string) ($isPost ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '')));
$reqIdParam = filter_var(
    $isPost ? ($_POST['request_id'] ?? null) : ($_GET['request_id'] ?? null),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$loginUid = (class_exists('Session') && method_exists('Session', 'getLoginUserID'))
    ? max(0, (int) Session::getLoginUserID(true))
    : 0;

$renderPageStart = static function (string $title, int $uid) use ($esc): void {
    if ($uid > 0 && class_exists('Html') && method_exists('Html', 'header')) {
        Html::header($title, '', 'helpdesk', 'ticket');
        return;
    }
    $cssHref = PluginApprovalflowCompat::webPath(false) . '/' . PluginApprovalflowCompat::assetPath('css/approvalflow.css');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>' . $esc($title) . '</title>'
        . '<link rel="stylesheet" href="' . $esc($cssHref) . '">'
        . '</head><body>';
};

$renderPageEnd = static function (int $uid): void {
    if ($uid > 0 && class_exists('Html') && method_exists('Html', 'footer')) {
        Html::footer();
        return;
    }
    echo '</body></html>';
};

$request   = null;
$tokenMode = false;
$tokenErr  = '';

if ($rawToken !== '') {
    $tokenMode = true;
    if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $rawToken)) {
        $tokenErr = __('Link de aprovação inválido.', 'approvalflow');
    } else {
        $candidate = PluginApprovalflowFlow::findRequestByToken($rawToken);
        if ($candidate === null) {
            $tokenErr = __('Link de aprovação inválido ou já utilizado.', 'approvalflow');
        } elseif (!PluginApprovalflowFlow::isTokenValid($candidate)) {
            $tokenErr = __('Esta solicitação de aprovação expirou ou já foi decidida.', 'approvalflow');
        } else {
            $request = $candidate;
        }
    }

    if ($request !== null) {
        $rule         = PluginApprovalflowConfig::getRule((int) $request['rules_id']);
        $requireLogin = ($rule === null) ? 1 : (int) ($rule['require_login_for_token'] ?? 1);
        if ($requireLogin !== 0) {
            if (
                $loginUid <= 0
                || !Session::haveAccessToEntity((int) $request['entities_id'])
                || !PluginApprovalflowFlow::canUserDecideRequest($request, $loginUid)
            ) {
                PluginApprovalflowCompat::denyAccess();
                return;
            }
        }
    } elseif ($isPost) {
        PluginApprovalflowCompat::denyAccess();
        return;
    }
} elseif ($reqIdParam !== false) {
    if ($loginUid <= 0) {
        PluginApprovalflowCompat::denyAccess();
        return;
    }
    $candidate = PluginApprovalflowFlow::getRequestById((int) $reqIdParam);
    if (
        $candidate === null
        || !Session::haveAccessToEntity((int) $candidate['entities_id'])
        || !PluginApprovalflowFlow::canUserDecideRequest($candidate, $loginUid)
    ) {
        PluginApprovalflowCompat::denyAccess();
        return;
    }
    $request = $candidate;
} else {
    PluginApprovalflowCompat::denyAccess();
    return;
}

if ($isPost) {
    if (!PluginApprovalflowCompat::requireSafePost()) {
        PluginApprovalflowCompat::denyAccess();
        return;
    }

    if ($request === null || $request['status'] !== PluginApprovalflowFlowConstants::STATUS_PENDING) {
        PluginApprovalflowCompat::denyAccess();
        return;
    }

    PluginApprovalflowCompat::normalizeInput();
    $decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
    if ($decision !== 'approve' && $decision !== 'refuse') {
        PluginApprovalflowCompat::denyAccess();
        return;
    }

    $comment = trim((string) ($_POST['comment'] ?? ''));
    $ok      = PluginApprovalflowFlow::processDecision((int) $request['id'], $decision, $comment, $loginUid);

    if (!$ok) {
        PluginApprovalflowCompat::denyAccess();
        return;
    }

    $rootDoc   = isset($CFG_GLPI['root_doc']) ? rtrim((string) $CFG_GLPI['root_doc'], '/') : '';
    $ticketsId = (int) $request['tickets_id'];
    $ticketUrl = $rootDoc . '/front/ticket.form.php?id=' . $ticketsId;

    $renderPageStart(__('Aprovação registrada', 'approvalflow'), $loginUid);
    echo '<div class="approvalflow-approve-wrap">';
    echo '<div class="approvalflow-card approvalflow-header-card">';
    echo '<h2 class="approvalflow-title">' . $esc(__('Decisão registrada com sucesso', 'approvalflow')) . '</h2>';
    echo '<p class="approvalflow-subtitle">' . $esc(
        $decision === 'approve'
            ? sprintf(__('O chamado #%d foi aprovado e o link de uso único foi invalidado.', 'approvalflow'), $ticketsId)
            : sprintf(__('O chamado #%d foi recusado e o link de uso único foi invalidado.', 'approvalflow'), $ticketsId)
    ) . '</p>';
    if ($loginUid > 0) {
        echo '<div class="mt-3"><a class="btn btn-primary" href="' . $esc($ticketUrl) . '">'
            . $esc(__('Voltar ao chamado', 'approvalflow'))
            . '</a></div>';
    }
    echo '</div></div>';
    $renderPageEnd($loginUid);
    return;
}

$renderPageStart(__('Confirmação de Aprovação', 'approvalflow'), $loginUid);
echo '<div class="approvalflow-approve-wrap">';

if ($tokenMode && $request === null) {
    echo '<div class="approvalflow-card">';
    echo '<h2 class="approvalflow-title">' . $esc(__('Aprovação indisponível', 'approvalflow')) . '</h2>';
    echo '<p class="approvalflow-subtitle">' . $esc($tokenErr) . '</p>';
    echo '</div>';
    echo '</div>';
    $renderPageEnd($loginUid);
    return;
}

$ticketsId  = (int) $request['tickets_id'];
$rule       = PluginApprovalflowConfig::getRule((int) $request['rules_id']);
$ruleName   = $rule !== null ? $rule['name'] : ('#' . (int) $request['rules_id']);
$ticketRow  = null;

if (isset($DB) && $DB->tableExists('glpi_tickets')) {
    $tIt = $DB->request([
        'SELECT' => ['id', 'name', 'content', 'urgency', 'type', 'entities_id'],
        'FROM'   => 'glpi_tickets',
        'WHERE'  => ['id' => $ticketsId],
        'LIMIT'  => 1,
    ]);
    foreach ($tIt as $row) {
        $ticketRow = $row;
        break;
    }
}

$ticketTitle = $ticketRow !== null
    ? PluginApprovalflowCompat::fromCore((string) ($ticketRow['name'] ?? ''))
    : sprintf(__('Chamado #%d', 'approvalflow'), $ticketsId);

$ticketContent = $ticketRow !== null
    ? trim(strip_tags(PluginApprovalflowCompat::fromCore((string) ($ticketRow['content'] ?? ''))))
    : '';
if ($ticketContent !== '') {
    if (function_exists('mb_substr')) {
        $ticketContent = mb_substr($ticketContent, 0, 500, 'UTF-8');
    } else {
        $ticketContent = substr($ticketContent, 0, 500);
    }
}

$urgencyVal = $ticketRow !== null ? (int) ($ticketRow['urgency'] ?? 3) : 3;
$typeVal    = $ticketRow !== null ? (int) ($ticketRow['type'] ?? 1) : 1;
$typeLabel  = ($typeVal === 2) ? __('Requisição', 'approvalflow') : __('Incidente', 'approvalflow');

echo '<div class="approvalflow-card approvalflow-header-card">';
echo '<h2 class="approvalflow-title">' . $esc(__('Confirmação de aprovação de chamado', 'approvalflow')) . '</h2>';
echo '<p class="approvalflow-subtitle">'
    . $esc(__('Revise os dados abaixo e confirme sua decisão. Nenhuma alteração é feita ao apenas abrir esta página.', 'approvalflow'))
    . '</p>';

echo '<div class="approvalflow-summary-list">';
echo '<div class="approvalflow-summary-item"><small>' . $esc(__('Chamado', 'approvalflow')) . '</small>'
    . '<strong>#' . $esc((string) $ticketsId) . ' — ' . $esc($ticketTitle) . '</strong></div>';
echo '<div class="approvalflow-summary-item"><small>' . $esc(__('Regra aplicada', 'approvalflow')) . '</small>'
    . '<strong>' . $esc($ruleName) . '</strong></div>';
echo '<div class="approvalflow-summary-item"><small>' . $esc(__('Tipo / Urgência', 'approvalflow')) . '</small>'
    . '<strong>' . $esc($typeLabel . ' / ' . $urgencyVal) . '</strong></div>';
echo '</div>';

if ($ticketContent !== '') {
    echo '<div class="mb-3"><small class="text-muted d-block mb-1">' . $esc(__('Resumo da descrição', 'approvalflow')) . '</small>'
        . '<div class="p-2 border rounded bg-light small">' . nl2br($esc($ticketContent)) . '</div></div>';
}

if ($request['status'] !== PluginApprovalflowFlowConstants::STATUS_PENDING) {
    echo '<div class="alert alert-info mb-0">'
        . $esc(sprintf(__('Esta solicitação já foi encerrada com status: %s.', 'approvalflow'), (string) $request['status']))
        . '</div>';
} else {
    echo '<form method="post" action="' . $esc($self) . '">';
    if ($tokenMode) {
        echo '<input type="hidden" name="token" value="' . $esc($rawToken) . '">';
    } else {
        echo '<input type="hidden" name="request_id" value="' . $esc((string) $request['id']) . '">';
    }

    echo '<div class="mb-3">';
    echo '<label class="form-label" for="af_decision_comment">' . $esc(__('Comentário / justificativa (opcional)', 'approvalflow')) . '</label>';
    echo '<textarea id="af_decision_comment" name="comment" rows="3" maxlength="4000" class="form-control"></textarea>';
    echo '</div>';

    echo '<div class="approvalflow-actions">';
    echo '<button type="submit" name="decision" value="approve" class="btn btn-success">'
        . $esc(__('Aprovar chamado', 'approvalflow'))
        . '</button>';
    echo '<button type="submit" name="decision" value="refuse" class="btn btn-danger">'
        . $esc(__('Recusar aprovação', 'approvalflow'))
        . '</button>';
    echo '</div>';

    if (class_exists('Html') && method_exists('Html', 'closeForm')) {
        Html::closeForm();
    } else {
        echo '</form>';
    }
}

echo '</div>';
echo '</div>';

$renderPageEnd($loginUid);
