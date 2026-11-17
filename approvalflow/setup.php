<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Manifesto e hooks do plugin para GLPI 10, 11 e 12
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

define('PLUGIN_APPROVALFLOW_VERSION', '1.0.0');
define('PLUGIN_APPROVALFLOW_MIN_GLPI', '10.0.0');
define('PLUGIN_APPROVALFLOW_MAX_GLPI', '12.0.99');
define('PLUGIN_APPROVALFLOW_MIN_PHP', '7.4');

/**
 * Inicializa hooks, classes e recursos estáticos do plugin.
 */
function plugin_init_approvalflow(): void
{
    global $PLUGIN_HOOKS;

    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';
    include_once __DIR__ . '/inc/flow.class.php';
    include_once __DIR__ . '/inc/tab.class.php';

    $PLUGIN_HOOKS['csrf_compliant']['approvalflow'] = true;
    $PLUGIN_HOOKS['config_page']['approvalflow']    = 'front/config.form.php';
    $PLUGIN_HOOKS['add_css']['approvalflow']        = [
        PluginApprovalflowCompat::assetPath('css/approvalflow.css'),
    ];

    if (class_exists('Glpi\\Http\\Firewall') && method_exists('Glpi\\Http\\Firewall', 'addPluginStrategyForLegacyScripts')) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'approvalflow',
            '#^/front/approve\.php$#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }

    if (class_exists('Plugin') && method_exists('Plugin', 'registerClass')) {
        Plugin::registerClass(PluginApprovalflowConfig::class);
        Plugin::registerClass(PluginApprovalflowTab::class, ['addtabon' => ['Ticket']]);
    }

    $PLUGIN_HOOKS['item_add']['approvalflow'] = [
        'Ticket' => [PluginApprovalflowFlow::class, 'onTicketAdd'],
    ];

    $PLUGIN_HOOKS['item_update']['approvalflow'] = [
        'Ticket'           => [PluginApprovalflowFlow::class, 'onTicketUpdate'],
        'TicketValidation' => [PluginApprovalflowFlow::class, 'onTicketValidationUpdate'],
    ];

    $PLUGIN_HOOKS['item_purge']['approvalflow'] = [
        'Ticket'           => [PluginApprovalflowFlow::class, 'purgeTicketRequests'],
        'TicketValidation' => [PluginApprovalflowFlow::class, 'onTicketValidationPurge'],
    ];

    $PLUGIN_HOOKS['pre_item_add']['approvalflow'] = [
        'ITILSolution' => [PluginApprovalflowFlow::class, 'preItemAddSolution'],
    ];

    $PLUGIN_HOOKS['pre_item_update']['approvalflow'] = [
        'Ticket' => [PluginApprovalflowFlow::class, 'preItemUpdateTicket'],
    ];
}

/**
 * Metadados exibidos em Configurar > Plugins.
 *
 * @return array<string, mixed>
 */
function plugin_version_approvalflow(): array
{
    return [
        'name'         => 'Approval Flow',
        'version'      => PLUGIN_APPROVALFLOW_VERSION,
        'author'       => 'G. Martins',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/GustavoMS0/GLPI-ApprovalFlow',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_APPROVALFLOW_MIN_GLPI,
                'max' => PLUGIN_APPROVALFLOW_MAX_GLPI,
            ],
            'php'  => [
                'min' => PLUGIN_APPROVALFLOW_MIN_PHP,
            ],
        ],
    ];
}

/**
 * Verifica pré-requisitos de PHP e GLPI antes da instalação/ativação.
 */
function plugin_approvalflow_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, PLUGIN_APPROVALFLOW_MIN_PHP, '<')) {
        echo sprintf('Este plugin exige PHP %s ou superior.', PLUGIN_APPROVALFLOW_MIN_PHP);
        return false;
    }

    if (defined('GLPI_VERSION')) {
        if (version_compare((string) GLPI_VERSION, PLUGIN_APPROVALFLOW_MIN_GLPI, '<')) {
            echo sprintf('Este plugin exige GLPI %s ou superior.', PLUGIN_APPROVALFLOW_MIN_GLPI);
            return false;
        }
        if (version_compare((string) GLPI_VERSION, PLUGIN_APPROVALFLOW_MAX_GLPI, '>')) {
            echo sprintf('Este plugin suporta até GLPI %s.', PLUGIN_APPROVALFLOW_MAX_GLPI);
            return false;
        }
    }

    return true;
}

/**
 * Verifica se o plugin está pronto para ativação.
 *
 * @param bool $verbose
 */
function plugin_approvalflow_check_config($verbose = false): bool
{
    if ($verbose) {
        echo function_exists('__') ? __('Instalado / configurado', 'approvalflow') : 'Instalado / configurado';
    }
    return true;
}
