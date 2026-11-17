<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Página administrativa de regras por entidade
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
    include_once __DIR__ . '/../../../inc/includes.php';
}

include_once __DIR__ . '/../inc/compat.class.php';
include_once __DIR__ . '/../inc/config.class.php';
include_once __DIR__ . '/../inc/flow.class.php';

/** @var DBmysql $DB */
global $DB;

Session::checkRight('config', READ);
$canUpdate = Session::haveRight('config', UPDATE);

$self = PluginApprovalflowCompat::webPath(false) . '/front/config.form.php';
$esc  = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$defaultEid = Session::haveAccessToEntity(0) ? 0 : (int) ($_SESSION['glpiactive_entity'] ?? 0);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    Session::checkRight('config', UPDATE);

    if (!PluginApprovalflowCompat::requireSafePost()) {
        PluginApprovalflowCompat::denyAccess();
        return;
    }

    $eid = filter_var(
        $_POST['entities_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if ($eid === false || !Session::haveAccessToEntity((int) $eid)) {
        PluginApprovalflowCompat::denyAccess();
        return;
    }
    $eid = (int) $eid;

    PluginApprovalflowCompat::normalizeInput();
    $action = strtolower(trim((string) ($_POST['action'] ?? 'save_rule')));

    if ($action === 'toggle_rule') {
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        if (PluginApprovalflowConfig::toggleRule($ruleId, $eid)) {
            Session::addMessageAfterRedirect(
                __('Status da regra atualizado com sucesso.', 'approvalflow'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Não foi possível alterar o status da regra.', 'approvalflow'),
                true,
                ERROR
            );
        }
    } elseif ($action === 'delete_rule') {
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        if (PluginApprovalflowConfig::deleteRule($ruleId, $eid)) {
            Session::addMessageAfterRedirect(
                __('Regra de aprovação removida com sucesso.', 'approvalflow'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Não foi possível remover a regra selecionada.', 'approvalflow'),
                true,
                ERROR
            );
        }
    } else {
        $ruleId       = max(0, (int) ($_POST['rule_id'] ?? 0));
        $approverType = strtolower(trim((string) ($_POST['approver_type'] ?? 'user')));
        $payload      = $_POST;

        if ($approverType === 'group') {
            if (!empty($_POST['approver_groups_id'])) {
                $payload['approver_id'] = (int) $_POST['approver_groups_id'];
            }
        } elseif (!empty($_POST['approver_users_id'])) {
            $payload['approver_id'] = (int) $_POST['approver_users_id'];
        }

        // Checkboxes não marcados não vêm em $_POST.
        $checkboxFields = [
            'is_active',
            'is_recursive',
            'use_native_validation',
            'require_all_group_members',
            'generate_token_link',
            'block_solve_while_pending',
            'require_login_for_token',
        ];
        foreach ($checkboxFields as $cb) {
            $payload[$cb] = !empty($_POST[$cb]) ? 1 : 0;
        }

        $savedId = PluginApprovalflowConfig::saveRule($eid, $payload, $ruleId);
        if ($savedId > 0) {
            Session::addMessageAfterRedirect(
                __('Regra de aprovação salva com sucesso.', 'approvalflow'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Informe um aprovador válido (usuário ou grupo) para salvar a regra.', 'approvalflow'),
                true,
                ERROR
            );
        }
    }

    Html::redirect($self . '?entities_id=' . $eid);
    return;
}

if (array_key_exists('entities_id', $_GET)) {
    $parsedGetEid = filter_var(
        $_GET['entities_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    $eid = $parsedGetEid !== false ? (int) $parsedGetEid : -1;
} else {
    $eid = $defaultEid;
}

if ($eid < 0 || !Session::haveAccessToEntity($eid)) {
    PluginApprovalflowCompat::denyAccess();
    return;
}

$editId   = isset($_GET['edit_id']) ? max(0, (int) $_GET['edit_id']) : 0;
$editRule = null;
if ($editId > 0) {
    $candidate = PluginApprovalflowConfig::getRule($editId);
    if ($candidate !== null && (int) $candidate['entities_id'] === $eid) {
        $editRule = $candidate;
    }
}
$formValues = $editRule !== null ? $editRule : PluginApprovalflowConfig::defaultRule($eid);

$ownRules        = PluginApprovalflowConfig::getRulesForEntity($eid);
$applicableRules = PluginApprovalflowConfig::getApplicableRules($eid);
$inheritedRules  = [];
foreach ($applicableRules as $r) {
    if ((int) $r['entities_id'] !== $eid) {
        $inheritedRules[] = $r;
    }
}

$entityName = class_exists('Dropdown')
    ? PluginApprovalflowCompat::fromCore((string) Dropdown::getDropdownName('glpi_entities', $eid))
    : ('#' . $eid);

$resolveLabel = static function (string $table, int $id): string {
    if ($id <= 0) {
        return __('Qualquer', 'approvalflow');
    }
    if (class_exists('Dropdown') && method_exists('Dropdown', 'getDropdownName')) {
        $label = trim(PluginApprovalflowCompat::fromCore((string) Dropdown::getDropdownName($table, $id)));
        if ($label !== '' && $label !== '&nbsp;') {
            return $label;
        }
    }
    return '#' . $id;
};

Html::header(
    __('Approval Flow', 'approvalflow'),
    $self,
    'config',
    'plugins'
);

echo '<div class="approvalflow-container">';

echo '<div class="approvalflow-card approvalflow-header-card">';
echo '<div class="approvalflow-header-top">';
echo '<div>';
echo '<h2 class="approvalflow-title">'
    . '<i class="ti ti-shield-check" aria-hidden="true"></i> '
    . $esc(__('Approval Flow — Regras de Aprovação', 'approvalflow'))
    . '</h2>';
echo '<p class="approvalflow-subtitle">'
    . $esc(sprintf(__('Entidade selecionada: %s (ID %d)', 'approvalflow'), $entityName, $eid))
    . '</p>';
echo '</div>';
echo '<span class="approvalflow-badge approvalflow-badge-neutral">'
    . $esc(sprintf(__('%d regra(s) na entidade', 'approvalflow'), count($ownRules)))
    . '</span>';
echo '</div>';

echo '<form method="get" action="' . $esc($self) . '" class="approvalflow-entity-bar">';
echo '<label for="approvalflow-entity-select" class="fw-bold mb-0">'
    . $esc(__('Entidade:', 'approvalflow'))
    . '</label>';
if (class_exists('Entity') && method_exists('Entity', 'dropdown')) {
    Entity::dropdown([
        'name'      => 'entities_id',
        'value'     => $eid,
        'entity'    => $_SESSION['glpiactiveentities'] ?? [],
        'rand'      => 'approvalflow_eid',
        'on_change' => 'this.form.submit()',
    ]);
} else {
    echo '<input type="number" min="0" id="approvalflow-entity-select" name="entities_id" value="' . $esc((string) $eid) . '" class="form-control form-control-sm" style="max-width:140px">';
}
echo '<button type="submit" class="btn btn-sm btn-outline-primary">'
    . $esc(__('Trocar entidade', 'approvalflow'))
    . '</button>';
echo '</form>';
echo '</div>';

if ($canUpdate) {
    $isEdit = $editRule !== null;
    echo '<div class="approvalflow-card">';
    echo '<h3 class="approvalflow-section-title">'
        . $esc($isEdit ? sprintf(__('Editar regra #%d', 'approvalflow'), (int) $formValues['id']) : __('Nova regra de aprovação', 'approvalflow'))
        . '</h3>';

    echo '<form method="post" action="' . $esc($self) . '">';
    echo '<input type="hidden" name="entities_id" value="' . $esc((string) $eid) . '">';
    echo '<input type="hidden" name="action" value="save_rule">';
    echo '<input type="hidden" name="rule_id" value="' . $esc((string) $formValues['id']) . '">';

    echo '<div class="approvalflow-form-grid">';

    echo '<div>';
    echo '<label class="form-label" for="af_name">' . $esc(__('Nome da regra', 'approvalflow')) . '</label>';
    echo '<input type="text" id="af_name" name="name" class="form-control" maxlength="255" required value="'
        . $esc((string) $formValues['name'])
        . '" placeholder="' . $esc(__('Ex.: Aprovação de mudança crítica', 'approvalflow')) . '">';
    echo '</div>';

    echo '<div>';
    echo '<label class="form-label" for="af_priority">' . $esc(__('Prioridade (menor executa primeiro)', 'approvalflow')) . '</label>';
    echo '<input type="number" id="af_priority" name="priority" min="1" max="9999" class="form-control" value="'
        . $esc((string) $formValues['priority']) . '">';
    echo '</div>';

    echo '<div>';
    echo '<label class="form-label" for="af_category">' . $esc(__('Categoria (0 = qualquer)', 'approvalflow')) . '</label>';
    if (class_exists('ITILCategory') && method_exists('ITILCategory', 'dropdown')) {
        ITILCategory::dropdown([
            'name'   => 'itilcategories_id',
            'value'  => (int) $formValues['itilcategories_id'],
            'entity' => $eid,
            'emptylabel' => __('Qualquer categoria', 'approvalflow'),
        ]);
    } else {
        echo '<input type="number" id="af_category" name="itilcategories_id" min="0" class="form-control" value="'
            . $esc((string) $formValues['itilcategories_id']) . '">';
    }
    echo '</div>';

    echo '<div>';
    echo '<label class="form-label" for="af_ticket_type">' . $esc(__('Tipo de chamado', 'approvalflow')) . '</label>';
    echo '<select id="af_ticket_type" name="ticket_type" class="form-select">';
    $types = [
        0 => __('Qualquer tipo', 'approvalflow'),
        1 => __('Incidente', 'approvalflow'),
        2 => __('Requisição', 'approvalflow'),
    ];
    foreach ($types as $tVal => $tLabel) {
        $sel = ((int) $formValues['ticket_type'] === $tVal) ? ' selected' : '';
        echo '<option value="' . $esc((string) $tVal) . '"' . $sel . '>' . $esc($tLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div>';
    echo '<label class="form-label" for="af_min_urgency">' . $esc(__('Urgência mínima', 'approvalflow')) . '</label>';
    echo '<select id="af_min_urgency" name="min_urgency" class="form-select">';
    $urgencies = [
        1 => __('1 - Muito baixa ou superior', 'approvalflow'),
        2 => __('2 - Baixa ou superior', 'approvalflow'),
        3 => __('3 - Média ou superior', 'approvalflow'),
        4 => __('4 - Alta ou superior', 'approvalflow'),
        5 => __('5 - Muito alta', 'approvalflow'),
    ];
    foreach ($urgencies as $uVal => $uLabel) {
        $sel = ((int) $formValues['min_urgency'] === $uVal) ? ' selected' : '';
        echo '<option value="' . $esc((string) $uVal) . '"' . $sel . '>' . $esc($uLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div>';
    echo '<label class="form-label" for="af_approver_type">' . $esc(__('Tipo de aprovador', 'approvalflow')) . '</label>';
    echo '<select id="af_approver_type" name="approver_type" class="form-select">';
    $selUser  = ($formValues['approver_type'] !== 'group') ? ' selected' : '';
    $selGroup = ($formValues['approver_type'] === 'group') ? ' selected' : '';
    echo '<option value="user"' . $selUser . '>' . $esc(__('Usuário', 'approvalflow')) . '</option>';
    echo '<option value="group"' . $selGroup . '>' . $esc(__('Grupo', 'approvalflow')) . '</option>';
    echo '</select>';
    echo '</div>';

    $curUserId  = ($formValues['approver_type'] === 'user') ? (int) $formValues['approver_id'] : 0;
    $curGroupId = ($formValues['approver_type'] === 'group') ? (int) $formValues['approver_id'] : 0;

    echo '<div>';
    echo '<label class="form-label" for="af_approver_user">' . $esc(__('Usuário aprovador', 'approvalflow')) . '</label>';
    if (class_exists('User') && method_exists('User', 'dropdown')) {
        User::dropdown([
            'name'   => 'approver_users_id',
            'value'  => $curUserId,
            'entity' => $eid,
            'right'  => 'all',
        ]);
    } else {
        echo '<input type="number" id="af_approver_user" name="approver_users_id" min="0" class="form-control" value="'
            . $esc((string) $curUserId) . '">';
    }
    echo '</div>';

    echo '<div>';
    echo '<label class="form-label" for="af_approver_group">' . $esc(__('Grupo aprovador', 'approvalflow')) . '</label>';
    if (class_exists('Group') && method_exists('Group', 'dropdown')) {
        Group::dropdown([
            'name'   => 'approver_groups_id',
            'value'  => $curGroupId,
            'entity' => $eid,
        ]);
    } else {
        echo '<input type="number" id="af_approver_group" name="approver_groups_id" min="0" class="form-control" value="'
            . $esc((string) $curGroupId) . '">';
    }
    echo '</div>';

    echo '<div>';
    echo '<label class="form-label" for="af_token_ttl">' . $esc(__('Validade do link (dias)', 'approvalflow')) . '</label>';
    echo '<input type="number" id="af_token_ttl" name="token_ttl_days" min="1" max="90" class="form-control" value="'
        . $esc((string) $formValues['token_ttl_days']) . '">';
    echo '</div>';

    echo '</div>';

    $checks = [
        'is_active' => [
            __('Regra ativa', 'approvalflow'),
            __('Avalia automaticamente novos chamados e alterações elegíveis.', 'approvalflow'),
        ],
        'is_recursive' => [
            __('Aplicar às entidades filhas', 'approvalflow'),
            __('Herda automaticamente esta regra para subentidades.', 'approvalflow'),
        ],
        'use_native_validation' => [
            __('Criar validação nativa (TicketValidation)', 'approvalflow'),
            __('Registra e sincroniza a validação nativa do GLPI (usuário ou grupo).', 'approvalflow'),
        ],
        'require_all_group_members' => [
            __('Exigir todos os membros do grupo', 'approvalflow'),
            __('Expande todos os membros ativos do grupo; cada membro precisa aprovar.', 'approvalflow'),
        ],
        'generate_token_link' => [
            __('Gerar link seguro de aprovação', 'approvalflow'),
            __('Gera token criptográfico de uso único (armazenado apenas como SHA-256).', 'approvalflow'),
        ],
        'block_solve_while_pending' => [
            __('Bloquear solução/fechamento enquanto pendente', 'approvalflow'),
            __('Impede solucionar ou fechar o chamado até que a aprovação seja concluída.', 'approvalflow'),
        ],
        'require_login_for_token' => [
            __('Exigir sessão autenticada no link de token', 'approvalflow'),
            __('Quando marcado, exige que o aprovador esteja logado no GLPI para confirmar o token.', 'approvalflow'),
        ],
    ];

    echo '<div class="approvalflow-checks-grid">';
    foreach ($checks as $field => $meta) {
        $checked = !empty($formValues[$field]) ? ' checked' : '';
        echo '<label class="approvalflow-check-card">';
        echo '<input type="checkbox" name="' . $esc($field) . '" value="1"' . $checked . '>';
        echo '<span><strong>' . $esc($meta[0]) . '</strong><small>' . $esc($meta[1]) . '</small></span>';
        echo '</label>';
    }
    echo '</div>';

    echo '<div class="approvalflow-actions">';
    echo '<button type="submit" class="btn btn-primary">'
        . $esc($isEdit ? __('Atualizar regra', 'approvalflow') : __('Adicionar regra', 'approvalflow'))
        . '</button>';
    if ($isEdit) {
        echo '<a class="btn btn-outline-secondary" href="' . $esc($self . '?entities_id=' . $eid) . '">'
            . $esc(__('Cancelar edição', 'approvalflow'))
            . '</a>';
    }
    echo '</div>';

    Html::closeForm();
    echo '</div>';
}

echo '<div class="approvalflow-card">';
echo '<h3 class="approvalflow-section-title">'
    . $esc(__('Regras configuradas nesta entidade', 'approvalflow'))
    . '</h3>';

if ($ownRules === []) {
    echo '<div class="alert alert-info mb-0">'
        . $esc(__('Nenhuma regra definida diretamente nesta entidade.', 'approvalflow'))
        . '</div>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover table-striped mb-0">';
    echo '<thead><tr>';
    echo '<th>' . $esc(__('Prioridade', 'approvalflow')) . '</th>';
    echo '<th>' . $esc(__('Nome', 'approvalflow')) . '</th>';
    echo '<th>' . $esc(__('Critérios', 'approvalflow')) . '</th>';
    echo '<th>' . $esc(__('Aprovador', 'approvalflow')) . '</th>';
    echo '<th>' . $esc(__('Opções', 'approvalflow')) . '</th>';
    echo '<th>' . $esc(__('Status', 'approvalflow')) . '</th>';
    if ($canUpdate) {
        echo '<th class="text-end">' . $esc(__('Ações', 'approvalflow')) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($ownRules as $rule) {
        $rId      = (int) $rule['id'];
        $catText  = $resolveLabel('glpi_itilcategories', (int) $rule['itilcategories_id']);
        $typeText = (int) $rule['ticket_type'] === 1
            ? __('Incidente', 'approvalflow')
            : ((int) $rule['ticket_type'] === 2 ? __('Requisição', 'approvalflow') : __('Qualquer', 'approvalflow'));
        $appTable = $rule['approver_type'] === 'group' ? 'glpi_groups' : 'glpi_users';
        $appLabel = ($rule['approver_type'] === 'group' ? __('Grupo', 'approvalflow') : __('Usuário', 'approvalflow'))
            . ': ' . $resolveLabel($appTable, (int) $rule['approver_id']);

        $flags = [];
        if (!empty($rule['is_recursive'])) {
            $flags[] = __('Recursiva', 'approvalflow');
        }
        if (!empty($rule['block_solve_while_pending'])) {
            $flags[] = __('Bloqueia solução', 'approvalflow');
        }
        if (!empty($rule['generate_token_link'])) {
            $flags[] = __('Token', 'approvalflow');
        }
        if ($rule['approver_type'] === 'group' && !empty($rule['require_all_group_members'])) {
            $flags[] = __('Todos do grupo', 'approvalflow');
        }

        echo '<tr>';
        echo '<td>' . $esc((string) $rule['priority']) . '</td>';
        echo '<td><strong>' . $esc((string) $rule['name']) . '</strong></td>';
        echo '<td class="small">'
            . $esc(sprintf(__('Cat: %s | Tipo: %s | Urg >= %d', 'approvalflow'), $catText, $typeText, (int) $rule['min_urgency']))
            . '</td>';
        echo '<td>' . $esc($appLabel) . '</td>';
        echo '<td class="small">' . $esc(implode(', ', $flags)) . '</td>';
        echo '<td>';
        if (!empty($rule['is_active'])) {
            echo '<span class="approvalflow-badge approvalflow-badge-approved">' . $esc(__('Ativa', 'approvalflow')) . '</span>';
        } else {
            echo '<span class="approvalflow-badge approvalflow-badge-cancelled">' . $esc(__('Inativa', 'approvalflow')) . '</span>';
        }
        echo '</td>';

        if ($canUpdate) {
            echo '<td class="text-end"><div class="approvalflow-inline-actions">';
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . $esc($self . '?entities_id=' . $eid . '&edit_id=' . $rId) . '">'
                . $esc(__('Editar', 'approvalflow'))
                . '</a>';

            echo '<form method="post" action="' . $esc($self) . '" style="display:inline">';
            echo '<input type="hidden" name="entities_id" value="' . $esc((string) $eid) . '">';
            echo '<input type="hidden" name="action" value="toggle_rule">';
            echo '<input type="hidden" name="rule_id" value="' . $esc((string) $rId) . '">';
            echo '<button type="submit" class="btn btn-sm btn-outline-primary">'
                . $esc(!empty($rule['is_active']) ? __('Desativar', 'approvalflow') : __('Ativar', 'approvalflow'))
                . '</button>';
            Html::closeForm();

            echo '<form method="post" action="' . $esc($self) . '" style="display:inline">';
            echo '<input type="hidden" name="entities_id" value="' . $esc((string) $eid) . '">';
            echo '<input type="hidden" name="action" value="delete_rule">';
            echo '<input type="hidden" name="rule_id" value="' . $esc((string) $rId) . '">';
            echo '<button type="submit" class="btn btn-sm btn-outline-danger">'
                . $esc(__('Excluir', 'approvalflow'))
                . '</button>';
            Html::closeForm();

            echo '</div></td>';
        }

        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

if (!empty($inheritedRules)) {
    echo '<div class="mt-4">';
    echo '<h4 class="h5 mb-2">' . $esc(__('Regras herdadas de entidades superiores (recursivas)', 'approvalflow')) . '</h4>';
    echo '<ul class="list-group list-group-flush">';
    foreach ($inheritedRules as $ir) {
        $parentName = $resolveLabel('glpi_entities', (int) $ir['entities_id']);
        echo '<li class="list-group-item px-0">'
            . '<strong>' . $esc((string) $ir['name']) . '</strong> '
            . '<span class="text-muted small">(' . $esc(sprintf(__('Entidade: %s | Urg >= %d', 'approvalflow'), $parentName, (int) $ir['min_urgency'])) . ')</span>'
            . '</li>';
    }
    echo '</ul></div>';
}

echo '</div>';
echo '</div>';

Html::footer();
