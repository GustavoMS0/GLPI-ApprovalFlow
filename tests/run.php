<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Suíte de testes unitários standalone
 *
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 * ------------------------------------------------------------------------
 */

require_once __DIR__ . '/bootstrap.php';

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf(
                "Falha em [%s]: esperado %s, obtido %s",
                $label,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }
}

function assertTrue($condition, string $label): void
{
    if ($condition !== true) {
        throw new RuntimeException(sprintf("Falha em [%s]: esperado true", $label));
    }
}

function resetEnvironment(): void
{
    global $DB;
    $DB->reset();
    Session::reset();
    Plugin::$webDirCalled = false;
    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }
    $GLOBALS['TEST_CLI_MODE'] = false;
    $_GET    = [];
    $_POST   = [];
    $_SERVER = [];
    plugin_approvalflow_install();
}

function runIsolatedPhpAssertion(array $env, string $code): void
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmdEnv = [];
    foreach ($_SERVER as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $cmdEnv[$k] = $v;
        }
    }
    foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'COMSPEC'] as $sysKey) {
        $val = getenv($sysKey);
        if ($val !== false) {
            $cmdEnv[$sysKey] = $val;
        }
    }
    foreach ($env as $k => $v) {
        $cmdEnv[$k] = $v;
    }

    $oneLineCode = trim((string) preg_replace('/\s+/', ' ', $code));
    $process     = proc_open([PHP_BINARY, '-r', $oneLineCode], $descriptorSpec, $pipes, dirname(__DIR__), $cmdEnv);
    if (!is_resource($process)) {
        throw new RuntimeException('Falha ao iniciar subprocesso PHP');
    }

    fclose($pipes[0]);
    $stdout   = stream_get_contents($pipes[1]);
    $stderr   = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $combined = trim($stdout . "\n" . $stderr);
        throw new RuntimeException(sprintf("Subprocesso falhou (codigo %d): %s", $exitCode, $combined));
    }
}

function test_recursive_entity_rule_matching_and_deduplication(): void
{
    global $DB;
    resetEnvironment();

    $DB->seed('glpi_entities', [
        ['id' => 1, 'name' => 'Filial Norte', 'entities_id' => 0],
        ['id' => 2, 'name' => 'Unidade Manaus', 'entities_id' => 1],
    ]);

    $ancestors = PluginApprovalflowCompat::getEntitiesAncestors(2);
    assertSame([1, 0], $ancestors, 'ancestrais resolvidos do pai mais proximo ate raiz via array_reverse');

    // Regra recursiva na raiz (entidade 0): Incidente com urgencia >= 4 -> usuario 10
    $rootRuleId = PluginApprovalflowConfig::saveRule(0, [
        'name'                      => 'Aprovacao Diretoria',
        'is_recursive'              => 1,
        'is_active'                 => 1,
        'priority'                  => 10,
        'itilcategories_id'         => 0,
        'ticket_type'               => 1,
        'min_urgency'               => 4,
        'approver_type'             => 'user',
        'approver_id'               => 10,
        'generate_token_link'       => 1,
        'block_solve_while_pending' => 1,
    ]);
    assertTrue($rootRuleId > 0, 'cria regra recursiva na raiz');

    // Regra NAO recursiva na entidade 1 (nao deve afetar entidade 2)
    $parentNonRecId = PluginApprovalflowConfig::saveRule(1, [
        'name'          => 'Local Norte',
        'is_recursive'  => 0,
        'is_active'     => 1,
        'priority'      => 5,
        'approver_type' => 'user',
        'approver_id'   => 11,
    ]);
    assertTrue($parentNonRecId > 0, 'cria regra nao recursiva no pai');

    // Regra especifica na entidade 2 para categoria 77
    $childCatRuleId = PluginApprovalflowConfig::saveRule(2, [
        'name'              => 'Categoria Infra',
        'is_recursive'      => 0,
        'is_active'         => 1,
        'priority'          => 5,
        'itilcategories_id' => 77,
        'ticket_type'       => 0,
        'min_urgency'       => 1,
        'approver_type'     => 'user',
        'approver_id'       => 12,
    ]);
    assertTrue($childCatRuleId > 0, 'cria regra por categoria na filha');

    // Cria chamado na entidade 2: Incidente, urgencia 3, categoria 0 (nao casa nenhuma)
    $ticket = new Ticket();
    $tid    = (int) $ticket->add([
        'name'              => 'Teste inicial',
        'entities_id'       => 2,
        'type'              => Ticket::INCIDENT_TYPE,
        'itilcategories_id' => 0,
        'urgency'           => 3,
        'status'            => 1,
    ]);
    assertTrue($tid > 0, 'chamado criado');
    assertSame(0, count($DB->tables[PluginApprovalflowFlowConstants::REQUESTS_TABLE]), 'nenhuma regra disparada inicialmente');

    // Atualiza urgencia para 4: deve disparar apenas a regra recursiva da raiz (nao a nao-recursiva do pai)
    $ticket->update(['id' => $tid, 'urgency' => 4]);
    $reqs = PluginApprovalflowFlow::getRequestsForTicket($tid);
    assertSame(1, count($reqs), 'dispara regra recursiva ao elevar urgencia');
    assertSame($rootRuleId, (int) $reqs[0]['rules_id'], 'regra correta aplicada');

    // Atualiza nome (campo nao monitorado) e depois categoria 77:
    // regra da raiz NAO pode duplicar (deduplicacao por rules_id + tickets_id), e regra da categoria 77 deve disparar
    $ticket->update(['id' => $tid, 'name' => 'Nome editado']);
    assertSame(1, count(PluginApprovalflowFlow::getRequestsForTicket($tid)), 'nao reavalia em campo nao monitorado');

    $ticket->update(['id' => $tid, 'itilcategories_id' => 77]);
    $reqsAfter = PluginApprovalflowFlow::getRequestsForTicket($tid);
    assertSame(2, count($reqsAfter), 'adiciona segunda regra sem duplicar a primeira');
}

