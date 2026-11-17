<?php

/**
 * ------------------------------------------------------------------------
 * Approval Flow - Bootstrap de testes unitários standalone (sem Composer)
 *
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 * ------------------------------------------------------------------------
 */

namespace Glpi\Toolbox {
    if (!class_exists(Sanitizer::class)) {
        class Sanitizer
        {
            /**
             * @param mixed $value
             * @return mixed
             */
            public static function sanitize($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::sanitize($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                $encoded = str_replace(['&', '<', '>', "'", '"'], ['&#38;', '&#60;', '&#62;', '&#39;', '&#34;'], $value);
                return addslashes($encoded);
            }

            /**
             * @param mixed $value
             * @return mixed
             */
            public static function decodeHtmlSpecialChars($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::decodeHtmlSpecialChars($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                return str_replace(['&#60;', '&#62;', '&#38;', '&#39;', '&#34;'], ['<', '>', '&', "'", '"'], $value);
            }

            /**
             * @param mixed $value
             * @return mixed
             */
            public static function unsanitize($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::unsanitize($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                return self::decodeHtmlSpecialChars(stripslashes($value));
            }
        }
    }
}

namespace Glpi\Exception\Http {
    $envVer = getenv('APPROVALFLOW_TEST_GLPI_VERSION');
    $ver    = ($envVer !== false && $envVer !== '') ? $envVer : (defined('GLPI_VERSION') ? GLPI_VERSION : '11.0.0');
    if (version_compare($ver, '12.0.0-dev', '>=') && !class_exists(AccessDeniedHttpException::class)) {
        class AccessDeniedHttpException extends \RuntimeException
        {
        }
    }
}

namespace Glpi\Http {
    $envVer = getenv('APPROVALFLOW_TEST_GLPI_VERSION');
    $ver    = ($envVer !== false && $envVer !== '') ? $envVer : (defined('GLPI_VERSION') ? GLPI_VERSION : '11.0.0');
    if (version_compare($ver, '11.0.0-dev', '>=') && !class_exists(Firewall::class)) {
        class Firewall
        {
            public const STRATEGY_NO_CHECK = 'no_check';

            /** @var array<int, array{plugin: string, regex: string, strategy: string}> */
            public static $strategies = [];

            public static function addPluginStrategyForLegacyScripts(string $plugin, string $regex, string $strategy): void
            {
                self::$strategies[] = [
                    'plugin'   => $plugin,
                    'regex'    => $regex,
                    'strategy' => $strategy,
                ];
            }
        }
    }
}

namespace {
    if (!defined('GLPI_ROOT')) {
        define('GLPI_ROOT', dirname(__DIR__));
    }

    $envVersion = getenv('APPROVALFLOW_TEST_GLPI_VERSION');
    if (!defined('GLPI_VERSION')) {
        define('GLPI_VERSION', ($envVersion !== false && $envVersion !== '') ? $envVersion : '11.0.0');
    }

    if (!defined('INFO')) {
        define('INFO', 0);
    }
    if (!defined('WARNING')) {
        define('WARNING', 2);
    }
    if (!defined('ERROR')) {
        define('ERROR', 3);
    }
    if (!defined('READ')) {
        define('READ', 1);
    }
    if (!defined('UPDATE')) {
        define('UPDATE', 2);
    }

    $GLOBALS['TEST_CLI_MODE'] = false;
    $GLOBALS['PLUGIN_HOOKS']  = [];
    $GLOBALS['CFG_GLPI']      = [
        'root_doc' => '',
        'url_base' => 'https://glpi.local',
    ];

    if (!function_exists('__')) {
        function __($str, $domain = 'glpi')
        {
            return (string) $str;
        }
    }

    if (!function_exists('isCommandLineInterface')) {
        function isCommandLineInterface()
        {
            return !empty($GLOBALS['TEST_CLI_MODE']);
        }
    }

    if (!function_exists('getAncestorsOf')) {
        function getAncestorsOf($table, $items_id)
        {
            global $DB;

            $items_id = (int) $items_id;
            if ($items_id <= 0 || !isset($DB)) {
                return [];
            }

            $chain   = [];
            $visited = [$items_id => true];
            $current = $items_id;
            $depth   = 0;

            while ($current > 0 && $depth < 64) {
                $depth++;
                $parent = null;
                foreach ($DB->request(['SELECT' => ['entities_id'], 'FROM' => $table, 'WHERE' => ['id' => $current], 'LIMIT' => 1]) as $row) {
                    $parent = (int) ($row['entities_id'] ?? -1);
                    break;
                }
                if ($parent === null || $parent < 0 || isset($visited[$parent])) {
                    break;
                }
                $visited[$parent] = true;
                $chain[]          = $parent;
                $current          = $parent;
            }

            // Simula o núcleo do GLPI (da raiz 0 até o pai imediato).
            return array_reverse($chain);
        }
    }

    class DBmysqlBase
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public $tables = [];

        /** @var array<string, int> */
        private $autoIncrement = [];

        /** @var int */
        private $lastInsertId = 0;

        /** @var int */
        private $lastAffectedRows = 0;

        /** @var bool */
        public $forceZeroAffectedRowsNextUpdate = false;

        /** @var array<int, string> */
        public $ddlLog = [];

        /** @var bool */
        public $hasItemtypeTarget = true;

        public function __construct()
        {
            $envTarget = getenv('APPROVALFLOW_TEST_HAS_ITEMTYPE_TARGET');
            if ($envTarget !== false && $envTarget !== '') {
                $this->hasItemtypeTarget = ($envTarget === '1');
            } else {
                $this->hasItemtypeTarget = version_compare((string) GLPI_VERSION, '11.0.0-dev', '>=');
            }
            $this->reset();
        }

        public function reset(): void
        {
            $this->tables = [
                'glpi_entities' => [
                    ['id' => 0, 'name' => 'Root Entity', 'entities_id' => -1],
                ],
                'glpi_tickets'           => [],
                'glpi_ticketvalidations' => [],
                'glpi_itilsolutions'     => [],
                'glpi_users'             => [],
                'glpi_groups'            => [],
                'glpi_groups_users'      => [],
                'glpi_tickets_users'     => [],
                'glpi_itilcategories'    => [],
            ];
            $this->autoIncrement                   = [];
            $this->lastInsertId                    = 0;
            $this->lastAffectedRows                = 0;
            $this->forceZeroAffectedRowsNextUpdate = false;
            $this->ddlLog                          = [];
        }

        public function tableExists(string $table): bool
        {
            return array_key_exists($table, $this->tables);
        }

        public function fieldExists(string $table, string $field): bool
        {
            if ($table === 'glpi_ticketvalidations') {
                if ($field === 'itemtype_target' || $field === 'items_id_target') {
                    return $this->hasItemtypeTarget;
                }
                if ($field === 'users_id_validate') {
                    return !$this->hasItemtypeTarget;
                }
                return in_array($field, ['id', 'tickets_id', 'users_id', 'status', 'comment_submission', 'comment_validation', 'validation_date'], true);
            }
            return true;
        }

        public function seed(string $table, array $rows): void
        {
            if (!isset($this->tables[$table])) {
                $this->tables[$table] = [];
            }
            $maxId = $this->autoIncrement[$table] ?? 0;
            foreach ($rows as $row) {
                $this->tables[$table][] = $row;
                if (isset($row['id']) && (int) $row['id'] > $maxId) {
                    $maxId = (int) $row['id'];
                }
            }
            $this->autoIncrement[$table] = $maxId;
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public function insertId(): int
        {
            return $this->lastInsertId;
        }

        public function affectedRows(): int
        {
            return $this->lastAffectedRows;
        }

        public function insert(string $table, array $params): bool
        {
            if (!isset($this->tables[$table])) {
                $this->tables[$table] = [];
            }
            $nextId = ($this->autoIncrement[$table] ?? 0) + 1;
            if (!isset($params['id'])) {
                $params['id'] = $nextId;
            }
            $this->autoIncrement[$table] = max($nextId, (int) $params['id']);
            $this->lastInsertId          = (int) $params['id'];

            foreach ($params as $k => $v) {
                if (is_string($v)) {
                    $params[$k] = stripslashes($v);
                }
            }
            $this->tables[$table][] = $params;
            return true;
        }

        public function update(string $table, array $params, array $where): bool
        {
            $this->lastAffectedRows = 0;
            if (!isset($this->tables[$table])) {
                return false;
            }
            if ($this->forceZeroAffectedRowsNextUpdate) {
                $this->forceZeroAffectedRowsNextUpdate = false;
                return true;
            }
            foreach ($params as $k => $v) {
                if (is_string($v)) {
                    $params[$k] = stripslashes($v);
                }
            }
            $affected = 0;
            foreach ($this->tables[$table] as $idx => $row) {
                if ($this->matchesWhere($row, $where)) {
                    $this->tables[$table][$idx] = array_merge($row, $params);
                    $affected++;
                }
            }
            $this->lastAffectedRows = $affected;
            return $affected > 0;
        }

        public function delete(string $table, array $where): bool
        {
            $this->lastAffectedRows = 0;
            if (!isset($this->tables[$table])) {
                return false;
            }
            $kept     = [];
            $affected = 0;
            foreach ($this->tables[$table] as $row) {
                if (!$this->matchesWhere($row, $where)) {
                    $kept[] = $row;
                } else {
                    $affected++;
                }
            }
            $this->tables[$table]   = $kept;
            $this->lastAffectedRows = $affected;
            return true;
        }

        /**
         * @return ArrayIterator<int, array<string, mixed>>
         */
        public function request(array $criteria): ArrayIterator
        {
            $table = (string) ($criteria['FROM'] ?? '');
            $rows  = $this->tables[$table] ?? [];
            $where = $criteria['WHERE'] ?? [];

            $filtered = [];
            foreach ($rows as $row) {
                if ($this->matchesWhere($row, $where)) {
                    $filtered[] = $row;
                }
            }

            if (isset($criteria['ORDER'])) {
                $orders = is_array($criteria['ORDER']) ? $criteria['ORDER'] : [$criteria['ORDER']];
                usort($filtered, static function (array $a, array $b) use ($orders): int {
                    foreach ($orders as $ord) {
                        $parts = preg_split('/\s+/', trim((string) $ord));
                        $col   = $parts[0] ?? 'id';
                        $dir   = strtoupper($parts[1] ?? 'ASC');
                        $va    = $a[$col] ?? null;
                        $vb    = $b[$col] ?? null;
                        if ($va == $vb) {
                            continue;
                        }
                        $cmp = ($va < $vb) ? -1 : 1;
                        return ($dir === 'DESC') ? -$cmp : $cmp;
                    }
                    return 0;
                });
            }

            if (isset($criteria['COUNT'])) {
                $alias = (string) $criteria['COUNT'];
                return new ArrayIterator([[$alias => count($filtered)]]);
            }

            if (isset($criteria['LIMIT'])) {
                $filtered = array_slice($filtered, 0, (int) $criteria['LIMIT']);
            }

            return new ArrayIterator($filtered);
        }

        protected function execSql(string $sql): bool
        {
            $this->ddlLog[] = $sql;
            if (preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                $table = $m[1];
                if (!isset($this->tables[$table])) {
                    $this->tables[$table] = [];
                }
                return true;
            }
            if (preg_match('/DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                unset($this->tables[$m[1]]);
                return true;
            }
            return true;
        }

        private function matchesWhere(array $row, array $where): bool
        {
            foreach ($where as $k => $expected) {
                $val = $row[$k] ?? null;
                if (is_array($expected)) {
                    $matched = false;
                    foreach ($expected as $item) {
                        if ($val == $item) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return false;
                    }
                } else {
                    if ($val != $expected) {
                        return false;
                    }
                }
            }
            return true;
        }
    }

    class DBmysql extends DBmysqlBase
    {
        public function doQuery(string $sql): bool
        {
            return $this->execSql($sql);
        }
    }

    class LegacyQueryOnlyDB extends DBmysqlBase
    {
        public function query(string $sql): bool
        {
            return $this->execSql($sql);
        }
    }

    $typedRightnameEnv = getenv('APPROVALFLOW_TEST_TYPED_RIGHTNAME');
    $typedRightname    = ($typedRightnameEnv !== false && $typedRightnameEnv !== '')
        ? ($typedRightnameEnv === '1')
        : version_compare((string) GLPI_VERSION, '11.0.0-dev', '>=');

    if ($typedRightname) {
        class CommonGLPI
        {
            public static string $rightname = '';

            public static function createTabEntry($text, $nb = 0)
            {
                return $text . ($nb > 0 ? ' (' . (int) $nb . ')' : '');
            }
        }
    } else {
        class CommonGLPI
        {
            public static $rightname = '';

            public static function createTabEntry($text, $nb = 0)
            {
                return $text . ($nb > 0 ? ' (' . (int) $nb . ')' : '');
            }
        }
    }

    class CommonDBTM extends CommonGLPI
    {
        /** @var array<string, mixed> */
        public $fields = [];

        /** @var mixed */
        public $input = [];

        /** @var array<int, string> */
        public $updates = [];

        public static function getTable($classname = null)
        {
            return 'glpi_commondbtms';
        }

        public function getID(): int
        {
            return (int) ($this->fields['id'] ?? 0);
        }

        public function getEntityID(): int
        {
            return (int) ($this->fields['entities_id'] ?? 0);
        }

        public function canViewItem(): bool
        {
            return true;
        }

        public function getFromDB($id): bool
        {
            global $DB;
            $table = static::getTable();
            foreach ($DB->request(['FROM' => $table, 'WHERE' => ['id' => (int) $id], 'LIMIT' => 1]) as $row) {
                $this->fields = $row;
                return true;
            }
            return false;
        }

        /**
         * @return int|false
         */
        public function add(array $input)
        {
            global $DB, $PLUGIN_HOOKS;

            $cls         = static::class;
            $this->input = $input;

            if (!empty($PLUGIN_HOOKS['pre_item_add'])) {
                foreach ($PLUGIN_HOOKS['pre_item_add'] as $plugin => $map) {
                    if (isset($map[$cls]) && is_callable($map[$cls])) {
                        call_user_func($map[$cls], $this);
                    }
                }
            }

            if ($this->input === false) {
                return false;
            }

            $table = static::getTable();
            $DB->insert($table, $this->input);
            $newId              = $DB->insertId();
            $this->fields       = $this->input;
            $this->fields['id'] = $newId;

            $this->postAddSimulate();

            if (!empty($PLUGIN_HOOKS['item_add'])) {
                foreach ($PLUGIN_HOOKS['item_add'] as $plugin => $map) {
                    if (isset($map[$cls]) && is_callable($map[$cls])) {
                        call_user_func($map[$cls], $this);
                    }
                }
            }

            return $newId;
        }

        public function update(array $input): bool
        {
            global $DB, $PLUGIN_HOOKS;

            $cls = static::class;
            $id  = (int) ($input['id'] ?? $this->getID());
            if ($id <= 0) {
                return false;
            }
            if (empty($this->fields)) {
                $this->getFromDB($id);
            }

            $this->input = $input;

            if (!empty($PLUGIN_HOOKS['pre_item_update'])) {
                foreach ($PLUGIN_HOOKS['pre_item_update'] as $plugin => $map) {
                    if (isset($map[$cls]) && is_callable($map[$cls])) {
                        call_user_func($map[$cls], $this);
                    }
                }
            }

            if ($this->input === false) {
                return false;
            }

            $changed = [];
            foreach ($this->input as $k => $v) {
                if ($k === 'id') {
                    continue;
                }
                if (!array_key_exists($k, $this->fields) || $this->fields[$k] != $v) {
                    $changed[] = $k;
                }
            }
            $this->updates = $changed;

            $table = static::getTable();
            $DB->update($table, $this->input, ['id' => $id]);
            $this->fields = array_merge($this->fields, $this->input);

            if (!empty($PLUGIN_HOOKS['item_update'])) {
                foreach ($PLUGIN_HOOKS['item_update'] as $plugin => $map) {
                    if (isset($map[$cls]) && is_callable($map[$cls])) {
                        call_user_func($map[$cls], $this);
                    }
                }
            }

            return true;
        }

        public function delete(array $input, $force = 0, $history = 1): bool
        {
            $id = (int) ($input['id'] ?? $this->getID());
            if ($id <= 0) {
                return false;
            }
            if (empty($this->fields)) {
                $this->getFromDB($id);
            }
            $this->fields['id'] = $id;
            return $this->purge();
        }

        public function purge(): bool
        {
            global $DB, $PLUGIN_HOOKS;

            $cls = static::class;
            $id  = $this->getID();
            if ($id <= 0) {
                return false;
            }
            $DB->delete(static::getTable(), ['id' => $id]);

            if (!empty($PLUGIN_HOOKS['item_purge'])) {
                foreach ($PLUGIN_HOOKS['item_purge'] as $plugin => $map) {
                    if (isset($map[$cls]) && is_callable($map[$cls])) {
                        call_user_func($map[$cls], $this);
                    }
                }
            }

            return true;
        }

        protected function postAddSimulate(): void
        {
        }
    }

    class Ticket extends CommonDBTM
    {
        public const INCIDENT_TYPE = 1;
        public const DEMAND_TYPE   = 2;
        public const SOLVED        = 5;
        public const CLOSED        = 6;

        public static function getTable($classname = null)
        {
            return 'glpi_tickets';
        }
    }

    class ITILSolution extends CommonDBTM
    {
        public static function getTable($classname = null)
        {
            return 'glpi_itilsolutions';
        }
    }

    class TicketValidation extends CommonDBTM
    {
        public static function getTable($classname = null)
        {
            return 'glpi_ticketvalidations';
        }

        protected function postAddSimulate(): void
        {
            // Simula o núcleo do GLPI atualizando global_validation no chamado pai (teste de anti-reentrância).
            $ticketsId = (int) ($this->fields['tickets_id'] ?? 0);
            if ($ticketsId > 0) {
                $t = new Ticket();
                if ($t->getFromDB($ticketsId)) {
                    $t->update([
                        'id'                => $ticketsId,
                        'global_validation' => 2,
                    ]);
                }
            }
        }
    }

    class Session
    {
        /** @var int */
        public static $loginUserId = 2;

        /** @var bool */
        public static $cronMode = false;

        /** @var array<int, array{msg: string, check_once: bool, type: int}> */
        public static $messages = [];

        /** @var array<string, bool> */
        public static $rights = ['config' => true, 'ticket' => true, 'ticketvalidation' => true];

        /** @var array<int, bool>|null */
        public static $accessibleEntities = null;

        public static function reset(): void
        {
            self::$loginUserId        = 2;
            self::$cronMode           = false;
            self::$messages           = [];
            self::$rights             = ['config' => true, 'ticket' => true, 'ticketvalidation' => true];
            self::$accessibleEntities = null;
            $_SESSION['glpiactiveprofile'] = ['id' => 3, 'interface' => 'central'];
            $_SESSION['glpiactive_entity'] = 0;
            unset($_SESSION['glpicronuserrunning'], $_SESSION['plugin_approvalflow_flash_tokens']);
        }

        public static function isCron(): bool
        {
            return self::$cronMode;
        }

        public static function getLoginUserID($force_human = true)
        {
            return self::$loginUserId > 0 ? self::$loginUserId : false;
        }

        public static function addMessageAfterRedirect($msg, $check_once = false, $message_type = INFO, $reset = false): void
        {
            if ($reset) {
                self::$messages = [];
            }
            self::$messages[] = [
                'msg'        => (string) $msg,
                'check_once' => (bool) $check_once,
                'type'       => (int) $message_type,
            ];
        }

        public static function haveRight($module, $right): bool
        {
            return !empty(self::$rights[$module]);
        }

        public static function checkRight($module, $right): void
        {
            if (!self::haveRight($module, $right)) {
                throw new RuntimeException('Right denied: ' . $module);
            }
        }

        public static function haveAccessToEntity($entities_id, $is_recursive = 0): bool
        {
            if (self::$accessibleEntities === null) {
                return true;
            }
            return !empty(self::$accessibleEntities[(int) $entities_id]);
        }
    }

    class Plugin
    {
        /** @var array<int, string> */
        public static $registered = [];

        /** @var bool */
        public static $webDirCalled = false;

        public static function registerClass($class, $params = []): void
        {
            self::$registered[] = (string) $class;
        }

        public static function getWebDir($plugin, $full = false): string
        {
            global $CFG_GLPI;
            self::$webDirCalled = true;
            $base = $full
                ? rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
                : rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
            return $base . '/plugins/' . $plugin;
        }
    }

    if (version_compare((string) GLPI_VERSION, '12.0.0-dev', '<')) {
        class Html
        {
            /** @var bool */
            public static $rightErrorDisplayed = false;

            public static function closeForm($display = true): string
            {
                $out = '<input type="hidden" name="_glpi_csrf_token" value="test"></form>';
                if ($display) {
                    echo $out;
                }
                return $out;
            }

            public static function header($title = '', $url = '', $sector = '', $item = ''): void
            {
            }

            public static function footer(): void
            {
            }

            public static function redirect($location): void
            {
            }

            public static function convDateTime($date): string
            {
                return (string) $date;
            }

            public static function displayRightError(): void
            {
                self::$rightErrorDisplayed = true;
            }
        }
    } else {
        class Html
        {
            public static function closeForm($display = true): string
            {
                $out = '<input type="hidden" name="_glpi_csrf_token" value="test"></form>';
                if ($display) {
                    echo $out;
                }
                return $out;
            }

            public static function header($title = '', $url = '', $sector = '', $item = ''): void
            {
            }

            public static function footer(): void
            {
            }

            public static function redirect($location): void
            {
            }

            public static function convDateTime($date): string
            {
                return (string) $date;
            }
        }
    }

    class Dropdown
    {
        public static function getDropdownName($table, $id): string
        {
            global $DB;
            if (isset($DB) && $DB->tableExists((string) $table)) {
                foreach ($DB->request(['FROM' => (string) $table, 'WHERE' => ['id' => (int) $id], 'LIMIT' => 1]) as $row) {
                    return (string) ($row['name'] ?? ('#' . (int) $id));
                }
            }
            return '#' . (int) $id;
        }
    }

    class Entity extends CommonDBTM
    {
        public static function dropdown($options = []): void
        {
            echo '<select name="entities_id"></select>';
        }
    }

    class Toolbox
    {
        /**
         * @param mixed $value
         * @return mixed
         */
        public static function addslashes_deep($value)
        {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = self::addslashes_deep($v);
                }
                return $value;
            }
            return is_string($value) ? addslashes($value) : $value;
        }
    }

    $legacyOnlyEnv = getenv('APPROVALFLOW_TEST_LEGACY_QUERY_ONLY');
    $GLOBALS['DB'] = ($legacyOnlyEnv === '1') ? new LegacyQueryOnlyDB() : new DBmysql();
    Session::reset();

    require_once GLPI_ROOT . '/approvalflow/setup.php';
    require_once GLPI_ROOT . '/approvalflow/hook.php';
    plugin_init_approvalflow();
}
