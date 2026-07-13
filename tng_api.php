<?php
/**
 * TNG API Script v1.1
 * 
 * A lightweight JSON API for The Next Generation (TNG) genealogy software.
 * Drop this file into your TNG installation root directory (same folder as config.php).
 * 
 * Usage: https://yoursite.com/path/tng_api.php?action=ping
 * 
 * Endpoints:
 *   ?action=ping              - Check if API is installed and reachable
 *   ?action=login (POST)      - Authenticate and get a session token
 *   ?action=search            - Search for people by name
 *   ?action=person            - Get full person detail
 *   ?action=family            - Get family detail
 *   ?action=trees             - List available trees
 *   ?action=places            - Get geocoded places
 */

// Suppress all errors/warnings from contaminating JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering BEFORE loading TNG config, because TNG's
// config.php and its includes may produce output (HTML, whitespace, BOM, etc.)
ob_start();

// ── Load TNG Configuration ──────────────────────────────────────────────────
$tng_dir = dirname(__FILE__);
$config_file = $tng_dir . '/config.php';

if (!file_exists($config_file)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['success' => false, 'error' => 'TNG config.php not found. Ensure this script is in the TNG root directory.']);
    exit;
}

// TNG's config.php sets variables like $database_host, $database_name, etc.
// It may also include other files that produce output — ob_start() catches that.
require_once($config_file);

// Also load customconfig if it exists (for table prefix overrides)
$custom_config = $tng_dir . '/customconfig.php';
if (file_exists($custom_config)) {
    require_once($custom_config);
}

// Discard any output produced by TNG config includes
ob_end_clean();

// NOW set our headers (after all TNG includes are done)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-Token, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Database Connection ─────────────────────────────────────────────────────
// TNG config.php may use different variable names depending on version.
// Try common variations.
$db_host = '';
$db_name = '';
$db_user = '';
$db_pass = '';

if (isset($database_host))     $db_host = $database_host;
elseif (isset($dbhost))        $db_host = $dbhost;
elseif (isset($mysql_host))    $db_host = $mysql_host;

if (isset($database_name))     $db_name = $database_name;
elseif (isset($dbname))        $db_name = $dbname;
elseif (isset($mysql_database)) $db_name = $mysql_database;

if (isset($database_username)) $db_user = $database_username;
elseif (isset($dbuser))        $db_user = $dbuser;
elseif (isset($mysql_user))    $db_user = $mysql_user;

if (isset($database_password)) $db_pass = $database_password;
elseif (isset($dbpassword))    $db_pass = $dbpassword;
elseif (isset($mysql_password)) $db_pass = $mysql_password;

if (empty($db_host) || empty($db_name) || empty($db_user)) {
    json_error('Could not find database credentials in TNG config.php. Check that config.php is valid.', 500);
}

try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    json_error('Database connection failed: ' . $e->getMessage(), 500);
}

// ── Table Names (respect TNG's table prefix) ────────────────────────────────
// TNG stores table names in variables like $people_table, $families_table, etc.
$tbl_people     = isset($people_table)     ? $people_table     : (isset($table_prefix) ? $table_prefix . 'people' : 'tng_people');
$tbl_families   = isset($families_table)   ? $families_table   : (isset($table_prefix) ? $table_prefix . 'families' : 'tng_families');
$tbl_children   = isset($children_table)   ? $children_table   : (isset($table_prefix) ? $table_prefix . 'children' : 'tng_children');
$tbl_events     = isset($events_table)     ? $events_table     : (isset($table_prefix) ? $table_prefix . 'events' : 'tng_events');
$tbl_eventtypes = isset($eventtypes_table) ? $eventtypes_table : (isset($table_prefix) ? $table_prefix . 'eventtypes' : 'tng_eventtypes');
$tbl_places     = isset($places_table)     ? $places_table     : (isset($table_prefix) ? $table_prefix . 'places' : 'tng_places');
$tbl_media      = isset($media_table)      ? $media_table      : (isset($table_prefix) ? $table_prefix . 'media' : 'tng_media');
$tbl_medialinks = isset($medialinks_table) ? $medialinks_table : (isset($table_prefix) ? $table_prefix . 'medialinks' : 'tng_medialinks');
$tbl_sources    = isset($sources_table)    ? $sources_table    : (isset($table_prefix) ? $table_prefix . 'sources' : 'tng_sources');
$tbl_users      = isset($users_table)      ? $users_table      : (isset($table_prefix) ? $table_prefix . 'users' : 'tng_users');
$tbl_trees      = isset($trees_table)      ? $trees_table      : (isset($table_prefix) ? $table_prefix . 'trees' : 'tng_trees');

// API sessions table (auto-created)
$pfx = isset($table_prefix) ? $table_prefix : 'tng_';
$tbl_api_sessions = $pfx . 'api_sessions';