function test_glpi10_vs_glpi11_ticketvalidation_and_reentrancy(): void
{
    // GLPI 10 (users_id_validate + expansao de grupo ativo)
    runIsolatedPhpAssertion(
        [
            'APPROVALFLOW_TEST_GLPI_VERSION'        => '10.0.18',
            'APPROVALFLOW_TEST_TYPED_RIGHTNAME'     => '0',
            'APPROVALFLOW_TEST_HAS_ITEMTYPE_TARGET' => '0',
        ],
        '
        require "tests/bootstrap.php";
        plugin_approvalflow_install();
        global $DB;
        $DB->seed("glpi_users", [
            ["id" => 10, "name" => "alice", "is_active" => 1, "is_deleted" => 0],
            ["id" => 11, "name" => "bob", "is_active" => 1, "is_deleted" => 0],
            ["id" => 12, "name" => "inativo", "is_active" => 0, "is_deleted" => 0],
        ]);
        $DB->seed("glpi_groups", [["id" => 5, "name" => "CAB"]]);
        $DB->seed("glpi_groups_users", [
            ["id" => 1, "groups_id" => 5, "users_id" => 10],
            ["id" => 2, "groups_id" => 5, "users_id" => 11],
            ["id" => 3, "groups_id" => 5, "users_id" => 12],
        ]);

        $rId = PluginApprovalflowConfig::saveRule(0, [
            "name"                      => "Grupo CAB",
            "approver_type"             => "group",
            "approver_id"               => 5,
            "require_all_group_members" => 0,
            "use_native_validation"     => 1,
        ]);

        $t = new Ticket();
        $tid = (int) $t->add(["name" => "Mudanca", "entities_id" => 0, "type" => 2, "urgency" => 3, "status" => 1]);
        $tvs = $DB->tables["glpi_ticketvalidations"];
        if (count($tvs) !== 2) { exit(1); }
        if (isset($tvs[0]["itemtype_target"])) { exit(2); }
        if ((int) $tvs[0]["users_id_validate"] !== 10 || (int) $tvs[1]["users_id_validate"] !== 11) { exit(3); }

        // Aprovacao nativa em TicketValidation deve sincronizar, cancelar irmao e purgar TicketValidation irmao
        $firstTvId  = (int) $tvs[0]["id"];
        $secondTvId = (int) $tvs[1]["id"];
        $tv = new TicketValidation();
        $tv->getFromDB($firstTvId);
        $tv->update(["id" => $firstTvId, "status" => 3, "comment_validation" => "OK nativo"]);

        $reqs = PluginApprovalflowFlow::getRequestsForTicket($tid);
        if (count($reqs) !== 2) { exit(4); }
        $statuses = [$reqs[0]["status"], $reqs[1]["status"]];
        sort($statuses);
        if ($statuses !== ["approved", "cancelled"]) { exit(5); }
        if (PluginApprovalflowFlow::hasBlockingPendingRequests($tid) !== false) { exit(6); }
        if (count($DB->tables["glpi_ticketvalidations"]) !== 1) { exit(7); }
        if ((int) $DB->tables["glpi_ticketvalidations"][0]["id"] !== $firstTvId) { exit(8); }

        // onTicketValidationUpdate nao deve sobrescrever solicitacao ja cancelada
        $cancelledTv = new TicketValidation();
        $cancelledTv->fields = ["id" => $secondTvId, "status" => 4, "comment_validation" => "Tardio"];
        PluginApprovalflowFlow::onTicketValidationUpdate($cancelledTv);
        $reqsAfter = PluginApprovalflowFlow::getRequestsForTicket($tid);
        $statusesAfter = [$reqsAfter[0]["status"], $reqsAfter[1]["status"]];
        sort($statusesAfter);
        if ($statusesAfter !== ["approved", "cancelled"]) { exit(9); }
        exit(0);
        '
    );

    // GLPI 11/12 (itemtype_target = Group quando require_all_group_members = 0; User quando = 1)
    runIsolatedPhpAssertion(
        [
            'APPROVALFLOW_TEST_GLPI_VERSION'        => '11.0.0',
            'APPROVALFLOW_TEST_TYPED_RIGHTNAME'     => '1',
            'APPROVALFLOW_TEST_HAS_ITEMTYPE_TARGET' => '1',
        ],
        '
        require "tests/bootstrap.php";
        plugin_approvalflow_install();
        global $DB;
        $DB->seed("glpi_users", [
            ["id" => 20, "name" => "u20", "is_active" => 1, "is_deleted" => 0],
            ["id" => 21, "name" => "u21", "is_active" => 1, "is_deleted" => 0],
        ]);
        $DB->seed("glpi_groups_users", [
            ["id" => 1, "groups_id" => 9, "users_id" => 20],
            ["id" => 2, "groups_id" => 9, "users_id" => 21],
        ]);

        PluginApprovalflowConfig::saveRule(0, [
            "name"                      => "Grupo Nativo",
            "approver_type"             => "group",
            "approver_id"               => 9,
            "require_all_group_members" => 0,
            "use_native_validation"     => 1,
        ]);

        $t1 = new Ticket();
        $tid1 = (int) $t1->add(["name" => "T1", "entities_id" => 0, "type" => 2, "urgency" => 3, "status" => 1]);
        $tvs1 = $DB->tables["glpi_ticketvalidations"];
        if (count($tvs1) !== 1) { exit(1); }
        if ($tvs1[0]["itemtype_target"] !== "Group" || (int) $tvs1[0]["items_id_target"] !== 9) { exit(2); }

        // Agora com require_all_group_members = 1 -> expande para User
        $DB->reset();
        plugin_approvalflow_install();
        $DB->seed("glpi_users", [
            ["id" => 20, "name" => "u20", "is_active" => 1, "is_deleted" => 0],
            ["id" => 21, "name" => "u21", "is_active" => 1, "is_deleted" => 0],
        ]);
        $DB->seed("glpi_groups_users", [
            ["id" => 1, "groups_id" => 9, "users_id" => 20],
            ["id" => 2, "groups_id" => 9, "users_id" => 21],
        ]);
        PluginApprovalflowConfig::saveRule(0, [
            "name"                      => "Grupo Todos",
            "approver_type"             => "group",
            "approver_id"               => 9,
            "require_all_group_members" => 1,
            "use_native_validation"     => 1,
        ]);
        $t2 = new Ticket();
        $tid2 = (int) $t2->add(["name" => "T2", "entities_id" => 0, "type" => 2, "urgency" => 3, "status" => 1]);
        $tvs2 = $DB->tables["glpi_ticketvalidations"];
        if (count($tvs2) !== 2) { exit(3); }
        if ($tvs2[0]["itemtype_target"] !== "User" || (int) $tvs2[0]["items_id_target"] !== 20) { exit(4); }
        if ($tvs2[1]["itemtype_target"] !== "User" || (int) $tvs2[1]["items_id_target"] !== 21) { exit(5); }
        exit(0);
        '
    );
}

