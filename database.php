<?php
require_once __DIR__ . '/includes/core.php';
require_permission('can_db_view', 'Access denied. You do not have permission to view databases.');

$rootPdo = null;
$serverVersion = 'MariaDB 10.11';
$allDbs = [];
$systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys', AUTH_DB];

try {
    $rootPdo = get_db_connection();
    $serverVersion = $rootPdo->query("SELECT VERSION()")->fetchColumn() ?: 'MariaDB 10.11';
    $allDbs = $rootPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

$userDbs = array_values(array_filter($allDbs, function($d) use ($systemDbs) {
    return !in_array($d, $systemDbs);
}));

// Helper to format tabular ASCII output for SQL Terminal
if (!function_exists('render_sql_terminal_table')) {
    function render_sql_terminal_table(array $columns, array $rows, int $maxDisplayRows = 300): string {
        if (empty($columns)) {
            return "Empty set\n";
        }
        $truncated = false;
        $totalCount = count($rows);
        if ($totalCount > $maxDisplayRows) {
            $rows = array_slice($rows, 0, $maxDisplayRows);
            $truncated = true;
        }

        $widths = [];
        foreach ($columns as $col) {
            $widths[$col] = mb_strwidth((string)$col, 'UTF-8');
        }

        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $val = $row[$col] ?? null;
                if ($val === null) {
                    $strVal = 'NULL';
                } elseif (is_bool($val)) {
                    $strVal = $val ? '1' : '0';
                } else {
                    $strVal = (string)$val;
                    $strVal = str_replace(["\r\n", "\r", "\n"], ' ', $strVal);
                    if (mb_strlen($strVal, 'UTF-8') > 100) {
                        $strVal = mb_substr($strVal, 0, 97, 'UTF-8') . '...';
                    }
                }
                $w = mb_strwidth($strVal, 'UTF-8');
                if ($w > $widths[$col]) {
                    $widths[$col] = $w;
                }
            }
        }

        foreach ($widths as $col => $w) {
            if ($w > 100) $widths[$col] = 100;
        }

        $border = '+';
        foreach ($columns as $col) {
            $border .= str_repeat('-', $widths[$col] + 2) . '+';
        }
        $border .= "\n";

        $out = $border . '|';
        foreach ($columns as $col) {
            $w = $widths[$col];
            $colStr = (string)$col;
            if (mb_strwidth($colStr, 'UTF-8') > $w) {
                $colStr = mb_substr($colStr, 0, $w, 'UTF-8');
            }
            $pad = $w - mb_strwidth($colStr, 'UTF-8');
            $out .= ' ' . $colStr . str_repeat(' ', max(0, $pad)) . ' |';
        }
        $out .= "\n" . $border;

        foreach ($rows as $row) {
            $out .= '|';
            foreach ($columns as $col) {
                $w = $widths[$col];
                $val = $row[$col] ?? null;
                if ($val === null) {
                    $strVal = 'NULL';
                } elseif (is_bool($val)) {
                    $strVal = $val ? '1' : '0';
                } else {
                    $strVal = (string)$val;
                    $strVal = str_replace(["\r\n", "\r", "\n"], ' ', $strVal);
                    if (mb_strwidth($strVal, 'UTF-8') > $w) {
                        $strVal = mb_substr($strVal, 0, max(1, $w - 3), 'UTF-8') . '...';
                    }
                }
                $pad = $w - mb_strwidth($strVal, 'UTF-8');
                $out .= ' ' . $strVal . str_repeat(' ', max(0, $pad)) . ' |';
            }
            $out .= "\n";
        }

        $out .= $border;
        if ($truncated) {
            $out .= "[Warning: Display truncated to {$maxDisplayRows} of {$totalCount} rows]\n";
        }
        return $out;
    }
}

// Current DB Selection
$currentDb = $_GET['db'] ?? ($userDbs[0] ?? ($allDbs[0] ?? ''));
if ($currentDb && !in_array($currentDb, $allDbs)) {
    $currentDb = $userDbs[0] ?? ($allDbs[0] ?? '');
}

// Handle AJAX SQL Terminal Command Execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exec_sql_terminal'])) {
    require_permission('can_db_query', 'Access denied. You do not have permission to execute SQL queries.');
    header('Content-Type: application/json; charset=utf-8');
    $rawSql = trim($_POST['sql'] ?? '');
    $targetDb = trim($_POST['db'] ?? $currentDb);
    if (!in_array($targetDb, $allDbs) && !empty($allDbs)) {
        $targetDb = $userDbs[0] ?? ($allDbs[0] ?? '');
    }

    if (empty($rawSql)) {
        echo json_encode(['success' => true, 'output' => '', 'db' => $targetDb]);
        exit;
    }

    $trimmedLower = strtolower(rtrim($rawSql, '; '));

    // Handle special meta commands
    if (in_array($trimmedLower, ['clear', 'cls', '\c'])) {
        echo json_encode(['success' => true, 'action' => 'clear', 'db' => $targetDb]);
        exit;
    }

    if (in_array($trimmedLower, ['help', '\h', '?'])) {
        $helpMsg = "MariaDB / MySQL Web Terminal CLI Help:\n" .
                   "--------------------------------------------------\n" .
                   "  USE <db_name>;          Switch default database\n" .
                   "  SHOW DATABASES;         List all accessible databases\n" .
                   "  SHOW TABLES;            List tables in current database\n" .
                   "  DESCRIBE <table_name>;  Display column schema\n" .
                   "  SHOW TABLE STATUS;      Display table engines, sizes and rows\n" .
                   "  SHOW PROCESSLIST;       Display current database connections\n" .
                   "  STATUS; or \\s           Display server status and connection info\n" .
                   "  SELECT ... ;            Execute SQL query\n" .
                   "  CLEAR or CLS            Clear screen history\n" .
                   "  HELP or \\h              Show this help reference\n" .
                   "--------------------------------------------------\n" .
                   "Shortcuts: Enter to execute, Shift+Enter for new line, ↑/↓ for history, Tab for autocomplete.\n";
        echo json_encode(['success' => true, 'output' => $helpMsg, 'db' => $targetDb]);
        exit;
    }

    if (in_array($trimmedLower, ['status', '\s'])) {
        $statusMsg = "--------------\n" .
                     "Connection:             db via TCP/IP\n" .
                     "Server version:         " . $serverVersion . "\n" .
                     "Current database:       " . ($targetDb ?: '(none)') . "\n" .
                     "Current user:           root@workspace\n" .
                     "Client characterset:    utf8mb4\n" .
                     "Server characterset:    utf8mb4\n" .
                     "Uptime:                 Active\n" .
                     "--------------\n";
        echo json_encode(['success' => true, 'output' => $statusMsg, 'db' => $targetDb]);
        exit;
    }

    // Handle USE <db>
    if (preg_match('/^USE\s+[`\'"]?([a-zA-Z0-9_\-]+)[`\'"]?;?$/i', $rawSql, $m)) {
        $newDb = $m[1];
        if (in_array($newDb, $allDbs)) {
            echo json_encode([
                'success' => true,
                'output' => "Database changed to `{$newDb}`\n",
                'db' => $newDb,
                'is_use' => true
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'output' => "ERROR 1049 (42000): Unknown database '{$newDb}'\n",
                'db' => $targetDb
            ]);
        }
        exit;
    }

    // Guest Mode Safety Filter
    if (is_guest()) {
        $trimmedSql = ltrim($rawSql);
        if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH|STATUS|HELP)\b/i', $trimmedSql)) {
            echo json_encode([
                'success' => false,
                'output' => "ERROR 1142 (42000): command denied to user 'guest' in Guest Mode (Read-Only access enforced).\n",
                'db' => $targetDb
            ]);
            exit;
        }
    }

    $startTime = microtime(true);
    try {
        $dbPdo = get_db_connection($targetDb ?: null);
        $stmt = $dbPdo->query($rawSql);
        $elapsed = round(microtime(true) - $startTime, 4);

        if ($stmt) {
            $colCount = $stmt->columnCount();
            if ($colCount > 0) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = !empty($rows) ? array_keys($rows[0]) : [];
                $ascii = render_sql_terminal_table($columns, $rows);
                $rowCount = count($rows);
                $ascii .= "{$rowCount} row" . ($rowCount === 1 ? '' : 's') . " in set ({$elapsed} sec)\n";

                echo json_encode([
                    'success' => true,
                    'output' => $ascii,
                    'db' => $targetDb,
                    'execution_time' => $elapsed,
                    'raw' => [
                        'columns' => $columns,
                        'rows' => array_slice($rows, 0, 100),
                        'total_rows' => $rowCount,
                        'type' => 'select'
                    ]
                ]);
            } else {
                $affected = $stmt->rowCount();
                $output = "Query OK, {$affected} row" . ($affected === 1 ? '' : 's') . " affected ({$elapsed} sec)\n";
                echo json_encode([
                    'success' => true,
                    'output' => $output,
                    'db' => $targetDb,
                    'execution_time' => $elapsed,
                    'raw' => [
                        'affected' => $affected,
                        'type' => 'mutation'
                    ]
                ]);
            }
        } else {
            echo json_encode([
                'success' => true,
                'output' => "Query OK ({$elapsed} sec)\n",
                'db' => $targetDb
            ]);
        }
    } catch (Exception $e) {
        $elapsed = round(microtime(true) - $startTime, 4);
        $errCode = $e->getCode() ?: '1064';
        echo json_encode([
            'success' => false,
            'output' => "ERROR {$errCode}: " . $e->getMessage() . " ({$elapsed} sec)\n",
            'db' => $targetDb,
            'execution_time' => $elapsed
        ]);
    }
    exit;
}

$activeTab = $_GET['tab'] ?? 'tables';
$currentTable = trim($_GET['table'] ?? '');

// Protect destructive database mutations from Guest Mode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['run_query'])) {
    require_not_guest('Database mutations and schema changes are disabled in Guest Mode.');
}