// ── Ensure API Sessions Table Exists ────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tbl_api_sessions}` (
        token VARCHAR(128) NOT NULL PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        realname VARCHAR(200) DEFAULT '',
        role VARCHAR(50) DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Non-fatal — table might already exist or engine not supported, try InnoDB
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tbl_api_sessions}` (
            token VARCHAR(128) NOT NULL PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            realname VARCHAR(200) DEFAULT '',
            role VARCHAR(50) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $e2) {
        // Ignore — login will fail gracefully
    }
}

// ── Route Request ───────────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'ping':
        handle_ping();
        break;
    case 'login':
        handle_login();
        break;
    case 'search':
        handle_search();
        break;
    case 'person':
        handle_person();
        break;
    case 'family':
        handle_family();
        break;
    case 'trees':
        handle_trees();
        break;
    case 'places':
        handle_places();
        break;
    case 'debug':
        handle_debug();
        break;
    default:
        json_error('Unknown action. Valid actions: ping, login, search, person, family, trees, places, debug', 400);
}

// ══════════════════════════════════════════════════════════════════════════════
// ── Handler Functions ───────────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════════════════════

function handle_debug() {
    global $pdo, $tbl_people, $tbl_users, $tbl_families, $tbl_children;
    
    $info = ['api_version' => '1.1'];
    
    // Check table existence and row counts
    $tables = [
        'people' => $tbl_people,
        'users' => $tbl_users,
        'families' => $tbl_families,
        'children' => $tbl_children,
    ];
    
    foreach ($tables as $label => $tbl) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$tbl}`");
            $info['tables'][$label] = ['table' => $tbl, 'rows' => (int)$stmt->fetchColumn()];
        } catch (PDOException $e) {
            $info['tables'][$label] = ['table' => $tbl, 'error' => $e->getMessage()];
        }
    }
    
    // Show people table columns
    try {
        $col_stmt = $pdo->query("SHOW COLUMNS FROM `{$tbl_people}`");
        $info['people_columns'] = [];
        while ($col = $col_stmt->fetch()) {
            $info['people_columns'][] = $col['Field'];
        }
    } catch (PDOException $e) {
        $info['people_columns_error'] = $e->getMessage();
    }
    
    // Show users table columns
    try {
        $col_stmt = $pdo->query("SHOW COLUMNS FROM `{$tbl_users}`");
        $info['users_columns'] = [];
        while ($col = $col_stmt->fetch()) {
            $info['users_columns'][] = $col['Field'];
        }
    } catch (PDOException $e) {
        $info['users_columns_error'] = $e->getMessage();
    }
    
    // Show detected column mappings
    $info['detected_people_cols'] = get_people_columns();
    
    // Sample one person (first record) to verify data structure
    try {
        $cols = get_people_columns();
        $stmt = $pdo->query("SELECT * FROM `{$tbl_people}` LIMIT 1");
        $row = $stmt->fetch();
        if ($row) {
            $info['sample_person'] = [
                'personID'  => $row[$cols['personID']] ?? '(missing)',
                'tree'      => $row[$cols['gedcom']] ?? '(missing)',
                'firstName' => $row[$cols['firstname']] ?? '(missing)',
                'lastName'  => $row[$cols['lastname']] ?? '(missing)',
                'sex'       => $row[$cols['sex']] ?? '(missing)',
            ];
        }
    } catch (PDOException $e) {
        $info['sample_error'] = $e->getMessage();
    }
    
    // Check auth status
    require_auth_optional();
    $info['authenticated'] = is_authenticated();
    if (is_authenticated()) {
        $info['authenticated_user'] = $GLOBALS['authenticated_user'];
    }
    
    // Person fetch test — if ?test_person= and ?test_tree= are provided
    $test_pid = isset($_GET['test_person']) ? trim($_GET['test_person']) : '';
    $test_tree = isset($_GET['test_tree']) ? trim($_GET['test_tree']) : '';
    if (!empty($test_pid) && !empty($test_tree)) {
        try {
            $cols = get_people_columns();
            $stmt = $pdo->prepare("SELECT * FROM `{$tbl_people}` WHERE `{$cols['personID']}` = ? AND `{$cols['gedcom']}` = ?");
            $stmt->execute([$test_pid, $test_tree]);
            $tp = $stmt->fetch();
            if ($tp) {
                $info['test_person'] = [
                    'found' => true,
                    'personID' => $tp[$cols['personID']] ?? '(null)',
                    'tree' => $tp[$cols['gedcom']] ?? '(null)',
                    'firstName' => $tp[$cols['firstname']] ?? '(null)',
                    'lastName' => $tp[$cols['lastname']] ?? '(null)',
                    'sex' => $tp[$cols['sex']] ?? '(null)',
                    'living' => $tp[$cols['living']] ?? '(null)',
                    'birthdate' => $tp[$cols['birthdate']] ?? '(null)',
                    'birthplace' => $tp[$cols['birthplace']] ?? '(null)',
                    'raw_column_count' => count($tp) / 2, // PDO returns both indexed and named
                ];
            } else {
                $info['test_person'] = ['found' => false, 'id' => $test_pid, 'tree' => $test_tree];
            }
        } catch (PDOException $e) {
            $info['test_person'] = ['error' => $e->getMessage()];
        }
    }
    
    // Login diagnostics — if ?test_user= is provided, show password info (no actual passwords)
    $test_user = isset($_GET['test_user']) ? trim($_GET['test_user']) : '';
    if (!empty($test_user)) {
        try {
            $stmt = $pdo->prepare("SELECT username, password, password_type, disabled FROM `{$tbl_users}` WHERE username = ?");
            $stmt->execute([$test_user]);
            $u = $stmt->fetch();
            if ($u) {
                $stored = $u['password'];
                $info['login_diag'] = [
                    'username' => $u['username'],
                    'password_type' => $u['password_type'] ?? '(not set)',
                    'password_length' => strlen($stored),
                    'password_prefix' => substr($stored, 0, 7),
                    'disabled' => $u['disabled'] ?? '(no column)',
                    'looks_like_md5' => (strlen($stored) === 32 && ctype_xdigit($stored)),
                    'looks_like_sha1' => (strlen($stored) === 40 && ctype_xdigit($stored)),
                    'looks_like_sha256' => (strlen($stored) === 64 && ctype_xdigit($stored)),
                    'looks_like_bcrypt' => (substr($stored, 0, 4) === '$2y$' || substr($stored, 0, 4) === '$2a$'),
                    'looks_like_plaintext' => (strlen($stored) < 32 && !ctype_xdigit($stored)),
                ];
            } else {
                $info['login_diag'] = ['error' => 'User not found: ' . $test_user];
            }
        } catch (PDOException $e) {
            $info['login_diag'] = ['error' => $e->getMessage()];
        }
    }
    
    json_response($info);
}