function test_blocks_solve_and_close_while_pending_and_unblocks_after_approval(): void
{
    global $DB;
    resetEnvironment();

    $ruleId = PluginApprovalflowConfig::saveRule(0, [
        'name'                      => 'Bloqueio pendente',
        'approver_type'             => 'user',
        'approver_id'               => 15,
        'block_solve_while_pending' => 1,
    ]);
    assertTrue($ruleId > 0, 'regra bloqueante salva');

    $ticket = new Ticket();
    $tid    = (int) $ticket->add([
        'name'        => 'Chamado bloqueado',
        'entities_id' => 0,
        'type'        => Ticket::INCIDENT_TYPE,
        'urgency'     => 3,
        'status'      => 2,
    ]);
    assertTrue(PluginApprovalflowFlow::hasBlockingPendingRequests($tid), 'chamado possui pendencia bloqueante');

    // Bloqueia ITILSolution
    $sol = new ITILSolution();
    $res = $sol->add(['itemtype' => 'Ticket', 'items_id' => $tid, 'content' => 'Solucao tentativa']);
    assertSame(false, $res, 'ITILSolution bloqueada enquanto pendente');
    assertTrue(count(Session::$messages) > 0 && Session::$messages[0]['type'] === ERROR, 'mensagem de erro ao bloquear solucao');

    // Bloqueia Ticket status 5 e 6
    Session::$messages = [];
    $okSolve = $ticket->update(['id' => $tid, 'status' => Ticket::SOLVED]);
    assertSame(false, $okSolve, 'Ticket::update para SOLVED bloqueado enquanto pendente');

    $okClose = $ticket->update(['id' => $tid, 'status' => Ticket::CLOSED]);
    assertSame(false, $okClose, 'Ticket::update para CLOSED bloqueado enquanto pendente');

    // CLI / Cron deve passar sem bloquear
    Session::$cronMode = true;
    $solCron           = new ITILSolution();
    $resCron           = $solCron->add(['itemtype' => 'Ticket', 'items_id' => $tid, 'content' => 'Solucao cron']);
    assertTrue($resCron !== false && (int) $resCron > 0, 'cron nao e bloqueado');
    Session::$cronMode = false;

    // Aprova a solicitacao e verifica desbloqueio
    $reqs = PluginApprovalflowFlow::getRequestsForTicket($tid);
    assertSame(1, count($reqs), 'uma solicitacao pendente');
    assertTrue(PluginApprovalflowFlow::processDecision((int) $reqs[0]['id'], 'approve', 'Liberado', 15), 'aprova solicitacao');
    assertSame(false, PluginApprovalflowFlow::hasBlockingPendingRequests($tid), 'nao bloqueia mais apos aprovacao');

    $solOk = new ITILSolution();
    $resOk = $solOk->add(['itemtype' => 'Ticket', 'items_id' => $tid, 'content' => 'Solucao final']);
    assertTrue($resOk !== false && (int) $resOk > 0, 'ITILSolution permitida apos aprovacao');

    $okSolveAfter = $ticket->update(['id' => $tid, 'status' => Ticket::SOLVED]);
    assertSame(true, $okSolveAfter, 'Ticket::update para SOLVED permitido apos aprovacao');
}