// Action: Create Database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_database'])) {
    require_permission('can_db_create', 'Access denied. You do not have permission to create databases.');
    $newDb = trim($_POST['db_name'] ?? '');
    $charset = trim($_POST['db_charset'] ?? 'utf8mb4');
    $collate = trim($_POST['db_collate'] ?? 'utf8mb4_unicode_ci');
    if (preg_match('/^[a-zA-Z0-9_]+$/', $newDb)) {
        try {
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$newDb` CHARACTER SET $charset COLLATE $collate");
            set_flash('success', "Database '{$newDb}' created successfully.");
        } catch (Exception $e) {
            set_flash('error', "Failed to create database: " . $e->getMessage());
        }
    } else {
        set_flash('error', "Invalid database name. Use letters, numbers, and underscores only.");
    }
    header('Location: /dashboard/database.php' . ($newDb ? '?db=' . urlencode($newDb) : ''));
    exit;
}

// Action: Drop Database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['drop_database'])) {
    require_permission('can_db_create', 'Access denied. You do not have permission to drop databases.');
    $dropDb = trim($_POST['db_name'] ?? '');
    if ($dropDb && !in_array($dropDb, $systemDbs) && preg_match('/^[a-zA-Z0-9_]+$/', $dropDb)) {
        try {
            $rootPdo->exec("DROP DATABASE `$dropDb`");
            set_flash('success', "Database '{$dropDb}' dropped successfully.");
        } catch (Exception $e) {
            set_flash('error', "Failed to drop database: " . $e->getMessage());
        }
    } else {
        set_flash('error', "Cannot drop system protected database.");
    }
    header('Location: /dashboard/database.php');
    exit;
}

// Action: Create Table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_table_action'])) {
    require_permission('can_db_create', 'Access denied. You do not have permission to create tables.');
    $targetDb = trim($_POST['db'] ?? '');
    $tableName = trim($_POST['table_name'] ?? '');
    $engine = trim($_POST['table_engine'] ?? 'InnoDB');
    $colNames = $_POST['col_name'] ?? [];
    $colTypes = $_POST['col_type'] ?? [];
    $colLengths = $_POST['col_length'] ?? [];
    $colNulls = $_POST['col_null'] ?? [];
    $colAIs = $_POST['col_ai'] ?? [];
    $colPrimary = $_POST['col_primary'] ?? '';

    if ($targetDb && preg_match('/^[a-zA-Z0-9_]+$/', $tableName) && !empty($colNames)) {
        try {
            $dbPdo = get_db_connection($targetDb);
            $colDefs = [];
            $primaryKeys = [];

            for ($i = 0; $i < count($colNames); $i++) {
                $cName = trim($colNames[$i]);
                if (!$cName || !preg_match('/^[a-zA-Z0-9_]+$/', $cName)) continue;
                
                $cType = strtoupper(trim($colTypes[$i] ?? 'VARCHAR'));
                $cLen = trim($colLengths[$i] ?? '');
                $cNull = !empty($colNulls[$i]) ? 'NULL' : 'NOT NULL';
                $cAI = !empty($colAIs[$i]) ? 'AUTO_INCREMENT' : '';

                $typeDef = $cType;
                if ($cLen !== '' && !in_array($cType, ['TEXT', 'LONGTEXT', 'MEDIUMTEXT', 'DATE', 'DATETIME', 'TIMESTAMP', 'JSON', 'BOOLEAN'])) {
                    $typeDef .= "($cLen)";
                }

                $colDefs[] = "`$cName` $typeDef $cNull $cAI";

                if ($colPrimary === $cName || (is_numeric($colPrimary) && (int)$colPrimary === $i)) {
                    $primaryKeys[] = "`$cName`";
                }
            }

            if (!empty($primaryKeys)) {
                $colDefs[] = "PRIMARY KEY (" . implode(', ', $primaryKeys) . ")";
            }

            if (!empty($colDefs)) {
                $sql = "CREATE TABLE `$tableName` (\n  " . implode(",\n  ", $colDefs) . "\n) ENGINE=$engine DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
                $dbPdo->exec($sql);
                set_flash('success', "Table '{$tableName}' created successfully.");
                header("Location: /dashboard/database.php?db=" . urlencode($targetDb) . "&table=" . urlencode($tableName) . "&tab=structure");
                exit;
            } else {
                set_flash('error', "Please provide at least one valid column definition.");
            }
        } catch (Exception $e) {
            set_flash('error', "Failed to create table: " . $e->getMessage());
        }
    } else {
        set_flash('error', "Invalid table name or database selected.");
    }
    header("Location: /dashboard/database.php?db=" . urlencode($targetDb));
    exit;
}

// Action: Drop Table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['drop_table_action'])) {
    require_permission('can_db_create', 'Access denied. You do not have permission to drop tables.');
    $targetDb = trim($_POST['db'] ?? '');
    $targetTable = trim($_POST['table_name'] ?? '');
    if ($targetDb && preg_match('/^[a-zA-Z0-9_]+$/', $targetTable)) {
        try {
            $dbPdo = get_db_connection($targetDb);
            $dbPdo->exec("DROP TABLE `$targetTable`");
            set_flash('success', "Table '{$targetTable}' dropped successfully.");
        } catch (Exception $e) {
            set_flash('error', "Failed to drop table: " . $e->getMessage());
        }
    }
    header("Location: /dashboard/database.php?db=" . urlencode($targetDb));
    exit;
}

// Action: Truncate Table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['truncate_table_action'])) {
    require_permission('can_db_create', 'Access denied. You do not have permission to truncate tables.');
    $targetDb = trim($_POST['db'] ?? '');
    $targetTable = trim($_POST['table_name'] ?? '');
    if ($targetDb && preg_match('/^[a-zA-Z0-9_]+$/', $targetTable)) {
        try {
            $dbPdo = get_db_connection($targetDb);
            $dbPdo->exec("TRUNCATE TABLE `$targetTable`");
            set_flash('success', "Table '{$targetTable}' truncated (all rows removed).");
        } catch (Exception $e) {
            set_flash('error', "Failed to truncate table: " . $e->getMessage());
        }
    }
    header("Location: /dashboard/database.php?db=" . urlencode($targetDb) . "&table=" . urlencode($targetTable) . "&tab=browse");
    exit;
}

// Action: Update Row Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_row_action'])) {
    require_permission('can_db_query', 'Access denied. You do not have permission to modify table rows.');
    $targetDb = trim($_POST['db'] ?? '');
    $targetTable = trim($_POST['table_name'] ?? '');
    $pkDataJson = $_POST['pk_data'] ?? '{}';
    $pkData = json_decode($pkDataJson, true) ?: [];
    $rowData = $_POST['row_data'] ?? [];
    $rowNull = $_POST['row_null'] ?? [];
    $returnPage = max(1, (int)($_POST['return_page'] ?? 1));

    if ($targetDb && $targetTable && preg_match('/^[a-zA-Z0-9_]+$/', $targetTable) && !empty($pkData)) {
        try {
            $dbPdo = get_db_connection($targetDb);
            $setClauses = [];
            $params = [];

            foreach ($rowData as $col => $val) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) continue;
                $setClauses[] = "`$col` = ?";
                $params[] = isset($rowNull[$col]) ? null : $val;
            }

            $whereClauses = [];
            foreach ($pkData as $pkCol => $pkVal) {
                if ($pkVal === null) {
                    $whereClauses[] = "`$pkCol` IS NULL";
                } else {
                    $whereClauses[] = "`$pkCol` = ?";
                    $params[] = $pkVal;
                }
            }

            if (!empty($setClauses) && !empty($whereClauses)) {
                $sql = "UPDATE `$targetTable` SET " . implode(', ', $setClauses) . " WHERE " . implode(' AND ', $whereClauses) . " LIMIT 1";
                $stmt = $dbPdo->prepare($sql);
                $stmt->execute($params);
                set_flash('success', "Row updated successfully in '{$targetTable}'.");
            }
        } catch (Exception $e) {
            set_flash('error', "Failed to update row: " . $e->getMessage());
        }
    }
    header("Location: /dashboard/database.php?db=" . urlencode($targetDb) . "&table=" . urlencode($targetTable) . "&tab=browse&page=" . $returnPage);
    exit;
}

// Action: Insert Row Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insert_row_action'])) {
    require_permission('can_db_query', 'Access denied. You do not have permission to insert table rows.');
    $targetDb = trim($_POST['db'] ?? '');
    $targetTable = trim($_POST['table_name'] ?? '');
    $rowData = $_POST['row_data'] ?? [];
    $rowNull = $_POST['row_null'] ?? [];

    if ($targetDb && $targetTable && preg_match('/^[a-zA-Z0-9_]+$/', $targetTable)) {
        try {
            $dbPdo = get_db_connection($targetDb);
            $colNames = [];
            $placeholders = [];
            $params = [];

            foreach ($rowData as $col => $val) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) continue;
                
                if (isset($rowNull[$col])) {
                    $colNames[] = "`$col`";
                    $placeholders[] = "NULL";
                } elseif ($val !== '') {
                    $colNames[] = "`$col`";
                    $placeholders[] = "?";
                    $params[] = $val;
                }
            }

            if (!empty($colNames)) {
                $sql = "INSERT INTO `$targetTable` (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $dbPdo->prepare($sql);
                $stmt->execute($params);
                set_flash('success', "New row inserted into '{$targetTable}'.");
            } else {
                $dbPdo->exec("INSERT INTO `$targetTable` () VALUES ()");
                set_flash('success', "New row inserted into '{$targetTable}'.");
            }
        } catch (Exception $e) {
            set_flash('error', "Failed to insert row: " . $e->getMessage());
        }
    }
    header("Location: /dashboard/database.php?db=" . urlencode($targetDb) . "&table=" . urlencode($targetTable) . "&tab=browse");
    exit;
}

// Action: Delete Single Row
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_row_action'])) {
    require_permission('can_db_query', 'Access denied. You do not have permission to delete table rows.');
    $targetDb = trim($_POST['db'] ?? '');
    $targetTable = trim($_POST['table_name'] ?? '');
    $pkDataJson = $_POST['pk_data'] ?? '{}';
    $pkData = json_decode($pkDataJson, true) ?: [];
    $returnPage = max(1, (int)($_POST['return_page'] ?? 1));

    if ($targetDb && $targetTable && preg_match('/^[a-zA-Z0-9_]+$/', $targetTable) && !empty($pkData)) {
        try {
            $dbPdo = get_db_connection($targetDb);
            $whereClauses = [];
            $params = [];

            foreach ($pkData as $pkCol => $pkVal) {
                if ($pkVal === null) {
                    $whereClauses[] = "`$pkCol` IS NULL";
                } else {
                    $whereClauses[] = "`$pkCol` = ?";
                    $params[] = $pkVal;
                }
            }

            if (!empty($whereClauses)) {
                $sql = "DELETE FROM `$targetTable` WHERE " . implode(' AND ', $whereClauses) . " LIMIT 1";
                $stmt = $dbPdo->prepare($sql);
                $stmt->execute($params);
                set_flash('success', "Row deleted from '{$targetTable}'.");
            }
        } catch (Exception $e) {
            set_flash('error', "Failed to delete row: " . $e->getMessage());
        }
    }
    header("Location: /dashboard/database.php?db=" . urlencode($targetDb) . "&table=" . urlencode($targetTable) . "&tab=browse&page=" . $returnPage);
    exit;
}

// Action: Optimize Tables
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['optimize_all_tables'])) {
    require_permission('can_db_query', 'Access denied. You do not have permission to optimize tables.');
    $targetDb = trim($_POST['db'] ?? '');
    if ($targetDb) {
        try {
            $dbPdo = get_db_connection($targetDb);
            $tables = $dbPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($tables)) {
                $tList = implode(', ', array_map(function($t) { return "`$t`"; }, $tables));
                $dbPdo->exec("OPTIMIZE TABLE $tList");
                set_flash('success', "Optimized " . count($tables) . " table(s) in '{$targetDb}'.");
            }
        } catch (Exception $e) {
            set_flash('error', "Optimization warning: " . $e->getMessage());
        }
    }
    header("Location: /dashboard/database.php?db=" . urlencode($targetDb) . "&tab=operations");
    exit;
}

// Action: Export Database or Table
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    require_permission('can_db_export', 'Access denied. You do not have permission to export database dumps.');
    $exportDb = trim($_GET['db'] ?? '');
    $exportTable = trim($_GET['table'] ?? '');
    $format = $_GET['format'] ?? 'sql';

    if (preg_match('/^[a-zA-Z0-9_]+$/', $exportDb)) {
        try {
            $dbPdo = get_db_connection($exportDb);

            if ($format === 'csv' && $exportTable) {
                // Export Single Table to CSV
                $stmt = $dbPdo->query("SELECT * FROM `$exportTable`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $exportTable . '_' . date('Ymd_His') . '.csv"');
                $output = fopen('php://output', 'w');
                if (!empty($rows)) {
                    fputcsv($output, array_keys($rows[0]));
                    foreach ($rows as $r) {
                        fputcsv($output, $r);
                    }
                }
                fclose($output);
                exit;
            }

            // Export SQL (Single Table or Entire Database)
            $tablesToDump = $exportTable ? [$exportTable] : $dbPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            $dump = "-- MariaDB SQL Export\n";
            $dump .= "-- Database: `{$exportDb}`\n";
            if ($exportTable) $dump .= "-- Table: `{$exportTable}`\n";
            $dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
            $dump .= "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n";

            foreach ($tablesToDump as $tbl) {
                $createStmt = $dbPdo->query("SHOW CREATE TABLE `$tbl`")->fetch(PDO::FETCH_ASSOC);
                $dump .= "-- --------------------------------------------------------\n";
                $dump .= "-- Table structure for `$tbl`\n--\n";
                $dump .= "DROP TABLE IF EXISTS `$tbl`;\n";
                $dump .= $createStmt['Create Table'] . ";\n\n";

                $rows = $dbPdo->query("SELECT * FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $dump .= "-- Dumping data for `$tbl`\n--\n";
                    $cols = array_map(function($c) { return "`" . str_replace("`", "``", $c) . "`"; }, array_keys($rows[0]));
                    $dump .= "INSERT INTO `$tbl` (" . implode(', ', $cols) . ") VALUES\n";
                    $valuesArr = [];
                    foreach ($rows as $row) {
                        $vals = array_map(function($v) use ($dbPdo) {
                            return $v === null ? 'NULL' : $dbPdo->quote($v);
                        }, array_values($row));
                        $valuesArr[] = "  (" . implode(', ', $vals) . ")";
                    }
                    $dump .= implode(",\n", $valuesArr) . ";\n\n";
                }
            }
            $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

            $filename = ($exportTable ? $exportTable : $exportDb) . '_' . date('Ymd_His') . '.sql';
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $dump;
            exit;
        } catch (Exception $e) {
            set_flash('error', "Export failed: " . $e->getMessage());
            header('Location: /dashboard/database.php?db=' . urlencode($exportDb));
            exit;
        }
    }
}