function handle_ping() {
    global $tng_dir;
    
    // Try to detect TNG version from multiple possible locations
    $tng_version = 'unknown';
    
    // Try version.php
    $version_file = $tng_dir . '/version.php';
    if (file_exists($version_file)) {
        $version_content = file_get_contents($version_file);
        if (preg_match('/\$tng_version\s*=\s*["\']([^"\']+)/', $version_content, $matches)) {
            $tng_version = $matches[1];
        }
    }
    
    // Try genlib.php as fallback
    if ($tng_version === 'unknown') {
        $genlib_file = $tng_dir . '/genlib.php';
        if (file_exists($genlib_file)) {
            $genlib_content = file_get_contents($genlib_file);
            if (preg_match('/\$tng_version\s*=\s*["\']([^"\']+)/', $genlib_content, $matches)) {
                $tng_version = $matches[1];
            }
        }
    }
    
    json_response([
        'success' => true,
        'version' => '1.1',
        'tngVersion' => $tng_version,
    ]);
}

function handle_login() {
    global $pdo, $tbl_users, $tbl_api_sessions;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Login requires POST method', 405);
    }
    
    $username = get_post('username');
    $password = get_post('password');
    
    if (empty($username) || empty($password)) {
        json_error('Username and password are required', 400);
    }
    
    // Detect available columns in users table to handle different TNG versions
    $available_cols = [];
    try {
        $col_stmt = $pdo->query("SHOW COLUMNS FROM `{$tbl_users}`");
        while ($col = $col_stmt->fetch()) {
            $available_cols[] = strtolower($col['Field']);
        }
    } catch (PDOException $e) {
        json_error('Cannot read users table structure: ' . $e->getMessage(), 500);
    }
    
    // Determine the password column name
    $pass_col = 'password';
    if (!in_array('password', $available_cols)) {
        if (in_array('pass_word', $available_cols)) $pass_col = 'pass_word';
        elseif (in_array('pswd', $available_cols)) $pass_col = 'pswd';
    }
    
    // Determine the username column name
    $user_col = 'username';
    if (!in_array('username', $available_cols)) {
        if (in_array('user_name', $available_cols)) $user_col = 'user_name';
    }
    
    // Determine display name column
    $name_col = null;
    foreach (['realname', 'real_name', 'description', 'name', 'fullname'] as $candidate) {
        if (in_array($candidate, $available_cols)) {
            $name_col = $candidate;
            break;
        }
    }
    
    // Determine role column
    $role_col = null;
    foreach (['role', 'allow_edit', 'admin', 'access'] as $candidate) {
        if (in_array($candidate, $available_cols)) {
            $role_col = $candidate;
            break;
        }
    }
    
    // Check for password_type and disabled columns
    $has_pass_type = in_array('password_type', $available_cols);
    $has_disabled = in_array('disabled', $available_cols);
    
    // Build select columns
    $select_cols = "`{$user_col}` as username";
    if ($name_col) $select_cols .= ", `{$name_col}` as realname";
    if ($role_col) $select_cols .= ", `{$role_col}` as role";
    $select_cols .= ", `{$pass_col}` as stored_pass";
    if ($has_pass_type) $select_cols .= ", `password_type`";
    if ($has_disabled) $select_cols .= ", `disabled`";
    
    // Fetch the user by username to get the stored password hash and type
    $stmt = $pdo->prepare("SELECT {$select_cols} FROM `{$tbl_users}` WHERE `{$user_col}` = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user) {
        json_error('Invalid username or password', 401);
    }
    
    // Check if account is disabled
    if ($has_disabled && !empty($user['disabled'])) {
        json_error('This account is disabled', 403);
    }
    
    $stored = $user['stored_pass'];
    $pass_type = $has_pass_type ? strtolower(trim($user['password_type'] ?? '')) : '';
    $matched = false;
    
    // Use password_type to determine the correct verification method
    if ($pass_type === 'blowfish' || $pass_type === 'bcrypt' || 
        (substr($stored, 0, 4) === '$2y$' || substr($stored, 0, 4) === '$2a$')) {
        // Bcrypt/Blowfish — use password_verify
        if (function_exists('password_verify')) {
            $matched = password_verify($password, $stored);
        }
    } elseif ($pass_type === 'sha256') {
        $matched = ($stored === hash('sha256', $password));
    } elseif ($pass_type === 'sha512') {
        $matched = ($stored === hash('sha512', $password));
    } else {
        // Try common schemes: MD5 (most common in TNG), SHA1, double MD5, plain
        if ($stored === md5($password))                    $matched = true;
        elseif ($stored === sha1($password))               $matched = true;
        elseif ($stored === md5(md5($password)))            $matched = true;
        elseif ($stored === $password)                      $matched = true;
        elseif (function_exists('password_verify') && password_verify($password, $stored)) $matched = true;
    }
    
    if (!$matched) {
        json_error('Invalid username or password', 401);
    }
    
    // Generate session token
    $token = bin2hex(random_bytes(32));
    
    // Store session
    try {
        $stmt = $pdo->prepare("INSERT INTO `{$tbl_api_sessions}` (token, username, realname, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$token, $user['username'], $user['realname'] ?? '', $user['role'] ?? '']);
    } catch (PDOException $e) {
        // Session table might not exist — still return success with token
    }
    
    // Clean up expired sessions (older than 30 days)
    try {
        $pdo->exec("DELETE FROM `{$tbl_api_sessions}` WHERE last_used < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (PDOException $e) {
        // Ignore
    }
    
    json_response([
        'success' => true,
        'token' => $token,
        'username' => $user['username'],
        'realname' => $user['realname'] ?? '',
        'role' => $user['role'] ?? '',
    ]);
}

function handle_search() {
    global $pdo, $tbl_people;
    
    // Wrap in error catching
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    
    try {
        _handle_search_inner();
    } catch (Exception $e) {
        json_error('Search endpoint error: ' . $e->getMessage(), 500);
    } catch (Error $e) {
        json_error('Search endpoint fatal: ' . $e->getMessage(), 500);
    } finally {
        restore_error_handler();
    }
}

function _handle_search_inner() {
    global $pdo, $tbl_people;
    
    require_auth_optional();
    
    $firstname = isset($_GET['firstname']) ? trim($_GET['firstname']) : '';
    $lastname  = isset($_GET['lastname'])  ? trim($_GET['lastname'])  : '';
    $tree      = isset($_GET['tree'])      ? trim($_GET['tree'])      : '';
    $page      = isset($_GET['page'])      ? max(1, intval($_GET['page'])) : 1;
    $limit     = isset($_GET['limit'])     ? min(100, max(1, intval($_GET['limit']))) : 50;
    $offset    = ($page - 1) * $limit;
    
    if (empty($firstname) && empty($lastname)) {
        json_error('At least one of firstname or lastname is required', 400);
    }
    
    // Detect people table column names
    $cols = get_people_columns();
    
    // Build query
    $conditions = [];
    $params = [];
    
    if (!empty($firstname)) {
        $conditions[] = "`{$cols['firstname']}` LIKE ?";
        $params[] = "%{$firstname}%";
    }
    if (!empty($lastname)) {
        $conditions[] = "`{$cols['lastname']}` LIKE ?";
        $params[] = "%{$lastname}%";
    }
    if (!empty($tree)) {
        $conditions[] = "`{$cols['gedcom']}` = ?";
        $params[] = $tree;
    }
    
    $where = implode(' AND ', $conditions);
    
    // Count total
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$tbl_people}` WHERE {$where}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();
    
    // Fetch page
    $select = "`{$cols['personID']}`, `{$cols['gedcom']}`, `{$cols['firstname']}`, `{$cols['lastname']}`, `{$cols['birthdate']}`, `{$cols['birthplace']}`, `{$cols['deathdate']}`, `{$cols['deathplace']}`, `{$cols['living']}`, `{$cols['sex']}`";
    $params_with_limit = array_merge($params, [$offset, $limit]);
    $stmt = $pdo->prepare("SELECT {$select} FROM `{$tbl_people}` WHERE {$where} ORDER BY `{$cols['lastname']}`, `{$cols['firstname']}` LIMIT ?, ?");
    $stmt->execute($params_with_limit);
    $rows = $stmt->fetchAll();
    
    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'personID'   => $row[$cols['personID']] ?? '',
            'tree'       => $row[$cols['gedcom']] ?? '',
            'firstName'  => $row[$cols['firstname']] ?? '',
            'lastName'   => $row[$cols['lastname']] ?? '',
            'birthDate'  => redact_if_living_col($row, $cols['birthdate'], $cols['living']),
            'birthPlace' => redact_if_living_col($row, $cols['birthplace'], $cols['living']),
            'deathDate'  => $row[$cols['deathdate']] ?? '',
            'deathPlace' => $row[$cols['deathplace']] ?? '',
            'living'     => (bool)($row[$cols['living']] ?? false),
            'sex'        => $row[$cols['sex']] ?? '',
        ];
    }
    
    json_response([
        'results' => $results,
        'total'   => $total,
        'page'    => $page,
        'limit'   => $limit,
    ]);
}

function handle_person() {
    global $pdo, $tbl_people, $tbl_families, $tbl_children, $tbl_events, $tbl_eventtypes, $tbl_places, $tbl_media, $tbl_medialinks;
    
    // Wrap entire handler in error catching so no silent failures occur
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    
    try {
        _handle_person_inner();
    } catch (Exception $e) {
        json_error('Person endpoint error: ' . $e->getMessage(), 500);
    } catch (Error $e) {
        json_error('Person endpoint fatal: ' . $e->getMessage(), 500);
    } finally {
        restore_error_handler();
    }
}

function _handle_person_inner() {
    global $pdo, $tbl_people, $tbl_families, $tbl_children, $tbl_events, $tbl_eventtypes, $tbl_places, $tbl_media, $tbl_medialinks;
    
    require_auth_optional();
    
    $id   = isset($_GET['id'])   ? trim($_GET['id'])   : '';
    $tree = isset($_GET['tree']) ? trim($_GET['tree']) : '';
    
    if (empty($id) || empty($tree)) {
        json_error('Parameters id and tree are required', 400);
    }
    
    // Detect people table column names
    $cols = get_people_columns();
    
    // Fetch person using detected column names
    $stmt = $pdo->prepare("SELECT * FROM `{$tbl_people}` WHERE `{$cols['personID']}` = ? AND `{$cols['gedcom']}` = ?");
    $stmt->execute([$id, $tree]);
    $person = $stmt->fetch();
    
    if (!$person) {
        json_error('Person not found', 404);
    }
    
    // Build response using detected column names — use safe_get to avoid notices on missing columns
    $g = function($col) use ($person, $cols) {
        $key = isset($cols[$col]) ? $cols[$col] : $col;
        return isset($person[$key]) ? $person[$key] : '';
    };
    
    $result = [
        'personID'    => $g('personID'),
        'tree'        => $g('gedcom'),
        'firstName'   => $g('firstname'),
        'lastName'    => $g('lastname'),
        'sex'         => $g('sex'),
        'living'      => (bool)$g('living'),
        'birthDate'   => redact_if_living_col($person, $cols['birthdate'], $cols['living']),
        'birthPlace'  => redact_if_living_col($person, $cols['birthplace'], $cols['living']),
        'deathDate'   => $g('deathdate'),
        'deathPlace'  => $g('deathplace'),
        'burialDate'  => $g('burialdate'),
        'burialPlace' => $g('burialplace'),
        'notes'       => $g('notes'),
    ];
    
    // Get coordinates for places
    $birthplace = $g('birthplace');
    $deathplace = $g('deathplace');
    $burialplace = $g('burialplace');
    $result['birthCoord'] = get_place_coords($birthplace);
    $result['deathCoord'] = get_place_coords($deathplace);
    $result['burialCoord'] = get_place_coords($burialplace);
    
    // Get custom events
    try {
        $stmt = $pdo->prepare("
            SELECT e.eventdate, e.eventplace, e.info, e.eventtypeID,
                   COALESCE(et.display, et.tag, 'Event') as eventType
            FROM `{$tbl_events}` e
            LEFT JOIN `{$tbl_eventtypes}` et ON e.eventtypeID = et.eventtypeID
            WHERE e.persfamID = ? AND e.gedcom = ?
            ORDER BY e.eventdate
        ");
        $stmt->execute([$id, $tree]);
        $events = $stmt->fetchAll();
        
        $result['customEvents'] = [];
        foreach ($events as $evt) {
            $result['customEvents'][] = [
                'eventType' => $evt['eventType'] ?? 'Event',
                'date'      => $evt['eventdate'] ?? '',
                'place'     => $evt['eventplace'] ?? '',
                'info'      => $evt['info'] ?? '',
            ];
        }
    } catch (PDOException $e) {
        $result['customEvents'] = [];
    }
    
    // Get parent family (family where this person is a child)
    try {
        $stmt = $pdo->prepare("SELECT familyID FROM `{$tbl_children}` WHERE personID = ? AND gedcom = ? LIMIT 1");
        $stmt->execute([$id, $tree]);
        $parent_link = $stmt->fetch();
        
        if ($parent_link) {
            $fam_stmt = $pdo->prepare("SELECT familyID, husband, wife FROM `{$tbl_families}` WHERE familyID = ? AND gedcom = ?");
            $fam_stmt->execute([$parent_link['familyID'], $tree]);
            $parent_fam = $fam_stmt->fetch();
            
            if ($parent_fam) {
                $result['parents'] = [
                    'familyID' => $parent_fam['familyID'],
                    'father'   => get_person_summary($parent_fam['husband'] ?? '', $tree),
                    'mother'   => get_person_summary($parent_fam['wife'] ?? '', $tree),
                ];
            }
        }
    } catch (PDOException $e) {
        // No parents found
    }
    
    // Get spouse families
    try {
        $stmt = $pdo->prepare("SELECT * FROM `{$tbl_families}` WHERE (husband = ? OR wife = ?) AND gedcom = ?");
        $stmt->execute([$id, $id, $tree]);
        $spouse_fams = $stmt->fetchAll();
        
        $result['spouseFamilies'] = [];
        foreach ($spouse_fams as $fam) {
            $spouse_id = ($fam['husband'] === $id) ? ($fam['wife'] ?? '') : ($fam['husband'] ?? '');
            
            // Get children
            $ch_stmt = $pdo->prepare("
                SELECT p.personID, p.firstname, p.lastname, p.birthdate, p.sex
                FROM `{$tbl_children}` c
                JOIN `{$tbl_people}` p ON c.personID = p.personID AND c.gedcom = p.gedcom
                WHERE c.familyID = ? AND c.gedcom = ?
                ORDER BY c.ordernum
            ");
            $ch_stmt->execute([$fam['familyID'], $tree]);
            $children = $ch_stmt->fetchAll();
            
            $children_arr = [];
            foreach ($children as $child) {
                $children_arr[] = [
                    'personID'  => $child['personID'],
                    'firstName' => $child['firstname'] ?? '',
                    'lastName'  => $child['lastname'] ?? '',
                    'birthDate' => $child['birthdate'] ?? '',
                    'sex'       => $child['sex'] ?? '',
                ];
            }
            
            $result['spouseFamilies'][] = [
                'familyID'      => $fam['familyID'],
                'spouse'        => get_person_summary($spouse_id, $tree),
                'marriageDate'  => $fam['marrdate'] ?? '',
                'marriagePlace' => $fam['marrplace'] ?? '',
                'children'      => $children_arr,
            ];
        }
    } catch (PDOException $e) {
        $result['spouseFamilies'] = [];
    }
    
    // Get media
    try {
        $stmt = $pdo->prepare("
            SELECT m.mediaID, m.path, m.description, m.mediatypeID
            FROM `{$tbl_medialinks}` ml
            JOIN `{$tbl_media}` m ON ml.mediaID = m.mediaID AND ml.gedcom = m.gedcom
            WHERE ml.personID = ? AND ml.gedcom = ?
        ");
        $stmt->execute([$id, $tree]);
        $media = $stmt->fetchAll();
        
        $result['media'] = [];
        foreach ($media as $m) {
            $result['media'][] = [
                'mediaID'     => $m['mediaID'],
                'path'        => $m['path'] ?? '',
                'description' => $m['description'] ?? '',
                'type'        => map_media_type($m['mediatypeID'] ?? ''),
            ];
        }
    } catch (PDOException $e) {
        $result['media'] = [];
    }
    
    json_response($result);
}

function handle_family() {
    global $pdo, $tbl_families, $tbl_children, $tbl_people;
    
    require_auth_optional();
    
    $id   = isset($_GET['id'])   ? trim($_GET['id'])   : '';
    $tree = isset($_GET['tree']) ? trim($_GET['tree']) : '';
    
    if (empty($id) || empty($tree)) {
        json_error('Parameters id and tree are required', 400);
    }
    
    $stmt = $pdo->prepare("SELECT * FROM `{$tbl_families}` WHERE familyID = ? AND gedcom = ?");
    $stmt->execute([$id, $tree]);
    $fam = $stmt->fetch();
    
    if (!$fam) {
        json_error('Family not found', 404);
    }
    
    // Get children
    $ch_stmt = $pdo->prepare("
        SELECT p.personID, p.firstname, p.lastname, p.birthdate, p.sex
        FROM `{$tbl_children}` c
        JOIN `{$tbl_people}` p ON c.personID = p.personID AND c.gedcom = p.gedcom
        WHERE c.familyID = ? AND c.gedcom = ?
        ORDER BY c.ordernum
    ");
    $ch_stmt->execute([$id, $tree]);
    $children = $ch_stmt->fetchAll();
    
    $children_arr = [];
    foreach ($children as $child) {
        $children_arr[] = [
            'personID'  => $child['personID'],
            'firstName' => $child['firstname'] ?? '',
            'lastName'  => $child['lastname'] ?? '',
            'birthDate' => $child['birthdate'] ?? '',
            'sex'       => $child['sex'] ?? '',
        ];
    }
    
    json_response([
        'familyID'      => $fam['familyID'],
        'tree'          => $fam['gedcom'],
        'husband'       => get_person_summary($fam['husband'] ?? '', $tree),
        'wife'          => get_person_summary($fam['wife'] ?? '', $tree),
        'marriageDate'  => $fam['marrdate'] ?? '',
        'marriagePlace' => $fam['marrplace'] ?? '',
        'divorceDate'   => $fam['divdate'] ?? '',
        'children'      => $children_arr,
    ]);
}

function handle_trees() {
    global $pdo, $tbl_trees;
    
    try {
        $stmt = $pdo->query("SELECT gedcom, treename, description FROM `{$tbl_trees}` ORDER BY treename");
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        $rows = [];
    }
    
    $trees = [];
    foreach ($rows as $row) {
        $trees[] = [
            'id'          => $row['gedcom'],
            'name'        => $row['treename'] ?? $row['gedcom'],
            'description' => $row['description'] ?? '',
        ];
    }
    
    json_response(['trees' => $trees]);
}

function handle_places() {
    global $pdo, $tbl_places;
    
    try {
        $stmt = $pdo->query("
            SELECT place, latitude, longitude 
            FROM `{$tbl_places}` 
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL 
              AND latitude != '' AND longitude != '' 
              AND latitude != '0' AND longitude != '0'
            ORDER BY place
        ");
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        $rows = [];
    }
    
    $places = [];
    foreach ($rows as $row) {
        $lat = floatval($row['latitude']);
        $lng = floatval($row['longitude']);
        if ($lat != 0.0 || $lng != 0.0) {
            $places[] = [
                'place' => $row['place'],
                'lat'   => $lat,
                'lng'   => $lng,
            ];
        }
    }
    
    json_response(['places' => $places]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ── Helper Functions ────────────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════════════════════

function get_people_columns() {
    global $pdo, $tbl_people;
    static $cache = null;
    if ($cache !== null) return $cache;
    
    // Get actual column names from the people table
    $available = [];
    try {
        $col_stmt = $pdo->query("SHOW COLUMNS FROM `{$tbl_people}`");
        while ($col = $col_stmt->fetch()) {
            $available[] = $col['Field'];
        }
    } catch (PDOException $e) {
        // Fall back to standard TNG column names
        $cache = [
            'personID' => 'personID', 'gedcom' => 'gedcom',
            'firstname' => 'firstname', 'lastname' => 'lastname',
            'sex' => 'sex', 'living' => 'living',
            'birthdate' => 'birthdate', 'birthplace' => 'birthplace',
            'deathdate' => 'deathdate', 'deathplace' => 'deathplace',
            'burialdate' => 'burialdate', 'burialplace' => 'burialplace',
            'notes' => 'notes',
        ];
        return $cache;
    }
    
    $available_lower = array_map('strtolower', $available);
    // Build a case-sensitive map: lowercase -> actual name
    $col_map = array_combine($available_lower, $available);
    
    // Helper to find a column from candidates
    $find = function($candidates) use ($col_map) {
        foreach ($candidates as $c) {
            if (isset($col_map[strtolower($c)])) {
                return $col_map[strtolower($c)];
            }
        }
        return $candidates[0]; // default to first candidate
    };
    
    $cache = [
        'personID'    => $find(['personID', 'ID', 'person_id', 'id']),
        'gedcom'      => $find(['gedcom', 'tree', 'treeid', 'tree_id']),
        'firstname'   => $find(['firstname', 'first_name', 'givenname', 'given_name']),
        'lastname'    => $find(['lastname', 'last_name', 'surname', 'family_name']),
        'sex'         => $find(['sex', 'gender']),
        'living'      => $find(['living', 'is_living']),
        'birthdate'   => $find(['birthdate', 'birth_date', 'birthdatetr']),
        'birthplace'  => $find(['birthplace', 'birth_place']),
        'deathdate'   => $find(['deathdate', 'death_date', 'deathdatetr']),
        'deathplace'  => $find(['deathplace', 'death_place']),
        'burialdate'  => $find(['burialdate', 'burial_date']),
        'burialplace' => $find(['burialplace', 'burial_place']),
        'notes'       => $find(['notes', 'note']),
    ];
    return $cache;
}

function redact_if_living_col($row, $field_col, $living_col) {
    $is_living = (bool)(isset($row[$living_col]) ? $row[$living_col] : false);
    if ($is_living && !is_authenticated()) {
        return 'Living';
    }
    return isset($row[$field_col]) ? $row[$field_col] : '';
}

function get_person_summary($personID, $tree) {
    global $pdo, $tbl_people;
    
    if (empty($personID)) return null;
    
    $cols = get_people_columns();
    
    try {
        $stmt = $pdo->prepare("SELECT `{$cols['personID']}`, `{$cols['firstname']}`, `{$cols['lastname']}` FROM `{$tbl_people}` WHERE `{$cols['personID']}` = ? AND `{$cols['gedcom']}` = ?");
        $stmt->execute([$personID, $tree]);
        $p = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
    
    if (!$p) return null;
    
    return [
        'personID'  => $p[$cols['personID']] ?? '',
        'firstName' => $p[$cols['firstname']] ?? '',
        'lastName'  => $p[$cols['lastname']] ?? '',
    ];
}

function get_place_coords($place_name) {
    global $pdo, $tbl_places;
    
    if (empty($place_name)) return null;
    
    try {
        $stmt = $pdo->prepare("SELECT latitude, longitude FROM `{$tbl_places}` WHERE place = ? LIMIT 1");
        $stmt->execute([$place_name]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
    
    if (!$row || empty($row['latitude']) || empty($row['longitude'])) return null;
    
    $lat = floatval($row['latitude']);
    $lng = floatval($row['longitude']);
    
    if ($lat == 0.0 && $lng == 0.0) return null;
    
    return ['lat' => $lat, 'lng' => $lng];
}

function map_media_type($type_id) {
    $map = [
        'photos'     => 'photo',
        'photo'      => 'photo',
        'documents'  => 'document',
        'document'   => 'document',
        'headstones' => 'headstone',
        'headstone'  => 'headstone',
        'histories'  => 'history',
        'history'    => 'history',
    ];
    $lower = strtolower(trim($type_id));
    return isset($map[$lower]) ? $map[$lower] : $lower;
}

function redact_if_living($row, $field) {
    $is_living = (bool)($row['living'] ?? false);
    if ($is_living && !is_authenticated()) {
        return 'Living';
    }
    return $row[$field] ?? '';
}

function require_auth_optional() {
    validate_token_if_present();
}

function validate_token_if_present() {
    global $pdo, $tbl_api_sessions;
    
    $token = get_auth_token();
    if (empty($token)) return;
    
    try {
        $stmt = $pdo->prepare("SELECT username FROM `{$tbl_api_sessions}` WHERE token = ? AND last_used > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        
        if ($session) {
            $update = $pdo->prepare("UPDATE `{$tbl_api_sessions}` SET last_used = NOW() WHERE token = ?");
            $update->execute([$token]);
            $GLOBALS['authenticated_user'] = $session['username'];
        }
    } catch (PDOException $e) {
        // Ignore session errors
    }
}

function is_authenticated() {
    return !empty($GLOBALS['authenticated_user']);
}

function get_auth_token() {
    // Check header first (case-insensitive)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'x-api-token') {
                return $value;
            }
        }
    }
    
    // Fallback: check $_SERVER for Apache/nginx header forwarding
    if (isset($_SERVER['HTTP_X_API_TOKEN'])) {
        return $_SERVER['HTTP_X_API_TOKEN'];
    }
    
    // Fallback to query param
    if (isset($_GET['token'])) return $_GET['token'];
    
    return '';
}

function get_post($key) {
    // Support both form-encoded and JSON body
    if (isset($_POST[$key])) return $_POST[$key];
    
    // Try JSON body
    static $json_body = null;
    if ($json_body === null) {
        $input = file_get_contents('php://input');
        $json_body = json_decode($input, true);
        if (!is_array($json_body)) $json_body = [];
    }
    if (isset($json_body[$key])) return $json_body[$key];
    
    return '';
}

function json_response($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