function test_two_step_approve_endpoint_and_security(): void
{
    global $DB;
    resetEnvironment();

    $DB->seed('glpi_users', [['id' => 30, 'name' => 'aprovador', 'is_active' => 1, 'is_deleted' => 0]]);

    PluginApprovalflowConfig::saveRule(0, [
        'name'                    => 'Aprovacao via link',
        'approver_type'           => 'user',
        'approver_id'             => 30,
        'generate_token_link'     => 1,
        'use_native_validation'   => 1,
        'require_login_for_token' => 0,
    ]);

    $ticket = new Ticket();
    $tid    = (int) $ticket->add([
        'name'        => 'Liberar acesso VPN',
        'content'     => 'Solicito acesso VPN corporativa.',
        'entities_id' => 0,
        'type'        => Ticket::DEMAND_TYPE,
        'urgency'     => 3,
        'status'      => 2,
    ]);

    $flash = PluginApprovalflowFlow::consumeFlashTokens($tid);
    assertSame(1, count($flash), 'flash token gerado uma vez');
    assertSame(0, count(PluginApprovalflowFlow::consumeFlashTokens($tid)), 'flash token consumido na primeira leitura');

    $reqIds   = array_keys($flash);
    $reqId    = (int) $reqIds[0];
    $rawToken = (string) $flash[$reqId];

    $storedRow = PluginApprovalflowFlow::getRequestById($reqId);
    assertTrue($storedRow !== null, 'solicitacao gravada');
    assertSame(hash('sha256', $rawToken), $storedRow['token_hash'], 'apenas SHA-256 armazenado no banco');
    assertSame(false, strpos($storedRow['token_hash'], $rawToken) !== false, 'token bruto nunca armazenado');

    // 1) GET sem autenticacao (link de e-mail) nao altera estado (anti link-scanner)
    Session::$loginUserId = 0;
    $_SERVER              = ['REQUEST_METHOD' => 'GET'];
    $_GET                 = ['token' => $rawToken];
    ob_start();
    include dirname(__DIR__) . '/approvalflow/front/approve.php';
    $getHtml = (string) ob_get_clean();

    assertTrue(strpos($getHtml, 'Liberar acesso VPN') !== false, 'GET exibe resumo do chamado');
    assertTrue(strpos($getHtml, 'name="decision" value="approve"') !== false, 'GET exibe formulario de confirmacao');
    $afterGet = PluginApprovalflowFlow::getRequestById($reqId);
    assertSame('pending', $afterGet['status'], 'GET nao altera status');
    assertSame(hash('sha256', $rawToken), $afterGet['token_hash'], 'GET nao consome token_hash');

    // 2) POST cross-site bloqueado por requireSafePost
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
    $_POST   = ['token' => $rawToken, 'decision' => 'approve', 'comment' => 'Ataque'];
    ob_start();
    include dirname(__DIR__) . '/approvalflow/front/approve.php';
    ob_end_clean();
    assertSame(true, Html::$rightErrorDisplayed, 'POST cross-site negado');
    assertSame('pending', PluginApprovalflowFlow::getRequestById($reqId)['status'], 'status intacto apos POST bloqueado');

    // 3) POST same-origin valido aprova, limpa token_hash e sincroniza TicketValidation
    Html::$rightErrorDisplayed = false;
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
    $_POST   = ['token' => $rawToken, 'decision' => 'approve', 'comment' => 'Aprovado via link'];
    ob_start();
    include dirname(__DIR__) . '/approvalflow/front/approve.php';
    $postHtml = (string) ob_get_clean();

    assertTrue(strpos($postHtml, 'Decisão registrada com sucesso') !== false, 'POST exibe confirmacao');
    $afterPost = PluginApprovalflowFlow::getRequestById($reqId);
    assertSame('approved', $afterPost['status'], 'status atualizado para approved');
    assertSame('', $afterPost['token_hash'], 'token_hash apagado (uso unico)');
    assertSame('Aprovado via link', $afterPost['comment'], 'comentario persistido');

    $tvRow = $DB->tables['glpi_ticketvalidations'][0];
    assertSame(3, (int) $tvRow['status'], 'TicketValidation sincronizado para ACCEPTED (3)');
    assertSame('Aprovado via link', $tvRow['comment_validation'], 'comentario sincronizado em TicketValidation');
    assertTrue(!empty($tvRow['validation_date']), 'validation_date preenchido em TicketValidation');

    // 4) Reuso do mesmo token e bloqueado
    Html::$rightErrorDisplayed = false;
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
    $_POST   = ['token' => $rawToken, 'decision' => 'refuse'];
    ob_start();
    include dirname(__DIR__) . '/approvalflow/front/approve.php';
    ob_end_clean();
    assertSame(true, Html::$rightErrorDisplayed, 'reuso de token ja consumido e negado');
}