// Action: Run SQL Query
$queryResult = null;
$queryError = null;
$querySql = '';
$queryExecutionTime = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_query'])) {
    $selectedDb = trim($_POST['db'] ?? $currentDb);
    $querySql = trim($_POST['sql'] ?? '');
    $activeTab = 'query';
    if (!empty($querySql)) {
        if (is_guest()) {
            $trimmedSql = ltrim($querySql);
            if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $trimmedSql)) {
                $queryError = "Only read-only queries (SELECT, SHOW, DESCRIBE, EXPLAIN) are permitted in Guest Mode.";
            }
        }

        if (!$queryError) {
            $startTime = microtime(true);
            try {
                $dbPdo = get_db_connection($selectedDb ?: null);
                $stmt = $dbPdo->query($querySql);
                $queryExecutionTime = round((microtime(true) - $startTime) * 1000, 2);
                if ($stmt) {
                    $queryResult = [
                        'columns' => [],
                        'rows' => []
                    ];
                    if ($stmt->columnCount() > 0) {
                        $queryResult['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($queryResult['rows'])) {
                            $queryResult['columns'] = array_keys($queryResult['rows'][0]);
                        }
                    } else {
                        $queryResult['affected'] = $stmt->rowCount();
                    }
                }
            } catch (Exception $e) {
                $queryExecutionTime = round((microtime(true) - $startTime) * 1000, 2);
                $queryError = $e->getMessage();
            }
        }
    }
}

// Fetch Table List & Database Metrics
$tableList = [];
$dbTotalRows = 0;
$dbTotalSizeBytes = 0;
$dbCollation = 'utf8mb4_unicode_ci';

