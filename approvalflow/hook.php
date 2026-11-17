<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Instalação e desinstalação
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

/**
 * Cria as tabelas do plugin na instalação ou atualização.
 */
function plugin_approvalflow_install(): bool
{
    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';

    PluginApprovalflowConfig::installSchema();
    return true;
}

/**
 * Remove as tabelas do plugin na desinstalação para evitar tabelas órfãs.
 */
function plugin_approvalflow_uninstall(): bool
{
    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';

    PluginApprovalflowConfig::uninstallSchema();
    return true;
}