function test_tab_config_and_purge(): void
{
    global $DB;
    resetEnvironment();

    $DB->seed('glpi_users', [['id' => 40, 'name' => 'joao', 'is_active' => 1, 'is_deleted' => 0]]);
    PluginApprovalflowConfig::saveRule(0, [
        'name'          => 'Regra <script>alert(1)</script>',
        'approver_type' => 'user',
        'approver_id'   => 40,
    ]);

    $ticket = new Ticket();
    $tid    = (int) $ticket->add(['name' => 'Chamado Aba', 'entities_id' => 0, 'type' => 1, 'urgency' => 3, 'status' => 1]);

    $tab = new PluginApprovalflowTab();
    assertSame('Aprovações (1)', $tab->getTabNameForItem($ticket), 'contador na aba do chamado');

    ob_start();
    $shown = PluginApprovalflowTab::displayTabContentForItem($ticket);
    $html  = (string) ob_get_clean();
    assertSame(true, $shown, 'aba renderizada');
    assertSame(false, strpos($html, '<script>') !== false, 'XSS escapado na aba');
    assertTrue(strpos($html, '&lt;script&gt;') !== false, 'conteudo escapado com htmlspecialchars');

    // Purge do chamado limpa solicitacoes orfas
    $ticket->purge();
    assertSame(0, count($DB->tables[PluginApprovalflowFlowConstants::REQUESTS_TABLE]), 'item_purge remove solicitacoes do chamado');

    // POST invalido em front/config.form.php fail-closed
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
    $_POST   = ['entities_id' => '-3', 'name' => 'Invalida', 'approver_users_id' => '40'];
    include dirname(__DIR__) . '/approvalflow/front/config.form.php';
    assertSame(true, Html::$rightErrorDisplayed, 'entities_id negativo bloqueado em config.form.php');
}