if ($currentDb && in_array($currentDb, $allDbs)) {
    try {
        $dbPdo = get_db_connection($currentDb);
        $collStmt = $dbPdo->query("SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $dbPdo->quote($currentDb));
        $dbCollation = $collStmt->fetchColumn() ?: 'utf8mb4_unicode_ci';

        $tables = $dbPdo->query("SHOW TABLE STATUS")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tables as $t) {
            $dataSize = (int)($t['Data_length'] ?? 0);
            $indexSize = (int)($t['Index_length'] ?? 0);
            $totalSize = $dataSize + $indexSize;
            $dbTotalSizeBytes += $totalSize;
            $dbTotalRows += (int)($t['Rows'] ?? 0);

            $tableList[] = [
                'name' => $t['Name'],
                'rows' => (int)($t['Rows'] ?? 0),
                'engine' => $t['Engine'] ?? 'InnoDB',
                'collation' => $t['Collation'] ?? 'utf8mb4_unicode_ci',
                'auto_increment' => $t['Auto_increment'] ?? '-',
                'data_size' => round($dataSize / 1024, 1),
                'index_size' => round($indexSize / 1024, 1),
                'total_size_kb' => round($totalSize / 1024, 1),
                'size_formatted' => $totalSize > 1048576 ? round($totalSize / 1048576, 2) . ' MB' : round($totalSize / 1024, 1) . ' KB',
                'created' => $t['Create_time'] ?? '',
                'updated' => $t['Update_time'] ?? ''
            ];
        }
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

// If browse table is selected
$browseRows = [];
$browseColumns = [];
$tableColumnsInfo = [];
$primaryKeyColumns = [];
$browseTotalRows = 0;
$browsePage = max(1, (int)($_GET['page'] ?? 1));
$browsePerPage = 25;
$browseOffset = ($browsePage - 1) * $browsePerPage;

if ($activeTab === 'browse' && $currentTable && $currentDb) {
    try {
        $dbPdo = get_db_connection($currentDb);
        $browseTotalRows = (int)$dbPdo->query("SELECT COUNT(*) FROM `$currentTable`")->fetchColumn();
        
        $tableColumnsInfo = $dbPdo->query("SHOW FULL COLUMNS FROM `$currentTable`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tableColumnsInfo as $ci) {
            if ($ci['Key'] === 'PRI') {
                $primaryKeyColumns[] = $ci['Field'];
            }
        }
        
        $stmt = $dbPdo->query("SELECT * FROM `$currentTable` LIMIT $browsePerPage OFFSET $browseOffset");
        $browseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($browseRows)) {
            $browseColumns = array_keys($browseRows[0]);
        } else {
            $browseColumns = array_column($tableColumnsInfo, 'Field');
        }
    } catch (Exception $e) {
        $queryError = $e->getMessage();
    }
}

// If structure table is selected
$structureColumns = [];
$structureIndexes = [];
$createTableSql = '';

if ($activeTab === 'structure' && $currentTable && $currentDb) {
    try {
        $dbPdo = get_db_connection($currentDb);
        $structureColumns = $dbPdo->query("SHOW FULL COLUMNS FROM `$currentTable`")->fetchAll(PDO::FETCH_ASSOC);
        $structureIndexes = $dbPdo->query("SHOW INDEX FROM `$currentTable`")->fetchAll(PDO::FETCH_ASSOC);
        $createRes = $dbPdo->query("SHOW CREATE TABLE `$currentTable`")->fetch(PDO::FETCH_ASSOC);
        $createTableSql = $createRes['Create Table'] ?? '';
    } catch (Exception $e) {
        $queryError = $e->getMessage();
    }
}

$pageTitle = 'MariaDB Database Manager';
$activeNav = 'database';
include __DIR__ . '/includes/header.php';
?>

<!-- DB Manager Layout Style Enhancements -->
<style>
    .db-badge-active {
        background: #eef2ff;
        color: #4338ca;
        border-color: #c7d2fe;
    }
    .db-tab-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 16px;
        font-size: 0.82rem;
        font-weight: 600;
        border-bottom: 2px solid transparent;
        color: #64748b;
        text-decoration: none;
        transition: all 0.15s ease;
        white-space: nowrap;
    }
    .db-tab-btn:hover {
        color: #0f172a;
        border-color: #cbd5e1;
    }
    .db-tab-btn.active {
        color: #4f46e5;
        border-color: #4f46e5;
        background: #ffffff;
    }
    .sql-code-editor {
        font-family: 'JetBrains Mono', monospace;
        background: #0f172a;
        color: #f8fafc;
        border-radius: 10px;
        padding: 14px;
        line-height: 1.5;
        resize: vertical;
    }
    .sql-code-editor:focus {
        outline: none;
        box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.4);
    }
    .mono-cell {
        font-family: 'JetBrains Mono', monospace;
    }
    /* SQL Terminal Themes */
    .sqlterm-theme-cyber { background-color: #0f141c !important; color: #f1f5f9 !important; }
    .sqlterm-theme-dracula { background-color: #282a36 !important; color: #f8f8f2 !important; }
    .sqlterm-theme-matrix { background-color: #051405 !important; color: #39ff14 !important; }
    .sqlterm-theme-solarized { background-color: #002b36 !important; color: #93a1a1 !important; }

    .sqlterm-fullscreen {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        z-index: 99999 !important;
        border-radius: 0 !important;
        margin: 0 !important;
    }
</style>

<!-- Top Toolbar Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">MariaDB Manager</h1>
            <span class="px-2 py-0.5 text-[11px] font-mono font-semibold rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
                Connected &bull; <?= htmlspecialchars($serverVersion) ?>
            </span>
        </div>
        <p class="text-xs text-gray-500 mt-1">
            Direct access to MariaDB engine on port 3306. You can also open 
            <a href="http://server.shriyashpatil.in:2555" target="_blank" class="text-indigo-600 hover:underline font-semibold inline-flex items-center gap-1">
                phpMyAdmin Panel &rarr;
            </a>
        </p>
    </div>

    <div class="flex items-center flex-wrap gap-2">
        <button onclick="openModal('createDbModal')" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white border border-gray-300 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 hover:border-gray-400 transition shadow-sm">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            New Database
        </button>

        <?php if ($currentDb): ?>
            <button onclick="openModal('createTableModal')" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white border border-indigo-200 rounded-lg text-xs font-semibold text-indigo-700 hover:bg-indigo-50 transition shadow-sm">
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                New Table
            </button>
        <?php endif; ?>

        <button onclick="openModal('importSqlModal')" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-indigo-600 rounded-lg text-xs font-semibold text-white hover:bg-indigo-700 transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            Import SQL Dump
        </button>
    </div>
</div>

<!-- Main Layout: Sidebar & Content Panel -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

    <!-- LEFT SIDEBAR: Database List (4 columns on lg) -->
    <div class="lg:col-span-4 space-y-4">
        
        <!-- Databases Card -->
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="p-3.5 bg-gray-50/75 border-b border-gray-100 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                    <span class="text-xs font-bold text-gray-700 uppercase tracking-wider">Databases</span>
                </div>
                <span class="text-xs font-mono font-medium text-gray-500 bg-white px-2 py-0.5 rounded border border-gray-200">
                    <?= count($allDbs) ?> Total
                </span>
            </div>

            <!-- Search Filter Input -->
            <div class="p-2.5 border-b border-gray-100 bg-white">
                <div class="relative">
                    <svg class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" id="dbSearchInput" onkeyup="filterDatabases()" placeholder="Filter databases..." class="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border border-gray-200 focus:outline-none focus:border-indigo-500 bg-gray-50 focus:bg-white transition">
                </div>
            </div>

            <!-- Database Scroll List -->
            <div class="p-2 space-y-1 max-h-[420px] overflow-y-auto" id="dbListContainer" style="scrollbar-width: thin;">
                <?php foreach ($allDbs as $db): ?>
                    <?php 
                        $isActive = ($db === $currentDb);
                        $isSys = in_array($db, $systemDbs);
                    ?>
                    <div class="db-item-row group flex items-center justify-between px-3 py-2 rounded-lg text-xs transition border <?= $isActive ? 'bg-indigo-50/80 border-indigo-200 text-indigo-900 font-semibold shadow-xs' : 'border-transparent text-gray-700 hover:bg-gray-50 hover:border-gray-100' ?>" data-db-name="<?= strtolower(htmlspecialchars($db)) ?>" data-is-sys="<?= $isSys ? '1' : '0' ?>">
                        <a href="/dashboard/database.php?db=<?= urlencode($db) ?>" class="flex items-center gap-2.5 min-w-0 flex-1 truncate">
                            <svg class="w-4 h-4 flex-shrink-0 <?= $isActive ? 'text-indigo-600' : 'text-gray-400 group-hover:text-gray-600' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                            <span class="truncate font-mono text-[12px]"><?= htmlspecialchars($db) ?></span>
                        </a>

                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <?php if ($isSys): ?>
                                <span class="text-[10px] text-gray-400 uppercase bg-gray-100 px-1.5 py-0.5 rounded font-mono font-medium">sys</span>
                            <?php else: ?>
                                <button type="button" onclick="openDropDbModal('<?= htmlspecialchars(addslashes($db)) ?>')" class="p-1 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition" title="Delete Database">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="p-2.5 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-[11px] text-gray-500">
                <label class="flex items-center gap-1.5 cursor-pointer">
                    <input type="checkbox" id="toggleSysDbsCheckbox" onchange="filterDatabases()" class="rounded text-indigo-600">
                    <span>Show system databases</span>
                </label>
            </div>
        </div>

        <!-- Connection Parameters Card -->
        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="flex items-center justify-between mb-3 pb-2 border-b border-gray-100">
                <h3 class="text-xs font-bold text-gray-700 uppercase tracking-wider flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Connection Specs
                </h3>
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" title="Connected"></span>
            </div>
            
            <div class="space-y-2 text-xs font-mono">
                <div class="flex items-center justify-between py-1 border-b border-gray-50">
                    <span class="text-gray-400">PHP Host:</span>
                    <span class="text-gray-900 font-semibold bg-gray-100 px-1.5 py-0.5 rounded">db</span>
                </div>
                <div class="flex items-center justify-between py-1 border-b border-gray-50">
                    <span class="text-gray-400">External Host:</span>
                    <span class="text-gray-900 bg-gray-100 px-1.5 py-0.5 rounded">127.0.0.1</span>
                </div>
                <div class="flex items-center justify-between py-1 border-b border-gray-50">
                    <span class="text-gray-400">Port:</span>
                    <span class="text-gray-900">3306</span>
                </div>
                <div class="flex items-center justify-between py-1 border-b border-gray-50">
                    <span class="text-gray-400">User:</span>
                    <span class="text-gray-900 font-bold">root</span>
                </div>
                <div class="flex items-center justify-between py-1">
                    <span class="text-gray-400">Password:</span>
                    <span class="text-gray-400 italic">none (empty)</span>
                </div>
            </div>
        </div>

    </div>

    <!-- RIGHT MAIN WORKSPACE: Database Inspector & Tools (8 columns on lg) -->
    <div class="lg:col-span-8 space-y-6">

        <?php if (!$currentDb): ?>
            <!-- Empty State: No Database Selected -->
            <div class="bg-white border border-gray-200 rounded-xl p-12 text-center shadow-sm">
                <div class="w-14 h-14 bg-indigo-50 rounded-full flex items-center justify-center text-indigo-600 mx-auto mb-4">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                </div>
                <h3 class="text-base font-bold text-gray-900">No Database Selected</h3>
                <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto">Select a database from the left sidebar or create a new database to get started.</p>
                <div class="mt-4">
                    <button onclick="openModal('createDbModal')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 transition shadow-sm">
                        Create New Database
                    </button>
                </div>
            </div>
        <?php else: ?>

            <!-- Selected DB Header Banner -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="p-4 sm:p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-gray-100">
                    <div class="flex items-center gap-3.5">
                        <div class="w-12 h-12 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 flex-shrink-0">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-bold text-gray-900 font-mono"><?= htmlspecialchars($currentDb) ?></h2>
                                <?php if ($currentTable): ?>
                                    <span class="text-gray-400 font-normal">/</span>
                                    <span class="text-indigo-600 font-mono font-bold text-sm bg-indigo-50 px-2 py-0.5 rounded border border-indigo-100"><?= htmlspecialchars($currentTable) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center flex-wrap gap-2 text-xs text-gray-500 mt-1">
                                <span><?= count($tableList) ?> tables</span>
                                <span>&bull;</span>
                                <span><?= number_format($dbTotalRows) ?> rows</span>
                                <span>&bull;</span>
                                <span><?= $dbTotalSizeBytes > 1048576 ? round($dbTotalSizeBytes / 1048576, 2) . ' MB' : round($dbTotalSizeBytes / 1024, 1) . ' KB' ?> total size</span>
                                <span>&bull;</span>
                                <span class="font-mono text-[11px] text-gray-400"><?= htmlspecialchars($dbCollation) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- DB Quick Actions -->
                    <div class="flex items-center flex-wrap gap-2">
                        <a href="/dashboard/database.php?action=export&db=<?= urlencode($currentDb) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-lg text-xs font-semibold transition shadow-xs">
                            <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Export SQL
                        </a>

                        <?php if (!in_array($currentDb, $systemDbs)): ?>
                            <button type="button" onclick="openDropDbModal('<?= htmlspecialchars(addslashes($currentDb)) ?>')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-200 hover:bg-red-100 text-red-700 rounded-lg text-xs font-semibold transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Drop DB
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Navigation Tabs Bar -->
                <div class="bg-gray-50/75 px-4 flex items-center gap-1 overflow-x-auto border-b border-gray-200">
                    <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&tab=tables" class="db-tab-btn <?= $activeTab === 'tables' ? 'active' : '' ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span>Tables (<?= count($tableList) ?>)</span>
                    </a>

                    <?php if ($currentTable): ?>
                        <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=browse" class="db-tab-btn <?= $activeTab === 'browse' ? 'active' : '' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <span>Browse (<?= htmlspecialchars($currentTable) ?>)</span>
                        </a>

                        <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=structure" class="db-tab-btn <?= $activeTab === 'structure' ? 'active' : '' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
                            <span>Structure</span>
                        </a>
                    <?php endif; ?>

                    <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&tab=query" class="db-tab-btn <?= $activeTab === 'query' ? 'active' : '' ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span>SQL Query Executor</span>
                    </a>

                    <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&tab=terminal" class="db-tab-btn <?= $activeTab === 'terminal' ? 'active' : '' ?>">
                        <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span>SQL Terminal</span>
                        <span class="px-1.5 py-0.2 bg-emerald-100 text-emerald-800 rounded font-mono text-[10px] font-bold">CLI</span>
                    </a>

                    <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&tab=operations" class="db-tab-btn <?= $activeTab === 'operations' ? 'active' : '' ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span>Operations</span>
                    </a>
                </div>
            </div>

            <!-- TAB CONTENT 1: TABLES OVERVIEW -->
            <?php if ($activeTab === 'tables'): ?>
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="p-3.5 bg-gray-50/75 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <h3 class="text-xs font-bold text-gray-700 uppercase tracking-wider">Database Tables</h3>
                            <span class="text-xs text-gray-400 font-mono">(<?= count($tableList) ?> found)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="relative">
                                <svg class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input type="text" id="tableFilterInput" onkeyup="filterTables()" placeholder="Filter tables..." class="pl-8 pr-3 py-1.5 text-xs rounded-lg border border-gray-200 focus:outline-none focus:border-indigo-500 bg-white">
                            </div>
                            <button onclick="openModal('createTableModal')" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold shadow-xs transition flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                Create Table
                            </button>
                        </div>
                    </div>

                    <?php if (empty($tableList)): ?>
                        <div class="p-12 text-center">
                            <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center text-gray-400 mx-auto mb-3">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                            <h4 class="text-sm font-bold text-gray-800">No Tables Found</h4>
                            <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto">Database <strong class="font-mono"><?= htmlspecialchars($currentDb) ?></strong> is currently empty.</p>
                            <div class="mt-4 flex items-center justify-center gap-2">
                                <button onclick="openModal('createTableModal')" class="px-3.5 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 transition">Create New Table</button>
                                <button onclick="openModal('importSqlModal')" class="px-3.5 py-1.5 bg-white border border-gray-300 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-50 transition">Import .SQL</button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-xs font-mono" id="tablesMainTable">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold">Table Name</th>
                                        <th class="px-3 py-3 text-left font-semibold">Engine</th>
                                        <th class="px-3 py-3 text-left font-semibold">Collation</th>
                                        <th class="px-3 py-3 text-right font-semibold">Est. Rows</th>
                                        <th class="px-3 py-3 text-right font-semibold">Data Size</th>
                                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100">
                                    <?php foreach ($tableList as $tb): ?>
                                        <tr class="hover:bg-gray-50 transition group table-row-item">
                                            <td class="px-4 py-3 text-gray-900 font-semibold flex items-center gap-2">
                                                <svg class="w-4 h-4 text-indigo-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tb['name']) ?>&tab=browse" class="hover:text-indigo-600 transition font-bold">
                                                    <?= htmlspecialchars($tb['name']) ?>
                                                </a>
                                            </td>
                                            <td class="px-3 py-3 text-gray-600"><?= htmlspecialchars($tb['engine']) ?></td>
                                            <td class="px-3 py-3 text-gray-400 text-[11px]"><?= htmlspecialchars($tb['collation']) ?></td>
                                            <td class="px-3 py-3 text-right font-bold text-gray-800"><?= number_format($tb['rows']) ?></td>
                                            <td class="px-3 py-3 text-right text-gray-500"><?= $tb['size_formatted'] ?></td>
                                            <td class="px-4 py-3 text-right whitespace-nowrap space-x-1">
                                                <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tb['name']) ?>&tab=browse" class="inline-flex items-center px-2 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded text-[11px] font-semibold transition" title="Browse table rows">
                                                    Browse
                                                </a>
                                                <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tb['name']) ?>&tab=structure" class="inline-flex items-center px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-[11px] font-semibold transition" title="Inspect columns and schema">
                                                    Structure
                                                </a>
                                                <a href="/dashboard/database.php?action=export&db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tb['name']) ?>" class="inline-flex items-center px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-[11px] font-semibold transition" title="Export as SQL">
                                                    Export
                                                </a>
                                                <button type="button" onclick="openTruncateTableModal('<?= htmlspecialchars(addslashes($tb['name'])) ?>')" class="inline-flex items-center px-2 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded text-[11px] font-semibold transition" title="Empty Table">
                                                    Truncate
                                                </button>
                                                <button type="button" onclick="openDropTableModal('<?= htmlspecialchars(addslashes($tb['name'])) ?>')" class="inline-flex items-center p-1 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition" title="Drop Table">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-gray-50 text-gray-600 font-bold border-t border-gray-200">
                                    <tr>
                                        <td class="px-4 py-2.5"><?= count($tableList) ?> Tables</td>
                                        <td class="px-3 py-2.5">-</td>
                                        <td class="px-3 py-2.5">-</td>
                                        <td class="px-3 py-2.5 text-right"><?= number_format($dbTotalRows) ?> rows</td>
                                        <td class="px-3 py-2.5 text-right"><?= $dbTotalSizeBytes > 1048576 ? round($dbTotalSizeBytes / 1048576, 2) . ' MB' : round($dbTotalSizeBytes / 1024, 1) . ' KB' ?></td>
                                        <td class="px-4 py-2.5 text-right"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- TAB CONTENT 2: BROWSE TABLE DATA -->
            <?php if ($activeTab === 'browse' && $currentTable): ?>
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="p-3.5 bg-gray-50/75 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-gray-700 uppercase tracking-wider">Browsing Table:</span>
                            <span class="text-xs font-mono font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200"><?= htmlspecialchars($currentTable) ?></span>
                            <span class="text-xs text-gray-400 font-mono">(<?= number_format($browseTotalRows) ?> total rows)</span>
                        </div>
                        <div class="flex items-center flex-wrap gap-2">
                            <button type="button" onclick="openInsertRowModal()" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold transition flex items-center gap-1.5 shadow-xs">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                <span>Insert Row</span>
                            </button>
                            <a href="/dashboard/database.php?action=export&db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&format=csv" class="px-2.5 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-lg text-xs font-semibold transition flex items-center gap-1 shadow-xs">
                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                Export CSV
                            </a>
                            <a href="/dashboard/database.php?action=export&db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>" class="px-2.5 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-lg text-xs font-semibold transition flex items-center gap-1 shadow-xs">
                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Export SQL
                            </a>
                            <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=structure" class="px-2.5 py-1.5 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-lg text-xs font-semibold transition flex items-center gap-1">
                                Structure &rarr;
                            </a>
                        </div>
                    </div>

                    <?php if (empty($browseRows)): ?>
                        <div class="p-10 text-center text-gray-400 text-xs">
                            <div class="mb-3 text-gray-300">
                                <svg class="w-8 h-8 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                            </div>
                            Table <strong class="text-gray-700 font-mono"><?= htmlspecialchars($currentTable) ?></strong> contains 0 rows.
                            <div class="mt-3">
                                <button type="button" onclick="openInsertRowModal()" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold transition">
                                    + Insert First Row
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto max-h-[600px] border-b border-gray-100" style="scrollbar-width: thin;">
                            <table class="min-w-full divide-y divide-gray-200 text-xs font-mono">
                                <thead class="bg-gray-50 sticky top-0 z-10 shadow-xs">
                                    <tr>
                                        <th class="px-3 py-2.5 text-center text-gray-600 font-bold w-20 bg-gray-50">Actions</th>
                                        <th class="px-3 py-2.5 text-left text-gray-400 font-bold w-10">#</th>
                                        <?php foreach ($browseColumns as $col): ?>
                                            <th class="px-4 py-2.5 text-left text-gray-700 font-semibold whitespace-nowrap bg-gray-50">
                                                <div class="flex items-center gap-1">
                                                    <?php if (in_array($col, $primaryKeyColumns)): ?>
                                                        <span class="text-amber-500 text-[11px]" title="Primary Key">&#128273;</span>
                                                    <?php endif; ?>
                                                    <span><?= htmlspecialchars($col) ?></span>
                                                </div>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100">
                                    <?php foreach ($browseRows as $idx => $r): ?>
                                        <?php
                                            $rowPk = [];
                                            if (!empty($primaryKeyColumns)) {
                                                foreach ($primaryKeyColumns as $pkCol) {
                                                    $rowPk[$pkCol] = $r[$pkCol] ?? null;
                                                }
                                            } else {
                                                $rowPk = $r;
                                            }
                                            $rowPkJson = json_encode($rowPk);
                                            $rowFullJson = json_encode($r);
                                        ?>
                                        <tr class="hover:bg-indigo-50/40 transition group">
                                            <td class="px-3 py-2 text-center whitespace-nowrap">
                                                <div class="flex items-center justify-center gap-1">
                                                    <button type="button" onclick="openEditRowModal(this)" data-row='<?= htmlspecialchars($rowFullJson, ENT_QUOTES) ?>' data-pk='<?= htmlspecialchars($rowPkJson, ENT_QUOTES) ?>' class="p-1 text-indigo-600 hover:text-indigo-800 hover:bg-indigo-100/60 rounded transition" title="Edit this row">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                    </button>
                                                    <button type="button" onclick="openDeleteRowModal(this)" data-pk='<?= htmlspecialchars($rowPkJson, ENT_QUOTES) ?>' class="p-1 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition" title="Delete this row">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-gray-400 text-[11px] select-none"><?= $browseOffset + $idx + 1 ?></td>
                                            <?php foreach ($browseColumns as $col): ?>
                                                <?php $val = $r[$col] ?? null; ?>
                                                <td class="px-4 py-2 text-gray-800 whitespace-nowrap max-w-xs truncate cursor-pointer" ondblclick="openEditRowModal(this.closest('tr').querySelector('button[data-row]'))" title="Double click to edit">
                                                    <?php if ($val === null): ?>
                                                        <span class="text-gray-400 italic bg-gray-100 px-1 py-0.5 rounded text-[10px]">NULL</span>
                                                    <?php elseif ($val === ''): ?>
                                                        <span class="text-gray-300 italic text-[10px]">&lt;empty&gt;</span>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars((string)$val) ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Footer -->
                        <?php 
                            $totalPages = max(1, ceil($browseTotalRows / $browsePerPage));
                        ?>
                        <div class="p-3 bg-gray-50 flex items-center justify-between text-xs text-gray-600">
                            <span>Showing <?= $browseOffset + 1 ?> to <?= min($browseTotalRows, $browseOffset + $browsePerPage) ?> of <?= number_format($browseTotalRows) ?> records</span>
                            <div class="flex items-center gap-1.5">
                                <?php if ($browsePage > 1): ?>
                                    <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=browse&page=<?= $browsePage - 1 ?>" class="px-2.5 py-1 bg-white border border-gray-300 rounded hover:bg-gray-100 text-gray-700 font-semibold">&larr; Previous</a>
                                <?php endif; ?>
                                <span class="px-2 font-mono font-bold">Page <?= $browsePage ?> / <?= $totalPages ?></span>
                                <?php if ($browsePage < $totalPages): ?>
                                    <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=browse&page=<?= $browsePage + 1 ?>" class="px-2.5 py-1 bg-white border border-gray-300 rounded hover:bg-gray-100 text-gray-700 font-semibold">Next &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- TAB CONTENT 3: TABLE STRUCTURE -->
            <?php if ($activeTab === 'structure' && $currentTable): ?>
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden space-y-6">
                    <div>
                        <div class="p-3.5 bg-gray-50/75 border-b border-gray-100 flex items-center justify-between">
                            <h3 class="text-xs font-bold text-gray-700 uppercase tracking-wider flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
                                Column Definitions
                            </h3>
                            <div class="flex items-center gap-2">
                                <a href="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=browse" class="text-xs text-indigo-600 hover:underline font-semibold flex items-center gap-1">
                                    <span>Browse Data &rarr;</span>
                                </a>
                                <span class="text-xs font-mono text-gray-400">|</span>
                                <span class="text-xs font-mono text-gray-500"><?= count($structureColumns) ?> columns</span>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-xs font-mono">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left font-semibold">Column Name</th>
                                        <th class="px-4 py-2.5 text-left font-semibold">Type</th>
                                        <th class="px-3 py-2.5 text-left font-semibold">Null</th>
                                        <th class="px-3 py-2.5 text-left font-semibold">Key</th>
                                        <th class="px-3 py-2.5 text-left font-semibold">Default</th>
                                        <th class="px-4 py-2.5 text-left font-semibold">Extra</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($structureColumns as $col): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-2.5 text-gray-900 font-bold flex items-center gap-1.5">
                                                <?php if ($col['Key'] === 'PRI'): ?>
                                                    <span class="text-amber-500" title="Primary Key">&#128273;</span>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($col['Field']) ?>
                                            </td>
                                            <td class="px-4 py-2.5 text-indigo-700 font-semibold"><?= htmlspecialchars($col['Type']) ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?= htmlspecialchars($col['Null']) ?></td>
                                            <td class="px-3 py-2.5">
                                                <?php if ($col['Key'] === 'PRI'): ?>
                                                    <span class="px-1.5 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded text-[10px] font-bold">PRIMARY</span>
                                                <?php elseif ($col['Key'] === 'UNI'): ?>
                                                    <span class="px-1.5 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded text-[10px] font-bold">UNIQUE</span>
                                                <?php elseif ($col['Key'] === 'MUL'): ?>
                                                    <span class="px-1.5 py-0.5 bg-gray-100 text-gray-700 rounded text-[10px]">INDEX</span>
                                                <?php else: ?>
                                                    <span class="text-gray-300">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2.5 text-gray-600"><?= $col['Default'] === null ? '<span class="text-gray-400 italic">NULL</span>' : htmlspecialchars($col['Default']) ?></td>
                                            <td class="px-4 py-2.5 text-gray-500"><?= htmlspecialchars($col['Extra']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Indexes List -->
                    <?php if (!empty($structureIndexes)): ?>
                        <div class="border-t border-gray-100 pt-4">
                            <div class="px-4 pb-2">
                                <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider">Indexes</h4>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-xs font-mono">
                                    <thead class="bg-gray-50 text-gray-600">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-semibold">Key Name</th>
                                            <th class="px-4 py-2 text-left font-semibold">Column</th>
                                            <th class="px-4 py-2 text-left font-semibold">Unique</th>
                                            <th class="px-4 py-2 text-left font-semibold">Type</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($structureIndexes as $idx): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-2 font-bold text-gray-900"><?= htmlspecialchars($idx['Key_name']) ?></td>
                                                <td class="px-4 py-2 text-indigo-600"><?= htmlspecialchars($idx['Column_name']) ?></td>
                                                <td class="px-4 py-2"><?= $idx['Non_unique'] == 0 ? '<span class="text-emerald-600 font-semibold">Yes</span>' : '<span class="text-gray-400">No</span>' ?></td>
                                                <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($idx['Index_type']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- CREATE TABLE Syntax Preview -->
                    <?php if ($createTableSql): ?>
                        <div class="border-t border-gray-100 p-4">
                            <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">CREATE TABLE Schema Definition</h4>
                            <pre class="p-3 bg-gray-900 text-gray-100 rounded-lg text-xs font-mono overflow-x-auto"><?= htmlspecialchars($createTableSql) ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- TAB CONTENT 4: SQL QUERY EXECUTOR -->
            <?php if ($activeTab === 'query'): ?>
                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <span>SQL Query Executor</span>
                        </h3>
                        <span class="text-xs text-gray-400 font-mono">Target: <strong class="text-gray-700"><?= htmlspecialchars($currentDb) ?></strong></span>
                    </div>

                    <form method="POST" action="/dashboard/database.php?db=<?= urlencode($currentDb) ?>&tab=query" id="sqlExecutorForm">
                        <input type="hidden" name="db" value="<?= htmlspecialchars($currentDb) ?>">
                        
                        <!-- Snippet Quick Buttons -->
                        <div class="flex items-center flex-wrap gap-1.5 mb-2">
                            <span class="text-[11px] text-gray-400 font-semibold mr-1">Snippets:</span>
                            <button type="button" onclick="insertSql('SELECT * FROM `<?= htmlspecialchars(addslashes($currentTable ?: ($tableList[0]['name'] ?? 'table_name'))) ?>` LIMIT 25;')" class="px-2 py-1 bg-gray-100 hover:bg-indigo-50 hover:text-indigo-600 rounded text-[11px] font-mono transition">SELECT *</button>
                            <button type="button" onclick="insertSql('SHOW TABLES;')" class="px-2 py-1 bg-gray-100 hover:bg-indigo-50 hover:text-indigo-600 rounded text-[11px] font-mono transition">SHOW TABLES</button>
                            <button type="button" onclick="insertSql('SHOW TABLE STATUS;')" class="px-2 py-1 bg-gray-100 hover:bg-indigo-50 hover:text-indigo-600 rounded text-[11px] font-mono transition">SHOW TABLE STATUS</button>
                            <button type="button" onclick="insertSql('DESCRIBE `<?= htmlspecialchars(addslashes($currentTable ?: ($tableList[0]['name'] ?? 'table_name'))) ?>`;')" class="px-2 py-1 bg-gray-100 hover:bg-indigo-50 hover:text-indigo-600 rounded text-[11px] font-mono transition">DESCRIBE</button>
                            <button type="button" onclick="insertSql('SHOW CREATE TABLE `<?= htmlspecialchars(addslashes($currentTable ?: ($tableList[0]['name'] ?? 'table_name'))) ?>`;')" class="px-2 py-1 bg-gray-100 hover:bg-indigo-50 hover:text-indigo-600 rounded text-[11px] font-mono transition">SHOW CREATE TABLE</button>
                            <button type="button" onclick="clearSql()" class="px-2 py-1 bg-gray-50 text-gray-400 hover:text-gray-700 rounded text-[11px] transition ml-auto">Clear</button>
                        </div>

                        <div class="mb-3">
                            <textarea name="sql" id="sqlQueryTextarea" rows="5" placeholder="SELECT * FROM `table_name` WHERE 1 LIMIT 50;" class="w-full sql-code-editor text-xs" required><?= htmlspecialchars($querySql) ?></textarea>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-[11px] text-gray-400">Tip: Press <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded font-mono text-[10px]">Ctrl+Enter</kbd> to execute</span>
                            <button type="submit" name="run_query" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold shadow-sm transition flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Run SQL Query
                            </button>
                        </div>
                    </form>

                    <!-- Query Result Output -->
                    <?php if ($queryError): ?>
                        <div class="mt-4 p-4 bg-rose-50 border border-rose-200 rounded-xl text-rose-800 text-xs font-mono space-y-1">
                            <div class="font-bold flex items-center gap-1.5 text-rose-700">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                MariaDB Execution Error:
                            </div>
                            <div class="pl-5 leading-relaxed"><?= htmlspecialchars($queryError) ?></div>
                            <div class="text-[11px] text-rose-500 pl-5 pt-1">Time elapsed: <?= $queryExecutionTime ?> ms</div>
                        </div>
                    <?php elseif ($queryResult !== null): ?>
                        <div class="mt-4 border-t border-gray-100 pt-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider">Query Results</h4>
                                    <span class="text-xs text-gray-400 font-mono">(<?= $queryExecutionTime ?> ms)</span>
                                </div>
                                <?php if (!empty($queryResult['rows'])): ?>
                                    <span class="text-xs font-mono font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                        <?= count($queryResult['rows']) ?> row(s) returned
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (isset($queryResult['affected'])): ?>
                                <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-800 text-xs font-mono flex items-center gap-2">
                                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>Query executed successfully. <strong><?= $queryResult['affected'] ?></strong> row(s) affected.</span>
                                </div>
                            <?php elseif (empty($queryResult['rows'])): ?>
                                <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg text-gray-500 text-xs italic text-center">
                                    Query returned an empty result set (0 records).
                                </div>
                            <?php else: ?>
                                <div class="overflow-x-auto border border-gray-200 rounded-xl max-h-96" style="scrollbar-width: thin;">
                                    <table class="min-w-full divide-y divide-gray-200 text-xs font-mono">
                                        <thead class="bg-gray-50 sticky top-0 z-10 shadow-xs">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-gray-400 font-bold w-10">#</th>
                                                <?php foreach ($queryResult['columns'] as $col): ?>
                                                    <th class="px-4 py-2.5 text-left text-gray-700 font-semibold whitespace-nowrap bg-gray-50"><?= htmlspecialchars($col) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-100">
                                            <?php foreach ($queryResult['rows'] as $idx => $r): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-2 text-gray-400 text-[11px] select-none"><?= $idx + 1 ?></td>
                                                    <?php foreach ($queryResult['columns'] as $col): ?>
                                                        <?php $val = $r[$col] ?? null; ?>
                                                        <td class="px-4 py-2 text-gray-800 whitespace-nowrap max-w-sm truncate">
                                                            <?php if ($val === null): ?>
                                                                <span class="text-gray-400 italic bg-gray-100 px-1 py-0.5 rounded text-[10px]">NULL</span>
                                                            <?php elseif ($val === ''): ?>
                                                                <span class="text-gray-300 italic text-[10px]">&lt;empty&gt;</span>
                                                            <?php else: ?>
                                                                <?= htmlspecialchars((string)$val) ?>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- TAB CONTENT: INTERACTIVE SQL TERMINAL -->
            <?php if ($activeTab === 'terminal'): ?>
                <div class="space-y-3">
                    <!-- Terminal Toolbar Controls -->
                    <div class="bg-white border border-gray-200 rounded-xl p-3 shadow-xs flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-bold text-gray-700 uppercase tracking-wider mr-1 flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                Database:
                            </span>
                            <select id="sqlTermDbSelect" onchange="switchSqlTerminalDb(this.value)" class="text-xs font-mono font-semibold bg-gray-50 border border-gray-300 rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-emerald-500 text-gray-800">
                                <?php foreach ($allDbs as $dbOpt): ?>
                                    <option value="<?= htmlspecialchars($dbOpt) ?>" <?= $dbOpt === $currentDb ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dbOpt) ?><?= in_array($dbOpt, $systemDbs) ? ' (system)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="h-4 w-px bg-gray-200 mx-1 hidden sm:block"></div>

                            <!-- Snippets / Quick Commands -->
                            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider hidden sm:inline">Snippets:</span>
                            <button type="button" onclick="runSqlTerminalCmd('SHOW TABLES;')" class="px-2.5 py-1 bg-gray-100 hover:bg-emerald-50 hover:text-emerald-700 border border-gray-200 rounded text-xs font-mono font-semibold transition cursor-pointer">SHOW TABLES;</button>
                            <button type="button" onclick="runSqlTerminalCmd('SHOW TABLE STATUS;')" class="px-2.5 py-1 bg-gray-100 hover:bg-emerald-50 hover:text-emerald-700 border border-gray-200 rounded text-xs font-mono font-semibold transition cursor-pointer">SHOW TABLE STATUS;</button>
                            <button type="button" onclick="runSqlTerminalCmd('SHOW DATABASES;')" class="px-2.5 py-1 bg-gray-100 hover:bg-emerald-50 hover:text-emerald-700 border border-gray-200 rounded text-xs font-mono font-semibold transition cursor-pointer">SHOW DATABASES;</button>
                            <button type="button" onclick="runSqlTerminalCmd('SHOW PROCESSLIST;')" class="px-2.5 py-1 bg-gray-100 hover:bg-emerald-50 hover:text-emerald-700 border border-gray-200 rounded text-xs font-mono font-semibold transition cursor-pointer">SHOW PROCESSLIST;</button>
                            <button type="button" onclick="runSqlTerminalCmd('SELECT VERSION(), NOW(), DATABASE();')" class="px-2.5 py-1 bg-gray-100 hover:bg-emerald-50 hover:text-emerald-700 border border-gray-200 rounded text-xs font-mono font-semibold transition cursor-pointer">INFO</button>
                            <button type="button" onclick="runSqlTerminalCmd('STATUS;')" class="px-2.5 py-1 bg-gray-100 hover:bg-emerald-50 hover:text-emerald-700 border border-gray-200 rounded text-xs font-mono font-semibold transition cursor-pointer">STATUS</button>
                            <button type="button" onclick="runSqlTerminalCmd('HELP;')" class="px-2.5 py-1 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 border border-emerald-200 rounded text-xs font-mono font-semibold transition cursor-pointer">HELP</button>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" onclick="clearSqlTerminal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 transition shadow-xs">
                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                <span>Clear</span>
                            </button>
                            <button type="button" onclick="copySqlTerminalOutput()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 transition shadow-xs">
                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                <span>Copy</span>
                            </button>
                            <button type="button" onclick="toggleSqlTerminalFullscreen()" id="btnFullscreenSqlTerm" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold transition shadow-xs">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                                <span id="sqlFullscreenBtnText">Fullscreen</span>
                            </button>
                        </div>
                    </div>

                    <!-- Main Terminal Screen Container -->
                    <div id="sqlTerminalContainer" class="bg-[#0f141c] border border-gray-800 rounded-2xl shadow-xl overflow-hidden flex flex-col transition-all duration-200" style="min-height: 520px; height: calc(100vh - 290px);">
                        <!-- Terminal Titlebar -->
                        <div class="bg-[#171d28] border-b border-gray-800/80 px-4 py-2.5 flex items-center justify-between gap-3 flex-shrink-0 select-none">
                            <div class="flex items-center gap-2">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-3 h-3 rounded-full bg-[#ff5f56] inline-block"></span>
                                    <span class="w-3 h-3 rounded-full bg-[#ffbd2e] inline-block"></span>
                                    <span class="w-3 h-3 rounded-full bg-[#27c93f] inline-block"></span>
                                </div>
                                <span class="ml-2 font-mono text-xs font-semibold text-gray-300 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                                    <span>mariadb:root@db [<span id="sqlTermTitleDb" class="text-emerald-300 font-bold"><?= htmlspecialchars($currentDb ?: 'none') ?></span>]</span>
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <select id="sqlTermThemeSelect" onchange="setSqlTerminalTheme(this.value)" class="bg-gray-800 text-gray-300 text-[11px] rounded px-2 py-1 border border-gray-700 focus:outline-none">
                                    <option value="cyber">Cyber Dark</option>
                                    <option value="dracula">Dracula</option>
                                    <option value="matrix">Matrix Green</option>
                                    <option value="solarized">Solarized Dark</option>
                                </select>
                                <button type="button" onclick="adjustSqlTermFont(-1)" class="w-6 h-6 flex items-center justify-center bg-gray-800 hover:bg-gray-700 text-gray-300 rounded text-xs font-bold" title="Decrease font size">A-</button>
                                <button type="button" onclick="adjustSqlTermFont(1)" class="w-6 h-6 flex items-center justify-center bg-gray-800 hover:bg-gray-700 text-gray-300 rounded text-xs font-bold" title="Increase font size">A+</button>
                            </div>
                        </div>

                        <!-- Terminal Screen Output -->
                        <div id="sqlTermScreen" class="flex-1 p-4 overflow-y-auto font-mono text-xs sm:text-sm leading-relaxed text-gray-200 select-text cursor-text" style="scrollbar-width: thin; scrollbar-color: #334155 #0f141c;">
                            <div class="text-emerald-400 font-bold mb-1 flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-400 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <span>MariaDB Interactive SQL Terminal CLI [<?= htmlspecialchars($serverVersion) ?>]</span>
                            </div>
                            <div class="text-gray-400 text-xs mb-3 space-y-0.5 border-b border-gray-800 pb-2">
                                <div>Type <span class="text-emerald-300 font-semibold font-mono">HELP</span> or <span class="text-emerald-300 font-semibold font-mono">\h</span> for MariaDB command reference. Type <span class="text-emerald-300 font-semibold font-mono">CLEAR</span> to clear screen.</div>
                                <div>Connected database: <strong id="sqlTermWelcomeDb" class="text-indigo-400"><?= htmlspecialchars($currentDb ?: '(none)') ?></strong>. Use <span class="text-emerald-300 font-semibold font-mono">USE &lt;dbname&gt;;</span> to switch database.</div>
                            </div>
                            
                            <div id="sqlTermHistory"></div>

                            <div id="sqlTermCurrentPromptRow" class="flex items-start gap-2 mt-2">
                                <span class="text-emerald-400 font-bold select-none flex-shrink-0" id="sqlPromptDisplay">mariadb [<span id="sqlPromptDbName" class="text-indigo-300"><?= htmlspecialchars($currentDb ?: 'none') ?></span>]&gt;</span>
                                <div class="flex-1 min-w-0 relative">
                                    <textarea id="sqlTermInput" rows="1" autocomplete="off" spellcheck="false" placeholder="Type SQL query (e.g. SHOW TABLES;) and press Enter" class="w-full bg-transparent text-gray-100 font-mono outline-none border-none p-0 m-0 text-xs sm:text-sm leading-relaxed caret-emerald-400 resize-none overflow-hidden"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Terminal Footer Status Bar -->
                        <div class="bg-[#121720] border-t border-gray-800 px-4 py-1.5 flex items-center justify-between text-[11px] font-mono text-gray-400 flex-shrink-0">
                            <div class="flex items-center gap-3">
                                <span class="flex items-center gap-1.5 text-emerald-400 font-semibold">
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                    CONNECTED
                                </span>
                                <span class="text-gray-600">|</span>
                                <span>Engine: <?= htmlspecialchars($serverVersion) ?></span>
                                <span class="text-gray-600">|</span>
                                <span id="sqlTermStatusText">Ready for SQL commands</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-gray-500 hidden sm:inline">Enter to execute &bull; Shift+Enter new line &bull; &uarr;&darr; history &bull; Tab autocomplete</span>
                                <span id="sqlTermDbBadge" class="text-emerald-300 font-semibold truncate max-w-xs"><?= htmlspecialchars($currentDb) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB CONTENT 5: DB OPERATIONS & MAINTENANCE -->
            <?php if ($activeTab === 'operations'): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Optimize & Maintain Card -->
                    <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm space-y-4">
                        <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                            <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <h3 class="text-sm font-bold text-gray-900">Database Optimization</h3>
                        </div>
                        <p class="text-xs text-gray-500 leading-relaxed">
                            Defragments storage and reclaims unused disk space across all <strong><?= count($tableList) ?></strong> tables in <span class="font-mono"><?= htmlspecialchars($currentDb) ?></span>.
                        </p>
                        <form method="POST" action="/dashboard/database.php">
                            <input type="hidden" name="db" value="<?= htmlspecialchars($currentDb) ?>">
                            <button type="submit" name="optimize_all_tables" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-sm transition">
                                Optimize All Tables
                            </button>
                        </form>
                    </div>

                    <!-- Export & Dump Card -->
                    <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm space-y-4">
                        <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                            <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            </div>
                            <h3 class="text-sm font-bold text-gray-900">Database SQL Backup</h3>
                        </div>
                        <p class="text-xs text-gray-500 leading-relaxed">
                            Download a full MariaDB SQL dump containing CREATE TABLE definitions and INSERT data.
                        </p>
                        <a href="/dashboard/database.php?action=export&db=<?= urlencode($currentDb) ?>" class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold shadow-sm transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download SQL Dump
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<!-- MODAL: Edit Row Modal -->
<div id="editRowModal" class="modal-backdrop">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <span>Edit Row in &lsquo;<span class="font-mono"><?= htmlspecialchars($currentTable) ?></span>&rsquo;</span>
            </div>
            <button type="button" onclick="closeModal('editRowModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/database.php">
            <input type="hidden" name="update_row_action" value="1">
            <input type="hidden" name="db" value="<?= htmlspecialchars($currentDb) ?>">
            <input type="hidden" name="table_name" value="<?= htmlspecialchars($currentTable) ?>">
            <input type="hidden" name="return_page" value="<?= $browsePage ?>">
            <input type="hidden" name="pk_data" id="editRowPkDataInput" value="">
            
            <div class="modal-body space-y-3" id="editRowModalFieldsContainer">
                <!-- Dynamically populated via JS -->
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('editRowModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Insert Row Modal -->
<div id="insertRowModal" class="modal-backdrop">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                <span>Insert New Row in &lsquo;<span class="font-mono"><?= htmlspecialchars($currentTable) ?></span>&rsquo;</span>
            </div>
            <button type="button" onclick="closeModal('insertRowModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/database.php">
            <input type="hidden" name="insert_row_action" value="1">
            <input type="hidden" name="db" value="<?= htmlspecialchars($currentDb) ?>">
            <input type="hidden" name="table_name" value="<?= htmlspecialchars($currentTable) ?>">
            
            <div class="modal-body space-y-3" id="insertRowModalFieldsContainer">
                <!-- Dynamically populated via JS -->
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('insertRowModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary">Insert Record</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Delete Row Confirmation Modal -->
<div id="deleteRowModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 440px;">
        <div class="modal-header">
            <div class="modal-title text-rose-600">
                <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                <span>Delete Row</span>
            </div>
            <button type="button" onclick="closeModal('deleteRowModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/database.php">
            <input type="hidden" name="delete_row_action" value="1">
            <input type="hidden" name="db" value="<?= htmlspecialchars($currentDb) ?>">
            <input type="hidden" name="table_name" value="<?= htmlspecialchars($currentTable) ?>">
            <input type="hidden" name="return_page" value="<?= $browsePage ?>">
            <input type="hidden" name="pk_data" id="deleteRowPkDataInput" value="">
            <div class="modal-body">
                <div class="bg-rose-50 border border-rose-200 rounded-lg p-3 text-xs text-rose-800 leading-relaxed">
                    Are you sure you want to delete this row? This action is permanent and cannot be undone.
                    <div id="deleteRowPkSummary" class="mt-2 font-mono text-[11px] bg-white p-2 rounded border border-rose-200 text-gray-700"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('deleteRowModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626; color: #fff; border: 1px solid #dc2626;">Delete Row</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Create Table Modal -->
<div id="createTableModal" class="modal-backdrop">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span>Create New Table in &lsquo;<?= htmlspecialchars($currentDb) ?>&rsquo;</span>
            </div>
            <button type="button" onclick="closeModal('createTableModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/database.php?db=<?= urlencode($currentDb) ?>">
            <input type="hidden" name="create_table_action" value="1">
            <input type="hidden" name="db" value="<?= htmlspecialchars($currentDb) ?>">
            
            <div class="modal-body space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="form-group">
                        <label class="form-label">Table Name</label>
                        <input type="text" name="table_name" class="form-input font-mono" placeholder="e.g. products, orders, users" required autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Storage Engine</label>
                        <select name="table_engine" class="form-select font-mono">
                            <option value="InnoDB" selected>InnoDB (Recommended, Foreign Keys, ACID)</option>
                            <option value="MyISAM">MyISAM (Fast read, non-transactional)</option>
                            <option value="MEMORY">MEMORY (In-RAM temporary)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="form-label mb-0">Column Definitions</label>
                        <button type="button" onclick="addColumnRow()" class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                            Add Column
                        </button>
                    </div>

                    <div class="border border-gray-200 rounded-lg overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-xs font-mono" id="tableColumnsDefTable">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-2.5 py-2 text-left font-semibold">Column Name</th>
                                    <th class="px-2.5 py-2 text-left font-semibold">Type</th>
                                    <th class="px-2.5 py-2 text-left font-semibold w-20">Length</th>
                                    <th class="px-2.5 py-2 text-center font-semibold w-16">Null</th>
                                    <th class="px-2.5 py-2 text-center font-semibold w-16">AI</th>
                                    <th class="px-2.5 py-2 text-center font-semibold w-16">Primary</th>
                                    <th class="px-2 py-2 text-center font-semibold w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white" id="columnsTableBody">
                                <!-- Row 1: ID -->
                                <tr>
                                    <td class="p-2"><input type="text" name="col_name[]" value="id" class="w-full text-xs p-1.5 border border-gray-200 rounded" required></td>
                                    <td class="p-2">
                                        <select name="col_type[]" class="w-full text-xs p-1.5 border border-gray-200 rounded">
                                            <option value="INT" selected>INT</option>
                                            <option value="BIGINT">BIGINT</option>
                                            <option value="VARCHAR">VARCHAR</option>
                                            <option value="TEXT">TEXT</option>
                                            <option value="DATETIME">DATETIME</option>
                                        </select>
                                    </td>
                                    <td class="p-2"><input type="text" name="col_length[]" value="11" class="w-full text-xs p-1.5 border border-gray-200 rounded"></td>
                                    <td class="p-2 text-center"><input type="checkbox" name="col_null[0]" value="1"></td>
                                    <td class="p-2 text-center"><input type="checkbox" name="col_ai[0]" value="1" checked></td>
                                    <td class="p-2 text-center"><input type="radio" name="col_primary" value="0" checked></td>
                                    <td class="p-2 text-center"></td>
                                </tr>
                                <!-- Row 2: Title / Name -->
                                <tr>
                                    <td class="p-2"><input type="text" name="col_name[]" value="name" class="w-full text-xs p-1.5 border border-gray-200 rounded" required></td>
                                    <td class="p-2">
                                        <select name="col_type[]" class="w-full text-xs p-1.5 border border-gray-200 rounded">
                                            <option value="VARCHAR" selected>VARCHAR</option>
                                            <option value="INT">INT</option>
                                            <option value="TEXT">TEXT</option>
                                            <option value="DATETIME">DATETIME</option>
                                            <option value="DECIMAL">DECIMAL</option>
                                        </select>
                                    </td>
                                    <td class="p-2"><input type="text" name="col_length[]" value="255" class="w-full text-xs p-1.5 border border-gray-200 rounded"></td>
                                    <td class="p-2 text-center"><input type="checkbox" name="col_null[1]" value="1"></td>
                                    <td class="p-2 text-center"><input type="checkbox" name="col_ai[1]" value="1"></td>
                                    <td class="p-2 text-center"><input type="radio" name="col_primary" value="1"></td>
                                    <td class="p-2 text-center"><button type="button" onclick="this.closest('tr').remove()" class="text-red-500 hover:text-red-700">&times;</button></td>
                                </tr>
                                <!-- Row 3: Created At -->
                                <tr>
                                    <td class="p-2"><input type="text" name="col_name[]" value="created_at" class="w-full text-xs p-1.5 border border-gray-200 rounded"></td>
                                    <td class="p-2">
                                        <select name="col_type[]" class="w-full text-xs p-1.5 border border-gray-200 rounded">
                                            <option value="TIMESTAMP" selected>TIMESTAMP</option>
                                            <option value="DATETIME">DATETIME</option>
                                            <option value="DATE">DATE</option>
                                            <option value="VARCHAR">VARCHAR</option>
                                        </select>
                                    </td>
                                    <td class="p-2"><input type="text" name="col_length[]" value="" class="w-full text-xs p-1.5 border border-gray-200 rounded"></td>
                                    <td class="p-2 text-center"><input type="checkbox" name="col_null[2]" value="1"></td>
                                    <td class="p-2 text-center"><input type="checkbox" name="col_ai[2]" value="1"></td>
                                    <td class="p-2 text-center"><input type="radio" name="col_primary" value="2"></td>
                                    <td class="p-2 text-center"><button type="button" onclick="this.closest('tr').remove()" class="text-red-500 hover:text-red-700">&times;</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('createTableModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Table</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Drop Table Confirmation Modal -->
<div id="dropTableModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 440px;">
        <div class="modal-header">
            <div class="modal-title text-rose-600">
                <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                <span>Drop Table</span>
            </div>
            <button type="button" onclick="closeModal('dropTableModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/database.php">
            <input type="hidden" name="drop_table_action" value="1">
            <input type="hidden" name="db" value="<?= htmlspecialchars($currentDb) ?>">
            <input type="hidden" name="table_name" id="dropTableTargetInput" value="">
            <div class="modal-body">
                <div class="bg-rose-50 border border-rose-200 rounded-lg p-3 text-xs text-rose-800 leading-relaxed">
                    Are you sure you want to drop table <strong id="dropTableNameDisplay" class="font-mono"></strong>? All schema definitions and data will be permanently destroyed.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('dropTableModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626; color: #fff; border: 1px solid #dc2626;">Drop Table</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Truncate Table Confirmation Modal -->
<div id="truncateTableModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 440px;">
        <div class="modal-header">
            <div class="modal-title text-amber-600">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                <span>Truncate (Empty) Table</span>
            </div>
            <button type="button" onclick="closeModal('truncateTableModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/database.php">
            <input type="hidden" name="truncate_table_action" value="1">
            <input type="hidden" name="db" value="<?= htmlspecialchars($currentDb) ?>">
            <input type="hidden" name="table_name" id="truncateTableTargetInput" value="">
            <div class="modal-body">
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 leading-relaxed">
                    Are you sure you want to empty table <strong id="truncateTableNameDisplay" class="font-mono"></strong>? All data rows will be deleted, resetting auto-increment counters.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('truncateTableModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn" style="background: #d97706; color: #fff; border: 1px solid #d97706;">Truncate Table</button>
            </div>
        </form>
    </div>
</div>

<script>
const currentTableColumns = <?= json_encode($tableColumnsInfo ?? []) ?>;
let colIndex = 3;

function filterDatabases() {
    const q = document.getElementById('dbSearchInput')?.value.toLowerCase() || '';
    const showSys = document.getElementById('toggleSysDbsCheckbox')?.checked || false;
    const items = document.querySelectorAll('.db-item-row');

    items.forEach(el => {
        const name = el.getAttribute('data-db-name') || '';
        const isSys = el.getAttribute('data-is-sys') === '1';
        
        let matchName = name.includes(q);
        let matchSys = showSys || !isSys;

        el.style.display = (matchName && matchSys) ? 'flex' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', filterDatabases);

function filterTables() {
    const q = document.getElementById('tableFilterInput')?.value.toLowerCase() || '';
    const rows = document.querySelectorAll('.table-row-item');
    rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        r.style.display = text.includes(q) ? '' : 'none';
    });
}

function insertSql(sql) {
    const area = document.getElementById('sqlQueryTextarea');
    if (area) {
        area.value = sql;
        area.focus();
    }
}

function clearSql() {
    const area = document.getElementById('sqlQueryTextarea');
    if (area) {
        area.value = '';
        area.focus();
    }
}

function openDropTableModal(tableName) {
    const input = document.getElementById('dropTableTargetInput');
    const display = document.getElementById('dropTableNameDisplay');
    if (input) input.value = tableName;
    if (display) display.textContent = tableName;
    openModal('dropTableModal');
}

function openTruncateTableModal(tableName) {
    const input = document.getElementById('truncateTableTargetInput');
    const display = document.getElementById('truncateTableNameDisplay');
    if (input) input.value = tableName;
    if (display) display.textContent = tableName;
    openModal('truncateTableModal');
}

function openEditRowModal(btn) {
    const rowJson = btn.getAttribute('data-row');
    const pkJson = btn.getAttribute('data-pk');
    if (!rowJson || !pkJson) return;

    const row = JSON.parse(rowJson);
    const pk = JSON.parse(pkJson);

    document.getElementById('editRowPkDataInput').value = pkJson;
    const container = document.getElementById('editRowModalFieldsContainer');
    container.innerHTML = '';

    currentTableColumns.forEach((col, idx) => {
        const fieldName = col.Field;
        const val = row[fieldName];
        const isNull = (val === null);
        const type = (col.Type || '').toLowerCase();
        const isPri = col.Key === 'PRI';
        const isTextarea = type.includes('text') || type.includes('json') || type.includes('blob');

        const wrapper = document.createElement('div');
        wrapper.className = 'p-3 bg-gray-50/70 border border-gray-200 rounded-lg';

        let inputHtml = '';
        if (isTextarea) {
            inputHtml = `<textarea name="row_data[${escapeHtml(fieldName)}]" id="edit_field_${idx}" rows="3" class="w-full form-input font-mono text-xs ${isNull ? 'bg-gray-100' : ''}" ${isNull ? 'disabled' : ''}>${escapeHtml(val || '')}</textarea>`;
        } else {
            inputHtml = `<input type="text" name="row_data[${escapeHtml(fieldName)}]" id="edit_field_${idx}" value="${escapeHtml(val !== null ? String(val) : '')}" class="form-input font-mono text-xs ${isNull ? 'bg-gray-100' : ''}" ${isNull ? 'disabled' : ''}>`;
        }

        wrapper.innerHTML = `
            <div class="flex items-center justify-between mb-1.5">
                <label class="text-xs font-bold text-gray-800 font-mono flex items-center gap-1.5">
                    ${isPri ? '<span class="text-amber-500" title="Primary Key">&#128273;</span>' : ''}
                    <span>${escapeHtml(fieldName)}</span>
                    <span class="text-[10px] font-normal text-gray-400">(${escapeHtml(col.Type)})</span>
                </label>
                <label class="text-xs text-gray-500 flex items-center gap-1 cursor-pointer select-none">
                    <input type="checkbox" name="row_null[${escapeHtml(fieldName)}]" value="1" ${isNull ? 'checked' : ''} onchange="toggleFieldNull('edit_field_${idx}', this.checked)" class="rounded text-indigo-600">
                    <span class="text-[11px] font-mono">NULL</span>
                </label>
            </div>
            ${inputHtml}
        `;
        container.appendChild(wrapper);
    });

    openModal('editRowModal');
}

function openInsertRowModal() {
    const container = document.getElementById('insertRowModalFieldsContainer');
    container.innerHTML = '';

    currentTableColumns.forEach((col, idx) => {
        const fieldName = col.Field;
        const type = (col.Type || '').toLowerCase();
        const isPri = col.Key === 'PRI';
        const isAi = (col.Extra || '').toLowerCase().includes('auto_increment');
        const isTextarea = type.includes('text') || type.includes('json') || type.includes('blob');
        const defaultVal = col.Default !== null ? col.Default : '';

        const wrapper = document.createElement('div');
        wrapper.className = 'p-3 bg-gray-50/70 border border-gray-200 rounded-lg';

        let inputHtml = '';
        if (isTextarea) {
            inputHtml = `<textarea name="row_data[${escapeHtml(fieldName)}]" id="insert_field_${idx}" rows="3" class="w-full form-input font-mono text-xs" placeholder="${isAi ? 'Auto-increment' : ''}">${escapeHtml(defaultVal)}</textarea>`;
        } else {
            inputHtml = `<input type="text" name="row_data[${escapeHtml(fieldName)}]" id="insert_field_${idx}" value="${escapeHtml(defaultVal)}" class="form-input font-mono text-xs" placeholder="${isAi ? 'Auto-generated ID (leave empty)' : ''}">`;
        }

        wrapper.innerHTML = `
            <div class="flex items-center justify-between mb-1.5">
                <label class="text-xs font-bold text-gray-800 font-mono flex items-center gap-1.5">
                    ${isPri ? '<span class="text-amber-500" title="Primary Key">&#128273;</span>' : ''}
                    <span>${escapeHtml(fieldName)}</span>
                    <span class="text-[10px] font-normal text-gray-400">(${escapeHtml(col.Type)})</span>
                    ${isAi ? '<span class="text-[9px] bg-indigo-50 text-indigo-600 px-1 py-0.2 rounded font-semibold">AUTO_INC</span>' : ''}
                </label>
                <label class="text-xs text-gray-500 flex items-center gap-1 cursor-pointer select-none">
                    <input type="checkbox" name="row_null[${escapeHtml(fieldName)}]" value="1" onchange="toggleFieldNull('insert_field_${idx}', this.checked)" class="rounded text-indigo-600">
                    <span class="text-[11px] font-mono">NULL</span>
                </label>
            </div>
            ${inputHtml}
        `;
        container.appendChild(wrapper);
    });

    openModal('insertRowModal');
}

function openDeleteRowModal(btn) {
    const pkJson = btn.getAttribute('data-pk');
    if (!pkJson) return;

    document.getElementById('deleteRowPkDataInput').value = pkJson;
    const pkObj = JSON.parse(pkJson);
    const summary = Object.entries(pkObj).map(([k, v]) => `${k} = ${v}`).join(', ');
    document.getElementById('deleteRowPkSummary').textContent = summary || 'Selected record';

    openModal('deleteRowModal');
}

function toggleFieldNull(fieldId, isNull) {
    const input = document.getElementById(fieldId);
    if (input) {
        input.disabled = isNull;
        if (isNull) {
            input.classList.add('bg-gray-100');
        } else {
            input.classList.remove('bg-gray-100');
            input.focus();
        }
    }
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function addColumnRow() {
    const tbody = document.getElementById('columnsTableBody');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="p-2"><input type="text" name="col_name[]" placeholder="col_name" class="w-full text-xs p-1.5 border border-gray-200 rounded" required></td>
        <td class="p-2">
            <select name="col_type[]" class="w-full text-xs p-1.5 border border-gray-200 rounded">
                <option value="VARCHAR">VARCHAR</option>
                <option value="INT">INT</option>
                <option value="TEXT">TEXT</option>
                <option value="DATETIME">DATETIME</option>
                <option value="DATE">DATE</option>
                <option value="DECIMAL">DECIMAL</option>
                <option value="TINYINT">TINYINT</option>
                <option value="JSON">JSON</option>
            </select>
        </td>
        <td class="p-2"><input type="text" name="col_length[]" placeholder="255" class="w-full text-xs p-1.5 border border-gray-200 rounded"></td>
        <td class="p-2 text-center"><input type="checkbox" name="col_null[${colIndex}]" value="1"></td>
        <td class="p-2 text-center"><input type="checkbox" name="col_ai[${colIndex}]" value="1"></td>
        <td class="p-2 text-center"><input type="radio" name="col_primary" value="${colIndex}"></td>
        <td class="p-2 text-center"><button type="button" onclick="this.closest('tr').remove()" class="text-red-500 hover:text-red-700 font-bold">&times;</button></td>
    `;
    tbody.appendChild(tr);
    colIndex++;
}

// Shortcut Ctrl+Enter to submit SQL executor
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const form = document.getElementById('sqlExecutorForm');
        const textarea = document.getElementById('sqlQueryTextarea');
        if (form && textarea && document.activeElement === textarea) {
            e.preventDefault();
            form.submit();
        }
    }
});

// --- Interactive SQL Terminal Logic ---
let sqlCmdHistory = JSON.parse(localStorage.getItem('sql_terminal_history') || '[]');
let sqlHistoryIndex = -1;
let currentTerminalDb = <?= json_encode($currentDb) ?>;
let isSqlExecuting = false;
let sqlFontSize = parseInt(localStorage.getItem('sql_terminal_font_size') || '13');

const sqlKeywords = [
    'SELECT', 'FROM', 'WHERE', 'INSERT INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE FROM',
    'CREATE TABLE', 'DROP TABLE', 'ALTER TABLE', 'ADD COLUMN', 'DROP COLUMN',
    'SHOW DATABASES;', 'SHOW TABLES;', 'SHOW PROCESSLIST;', 'SHOW TABLE STATUS;', 'SHOW CREATE TABLE',
    'DESCRIBE', 'EXPLAIN', 'LIMIT', 'ORDER BY', 'GROUP BY', 'HAVING', 'JOIN', 'LEFT JOIN', 'INNER JOIN',
    'USE', 'STATUS;', 'HELP;', 'CLEAR', 'COUNT(*)', 'DISTINCT', 'TRUNCATE TABLE', 'OPTIMIZE TABLE'
];

function initSqlTerminal() {
    const input = document.getElementById('sqlTermInput');
    const screen = document.getElementById('sqlTermScreen');
    if (!input || !screen) return;

    applySqlTerminalFontSize();

    // Auto-focus input when clicking terminal screen
    screen.addEventListener('click', function(e) {
        if (window.getSelection().toString().length === 0) {
            input.focus();
        }
    });

    // Auto-adjust textarea height
    input.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 180) + 'px';
    });

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            const cmd = input.value.trim();
            if (cmd) {
                executeSqlTerminalCommand(cmd);
            }
        } else if (e.key === 'ArrowUp') {
            if (sqlCmdHistory.length > 0 && input.selectionStart === 0) {
                e.preventDefault();
                if (sqlHistoryIndex === -1) {
                    sqlHistoryIndex = sqlCmdHistory.length - 1;
                } else if (sqlHistoryIndex > 0) {
                    sqlHistoryIndex--;
                }
                input.value = sqlCmdHistory[sqlHistoryIndex] || '';
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 180) + 'px';
            }
        } else if (e.key === 'ArrowDown') {
            if (sqlHistoryIndex !== -1) {
                e.preventDefault();
                if (sqlHistoryIndex < sqlCmdHistory.length - 1) {
                    sqlHistoryIndex++;
                    input.value = sqlCmdHistory[sqlHistoryIndex] || '';
                } else {
                    sqlHistoryIndex = -1;
                    input.value = '';
                }
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 180) + 'px';
            }
        } else if (e.key === 'Tab') {
            e.preventDefault();
            autocompleteSqlInput(input);
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'l') {
            e.preventDefault();
            clearSqlTerminal();
        }
    });
}

function autocompleteSqlInput(input) {
    const val = input.value;
    const cursorPos = input.selectionStart;
    const textBefore = val.slice(0, cursorPos);
    const words = textBefore.split(/\s+/);
    const currentWord = words[words.length - 1];

    if (!currentWord) return;

    // Available autocomplete candidates: SQL keywords + current table names + database names
    const tables = <?= json_encode(array_column($tableList, 'name')) ?> || [];
    const dbs = <?= json_encode($allDbs) ?> || [];
    const candidates = [...sqlKeywords, ...tables, ...dbs];

    const match = candidates.find(c => c.toLowerCase().startsWith(currentWord.toLowerCase()) && c.toLowerCase() !== currentWord.toLowerCase());
    if (match) {
        const replaceLen = currentWord.length;
        const newVal = val.slice(0, cursorPos - replaceLen) + match + ' ' + val.slice(cursorPos);
        input.value = newVal;
        const newPos = cursorPos - replaceLen + match.length + 1;
        input.setSelectionRange(newPos, newPos);
    }
}

function executeSqlTerminalCommand(cmd) {
    if (isSqlExecuting) return;
    const input = document.getElementById('sqlTermInput');
    const historyContainer = document.getElementById('sqlTermHistory');
    const screen = document.getElementById('sqlTermScreen');
    const statusText = document.getElementById('sqlTermStatusText');

    if (!cmd.trim()) return;

    // Save to history
    if (!sqlCmdHistory.length || sqlCmdHistory[sqlCmdHistory.length - 1] !== cmd) {
        sqlCmdHistory.push(cmd);
        if (sqlCmdHistory.length > 100) sqlCmdHistory.shift();
        localStorage.setItem('sql_terminal_history', JSON.stringify(sqlCmdHistory));
    }
    sqlHistoryIndex = -1;

    // Append Prompt Entry in History
    const promptEntry = document.createElement('div');
    promptEntry.className = 'mb-1';
    promptEntry.innerHTML = `
        <div class="flex items-start gap-2 mt-2">
            <span class="text-emerald-400 font-bold select-none flex-shrink-0">mariadb [<span class="text-indigo-300">${escapeHtml(currentTerminalDb || 'none')}</span>]&gt;</span>
            <span class="text-gray-100 font-mono whitespace-pre-wrap flex-1 break-words">${escapeHtml(cmd)}</span>
        </div>
    `;
    historyContainer.appendChild(promptEntry);

    // Clear input
    input.value = '';
    input.style.height = 'auto';
    if (statusText) statusText.textContent = 'Executing query...';
    isSqlExecuting = true;

    // Scroll to bottom
    screen.scrollTop = screen.scrollHeight;

    // Send AJAX request
    const formData = new FormData();
    formData.append('exec_sql_terminal', '1');
    formData.append('sql', cmd);
    formData.append('db', currentTerminalDb);

    fetch('/dashboard/database.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        isSqlExecuting = false;
        if (statusText) statusText.textContent = 'Ready for SQL commands';

        if (res.action === 'clear') {
            clearSqlTerminal();
            return;
        }

        if (res.db && res.db !== currentTerminalDb) {
            currentTerminalDb = res.db;
            updateSqlTerminalDbDisplay(res.db);
        }

        const resultEntry = document.createElement('div');
        resultEntry.className = 'mb-3 pl-2';

        if (res.success) {
            resultEntry.innerHTML = `<pre class="whitespace-pre overflow-x-auto text-emerald-300 font-mono text-xs my-1 selection:bg-emerald-900" style="scrollbar-width: thin;">${escapeHtml(res.output)}</pre>`;
        } else {
            resultEntry.innerHTML = `<pre class="whitespace-pre overflow-x-auto text-rose-400 font-mono text-xs my-1 selection:bg-rose-900" style="scrollbar-width: thin;">${escapeHtml(res.output)}</pre>`;
        }
        historyContainer.appendChild(resultEntry);
        screen.scrollTop = screen.scrollHeight;
        input.focus();
    })
    .catch(err => {
        isSqlExecuting = false;
        if (statusText) statusText.textContent = 'Error executing command';
        const errEntry = document.createElement('div');
        errEntry.className = 'mb-3 pl-2';
        errEntry.innerHTML = `<pre class="whitespace-pre overflow-x-auto text-rose-400 font-mono text-xs my-1">Network/Server Error: ${escapeHtml(err.message)}</pre>`;
        historyContainer.appendChild(errEntry);
        screen.scrollTop = screen.scrollHeight;
        input.focus();
    });
}

function updateSqlTerminalDbDisplay(dbName) {
    const titleDb = document.getElementById('sqlTermTitleDb');
    const promptDb = document.getElementById('sqlPromptDbName');
    const welcomeDb = document.getElementById('sqlTermWelcomeDb');
    const badgeDb = document.getElementById('sqlTermDbBadge');
    const dbSelect = document.getElementById('sqlTermDbSelect');

    if (titleDb) titleDb.textContent = dbName || 'none';
    if (promptDb) promptDb.textContent = dbName || 'none';
    if (welcomeDb) welcomeDb.textContent = dbName || '(none)';
    if (badgeDb) badgeDb.textContent = dbName || 'none';
    if (dbSelect && dbName) dbSelect.value = dbName;
}

function switchSqlTerminalDb(newDb) {
    if (!newDb) return;
    executeSqlTerminalCommand(`USE \`${newDb}\`;`);
}

function runSqlTerminalCmd(cmd) {
    const input = document.getElementById('sqlTermInput');
    if (input) {
        input.value = cmd;
        executeSqlTerminalCommand(cmd);
    }
}

function clearSqlTerminal() {
    const history = document.getElementById('sqlTermHistory');
    if (history) history.innerHTML = '';
    const input = document.getElementById('sqlTermInput');
    if (input) {
        input.value = '';
        input.style.height = 'auto';
        input.focus();
    }
}

function copySqlTerminalOutput() {
    const history = document.getElementById('sqlTermHistory');
    if (!history) return;
    const text = history.innerText || history.textContent;
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.currentTarget;
        const orig = btn.innerHTML;
        btn.innerHTML = '<span>Copied!</span>';
        setTimeout(() => btn.innerHTML = orig, 1500);
    });
}

function toggleSqlTerminalFullscreen() {
    const container = document.getElementById('sqlTerminalContainer');
    const btnText = document.getElementById('sqlFullscreenBtnText');
    if (!container) return;

    container.classList.toggle('sqlterm-fullscreen');
    if (container.classList.contains('sqlterm-fullscreen')) {
        if (btnText) btnText.textContent = 'Exit Fullscreen';
    } else {
        if (btnText) btnText.textContent = 'Fullscreen';
    }
    const input = document.getElementById('sqlTermInput');
    if (input) input.focus();
}

function setSqlTerminalTheme(themeName) {
    const container = document.getElementById('sqlTerminalContainer');
    if (!container) return;
    container.classList.remove('sqlterm-theme-cyber', 'sqlterm-theme-dracula', 'sqlterm-theme-matrix', 'sqlterm-theme-solarized');
    container.classList.add(`sqlterm-theme-${themeName}`);
    localStorage.setItem('sql_terminal_theme', themeName);
}

function adjustSqlTermFont(delta) {
    sqlFontSize = Math.max(10, Math.min(22, sqlFontSize + delta));
    localStorage.setItem('sql_terminal_font_size', sqlFontSize);
    applySqlTerminalFontSize();
}

function applySqlTerminalFontSize() {
    const screen = document.getElementById('sqlTermScreen');
    if (screen) {
        screen.style.fontSize = `${sqlFontSize}px`;
    }
}

// Auto init on page load
document.addEventListener('DOMContentLoaded', function() {
    initSqlTerminal();
    const savedTheme = localStorage.getItem('sql_terminal_theme');
    if (savedTheme) {
        const themeSelect = document.getElementById('sqlTermThemeSelect');
        if (themeSelect) themeSelect.value = savedTheme;
        setSqlTerminalTheme(savedTheme);
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