function test_legacy_query_only_db_and_glpi_10_11_12_subprocesses(): void
{
    // 1) GLPI 10.0.5 com LegacyQueryOnlyDB (somente $DB->query, sem $DB->doQuery)
    runIsolatedPhpAssertion(
        [
            'APPROVALFLOW_TEST_GLPI_VERSION'        => '10.0.5',
            'APPROVALFLOW_TEST_TYPED_RIGHTNAME'     => '0',
            'APPROVALFLOW_TEST_LEGACY_QUERY_ONLY'   => '1',
            'APPROVALFLOW_TEST_HAS_ITEMTYPE_TARGET' => '0',
        ],
        '
        require "tests/bootstrap.php";
        global $DB;
        if (method_exists($DB, "doQuery")) { exit(1); }
        plugin_approvalflow_install();
        if (!$DB->tableExists(PluginApprovalflowConfigConstants::RULES_TABLE)) { exit(2); }
        if (!$DB->tableExists(PluginApprovalflowConfigConstants::REQUESTS_TABLE)) { exit(3); }
        plugin_approvalflow_uninstall();
        if ($DB->tableExists(PluginApprovalflowConfigConstants::RULES_TABLE)) { exit(4); }
        if ($DB->tableExists(PluginApprovalflowConfigConstants::REQUESTS_TABLE)) { exit(5); }
        exit(0);
        '
    );

    // 2) GLPI 10.0.18
    runIsolatedPhpAssertion(
        [
            'APPROVALFLOW_TEST_GLPI_VERSION'    => '10.0.18',
            'APPROVALFLOW_TEST_TYPED_RIGHTNAME' => '0',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginApprovalflowCompat::typedRightname() !== false) { exit(1); }
        $ref = new ReflectionProperty("PluginApprovalflowTab", "rightname");
        if ($ref->hasType()) { exit(2); }
        if (PluginApprovalflowTab::$rightname !== "ticket") { exit(3); }
        if (PluginApprovalflowCompat::assetPath("css/approvalflow.css") !== "public/css/approvalflow.css") { exit(4); }
        $bs = chr(92);
        if (PluginApprovalflowCompat::fromCore("&#60;b&#62;C:" . $bs . "ok&#60;/b&#62;") !== "<b>C:" . $bs . "ok</b>") { exit(5); }
        if (PluginApprovalflowCompat::webPath() !== "/plugins/approvalflow") { exit(6); }
        if (Plugin::$webDirCalled !== true) { exit(7); }
        PluginApprovalflowCompat::denyAccess();
        if (Html::$rightErrorDisplayed !== true) { exit(8); }
        exit(0);
        '
    );

    // 3) GLPI 11.0.0
    runIsolatedPhpAssertion(
        [
            'APPROVALFLOW_TEST_GLPI_VERSION'    => '11.0.0',
            'APPROVALFLOW_TEST_TYPED_RIGHTNAME' => '1',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginApprovalflowCompat::typedRightname() !== true) { exit(1); }
        $ref = new ReflectionProperty("PluginApprovalflowTab", "rightname");
        if (!$ref->hasType()) { exit(2); }
        if (PluginApprovalflowCompat::assetPath("css/approvalflow.css") !== "css/approvalflow.css") { exit(3); }
        if (PluginApprovalflowCompat::webPath(false) !== "/plugins/approvalflow") { exit(4); }
        if (PluginApprovalflowCompat::webPath(true) !== "https://glpi.local/plugins/approvalflow") { exit(5); }
        if (Plugin::$webDirCalled !== false) { exit(6); }
        if (empty(\Glpi\Http\Firewall::$strategies) || \Glpi\Http\Firewall::$strategies[0]["plugin"] !== "approvalflow") { exit(7); }
        if (\Glpi\Http\Firewall::$strategies[0]["strategy"] !== \Glpi\Http\Firewall::STRATEGY_NO_CHECK) { exit(8); }
        PluginApprovalflowCompat::denyAccess();
        if (Html::$rightErrorDisplayed !== true) { exit(9); }
        exit(0);
        '
    );

    // 4) GLPI 12.0.0
    runIsolatedPhpAssertion(
        [
            'APPROVALFLOW_TEST_GLPI_VERSION'    => '12.0.0',
            'APPROVALFLOW_TEST_TYPED_RIGHTNAME' => '1',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginApprovalflowCompat::typedRightname() !== true) { exit(1); }
        if (PluginApprovalflowCompat::glpiMajor() !== 12) { exit(2); }
        if (PluginApprovalflowCompat::assetPath("css/approvalflow.css") !== "css/approvalflow.css") { exit(3); }
        if (PluginApprovalflowCompat::webPath(false) !== "/plugins/approvalflow") { exit(4); }
        if (Plugin::$webDirCalled !== false) { exit(5); }
        if (empty(\Glpi\Http\Firewall::$strategies) || \Glpi\Http\Firewall::$strategies[0]["regex"] !== "#^/front/approve\\.php$#") { exit(6); }
        $denied = false;
        try { PluginApprovalflowCompat::denyAccess(); } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) { $denied = true; }
        if (!$denied) { exit(7); }
        exit(0);
        '
    );
}

function test_security_hardening_and_concurrency_guards(): void
{
    global $DB;
    resetEnvironment();

    // 1) Default require_login_for_token = 1
    $def = PluginApprovalflowConfig::defaultRule(0);
    assertSame(1, $def['require_login_for_token'], 'default require_login_for_token e 1');

    $DB->seed('glpi_users', [
        ['id' => 50, 'name' => 'solicitante', 'is_active' => 1, 'is_deleted' => 0],
        ['id' => 51, 'name' => 'leitor', 'is_active' => 1, 'is_deleted' => 0],
        ['id' => 52, 'name' => 'membro_a', 'is_active' => 1, 'is_deleted' => 0],
        ['id' => 53, 'name' => 'membro_b', 'is_active' => 1, 'is_deleted' => 0],
    ]);
    $DB->seed('glpi_groups_users', [
        ['id' => 1, 'groups_id' => 12, 'users_id' => 52],
        ['id' => 2, 'groups_id' => 12, 'users_id' => 53],
    ]);

    // 2) Flash token oculto para solicitante (mesmo com config UPDATE) e para leitor comum (ticket READ)
    $rUserId = PluginApprovalflowConfig::saveRule(0, [
        'name'                => 'Regra Sigilosa',
        'approver_type'       => 'user',
        'approver_id'         => 52,
        'generate_token_link' => 1,
    ]);
    $savedRule = PluginApprovalflowConfig::getRule($rUserId);
    assertSame(1, $savedRule['require_login_for_token'], 'regra salva herda require_login_for_token = 1');

    // Solicitante 50 com config UPDATE criando chamado para si proprio
    Session::$loginUserId = 50;
    Session::$rights      = ['config' => true, 'ticket' => true, 'ticketvalidation' => true];
    $tReq                 = new Ticket();
    $tidReq               = (int) $tReq->add([
        'name'               => 'Chamado do solicitante',
        'entities_id'        => 0,
        'users_id_recipient' => 50,
        'type'               => 1,
        'urgency'            => 3,
        'status'             => 1,
    ]);
    assertSame(0, count(PluginApprovalflowFlow::consumeFlashTokens($tidReq)), 'flash token nao e gravado para o solicitante do chamado');

    // Leitor comum 51 (sem config UPDATE, apenas ticket READ)
    Session::$loginUserId = 51;
    Session::$rights      = ['config' => false, 'ticket' => true, 'ticketvalidation' => true];
    $tRead                = new Ticket();
    $tidRead              = (int) $tRead->add([
        'name'        => 'Chamado leitor',
        'entities_id' => 0,
        'type'        => 1,
        'urgency'     => 3,
        'status'      => 1,
    ]);
    assertSame(0, count(PluginApprovalflowFlow::consumeFlashTokens($tidRead)), 'flash token nao e gravado para leitor comum');

    // Mesmo que flash token tenha sido gravado por admin nao-solicitante (id 2), a aba oculta do solicitante e do leitor
    Session::$loginUserId = 2;
    Session::$rights      = ['config' => true, 'ticket' => true, 'ticketvalidation' => false];
    $tAdm                 = new Ticket();
    $tidAdm               = (int) $tAdm->add([
        'name'               => 'Chamado admin cria',
        'entities_id'        => 0,
        'users_id_recipient' => 50,
        'type'               => 1,
        'urgency'            => 3,
        'status'             => 1,
    ]);
    // Troca sessao para solicitante 50 com config UPDATE antes de renderizar a aba
    Session::$loginUserId = 50;
    ob_start();
    PluginApprovalflowTab::displayTabContentForItem($tAdm);
    $tabHtmlReq = (string) ob_get_clean();
    assertSame(false, strpos($tabHtmlReq, 'front/approve.php?token=') !== false, 'aba oculta link flash para solicitante mesmo com config UPDATE');

    // 3) Isolamento por membro quando require_all_group_members = 1 e remocao de ticketvalidation UPDATE
    Session::$loginUserId = 2;
    Session::$rights      = ['config' => true, 'ticket' => true, 'ticketvalidation' => true];
    PluginApprovalflowConfig::deleteRule($rUserId);
    $rGrpAll = PluginApprovalflowConfig::saveRule(0, [
        'name'                      => 'Grupo Todos Isolado',
        'approver_type'             => 'group',
        'approver_id'               => 12,
        'require_all_group_members' => 1,
        'require_login_for_token'   => 0,
    ]);
    $tGrp    = new Ticket();
    $tidGrp  = (int) $tGrp->add(['name' => 'Grupo Isolado', 'entities_id' => 0, 'type' => 2, 'urgency' => 3, 'status' => 1]);
    $grpReqs = PluginApprovalflowFlow::getRequestsForTicket($tidGrp);
    assertSame(2, count($grpReqs), 'duas solicitacoes individuais para grupo com require_all_group_members = 1');

    $reqFor52 = ((int) $grpReqs[0]['approver_users_id'] === 52) ? $grpReqs[0] : $grpReqs[1];
    Session::$loginUserId = 53;
    Session::$rights      = ['config' => false, 'ticket' => true, 'ticketvalidation' => true];
    assertSame(false, PluginApprovalflowFlow::canUserDecideRequest($reqFor52, 53), 'membro 53 nao pode decidir solicitacao atribuida ao membro 52');
    assertSame(false, PluginApprovalflowFlow::canUserDecideRequest($reqFor52, 51), 'ticketvalidation UPDATE sem ser aprovador nao concede decisao');
    Session::$loginUserId = 52;
    assertSame(true, PluginApprovalflowFlow::canUserDecideRequest($reqFor52, 52), 'membro 52 decide sua propria solicitacao');

    // 4) Guarda de concorrencia affectedRows() === 0 em processDecision()
    $DB->forceZeroAffectedRowsNextUpdate = true;
    $tvBefore                           = $DB->tables['glpi_ticketvalidations'];
    $okRace                             = PluginApprovalflowFlow::processDecision((int) $reqFor52['id'], 'approve', 'Corrida', 52);
    assertSame(false, $okRace, 'processDecision falha quando affectedRows() e 0');
    assertSame($tvBefore, $DB->tables['glpi_ticketvalidations'], 'TicketValidation nao e alterado quando affectedRows() e 0');

    // 5) Regra deletada com solicitacao pendente -> fail-closed em front/approve.php (exige login)
    $flashGrp = PluginApprovalflowFlow::consumeFlashTokens($tidGrp);
    $tok52    = (string) ($flashGrp[(int) $reqFor52['id']] ?? '');
    if ($tok52 === '') {
        // Caso o flash nao tenha sido gravado para id 2 em algum cenario, gera via admin
        throw new RuntimeException('Esperava flash token para admin nao-solicitante');
    }
    PluginApprovalflowConfig::deleteRule($rGrpAll);
    Session::$loginUserId      = 0;
    Html::$rightErrorDisplayed = false;
    $_SERVER                   = ['REQUEST_METHOD' => 'GET'];
    $_GET                      = ['token' => $tok52];
    ob_start();
    include dirname(__DIR__) . '/approvalflow/front/approve.php';
    ob_end_clean();
    assertSame(true, Html::$rightErrorDisplayed, 'regra deletada exige login (fail-closed) em approve.php');
}

$tests = [
    'test_recursive_entity_rule_matching_and_deduplication',
    'test_glpi10_vs_glpi11_ticketvalidation_and_reentrancy',
    'test_blocks_solve_and_close_while_pending_and_unblocks_after_approval',
    'test_two_step_approve_endpoint_and_security',
    'test_tab_config_and_purge',
    'test_legacy_query_only_db_and_glpi_10_11_12_subprocesses',
    'test_security_hardening_and_concurrency_guards',
];

$passed = 0;
foreach ($tests as $testFn) {
    $testFn();
    $passed++;
    echo "[OK] {$testFn}\n";
}

echo sprintf("Todos os %d testes passaram com sucesso.\n", $passed);
