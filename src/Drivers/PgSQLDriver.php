<?php

namespace aktolu\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class PgSQLDriver implements DriverInterface
{
    private PDO $pdo;
    private string $dbName;
    private string $prefix = '';
    private ?array $prefixReplace = null;
    private array $identifiers = [];

    /**
     * PgSQLDriver Constructor
     */
    public function __construct(
        string $user,
        string $pass,
        string $dbName,
        string $host = 'localhost',
        int $port = 5432,
        array $options = []
    ) {
        $this->dbName = $dbName;
        
        $charset = $options['charset'] ?? 'utf8';
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};options='--client_encoding={$charset}'";
        
        $pdoAttr = $options['pdo_attributes'] ?? [];
        $defaultAttr = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        $attributes = $pdoAttr + $defaultAttr;

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $attributes);
        } catch (PDOException $e) {
            throw new RuntimeException("PostgreSQL connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Backup the database
     */
    public function backup(string $destinationFilePath, array $options = []): bool
    {
        $compress = $options['compress'] ?? str_ends_with(strtolower($destinationFilePath), '.gz');

        $this->prefix = $options['prefix'] ?? '';
        $this->prefixReplace = $options['prefix_replace'] ?? null;
        if ($this->prefix !== '' || $this->prefixReplace !== null) {
            $this->identifiers = $this->gatherIdentifiers($options);
        } else {
            $this->identifiers = [];
        }

        try {
            $handle = $this->open($destinationFilePath, 'w9', $compress);
        } catch (RuntimeException $e) {
            return false;
        }

        try {
            // Write standard header info
            $header = "-- PHP DB Backup (PostgreSQL)\n" .
                      "-- Host: " . $this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS) . "\n" .
                      "-- Generation Time: " . date('Y-m-d H:i:s') . "\n" .
                      "-- Database: `{$this->dbName}`\n" .
                      "-- PHP Version: " . PHP_VERSION . "\n\n" .
                      "SET session_replication_role = 'replica';\n\n";
            $this->write($handle, $header, $compress);

            $tables = $this->getTablesToBackup($options);
            $tables = $this->sortTablesTopologically($tables);
            $includeStructure = $options['include_structure'] ?? true;
            $includeData = $options['include_data'] ?? true;
            $sequenceResets = [];

            foreach ($tables as $table) {
                // Get Columns list (checking for generated columns dynamically)
                $stmt = $this->pdo->prepare("
                    SELECT * 
                    FROM information_schema.columns 
                    WHERE table_schema = 'public' AND table_name = :table 
                    ORDER BY ordinal_position
                ");
                $stmt->execute(['table' => $table]);
                $columnsInfo = $stmt->fetchAll();

                $backupCols = [];
                $colDefs = [];

                foreach ($columnsInfo as $col) {
                    $isGen = isset($col['is_generated']) && $col['is_generated'] === 'ALWAYS';
                    if ($isGen) {
                        continue; // Skip generated column
                    }

                    $name = $col['column_name'];
                    $backupCols[] = $name;

                    if ($includeStructure) {
                        $type = $col['data_type'];
                        $maxLength = $col['character_maximum_length'];
                        $nullable = $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
                        $defaultVal = $col['column_default'];

                        $default = '';
                        $isSerial = false;
                        if ($defaultVal !== null) {
                            if (str_contains(strtolower($defaultVal), 'nextval')) {
                                $isSerial = true;
                            } else {
                                $default = " DEFAULT " . $defaultVal;
                            }
                        }

                        // Parse standard PGSQL Serial types
                        if ($isSerial) {
                            if (str_contains(strtolower($type), 'bigint')) {
                                $type = 'BIGSERIAL';
                            } else {
                                $type = 'SERIAL';
                            }
                            $sequenceResets[] = "SELECT setval(pg_get_serial_sequence('\"{$table}\"', '{$name}'), COALESCE(MAX(\"{$name}\"), 1)) FROM \"{$table}\";";
                        } else {
                            if ($maxLength) {
                                $type = "{$type}({$maxLength})";
                            }
                        }

                        $colDefs[] = "    \"{$name}\" {$type} {$nullable}{$default}";
                    }
                }

                // Export Structure
                if ($includeStructure) {
                    $this->write($handle, "\n--\n-- Table structure for table \"{$table}\"\n--\n\n", $compress);
                    $this->write($handle, "DROP TABLE IF EXISTS \"{$table}\" CASCADE;\n", $compress);

                    // Fetch constraints
                    $stmtCon = $this->pdo->prepare("
                        SELECT conname, pg_get_constraintdef(c.oid) AS def
                        FROM pg_constraint c
                        JOIN pg_class t ON c.conrelid = t.oid
                        JOIN pg_namespace n ON t.relnamespace = n.oid
                        WHERE n.nspname = 'public' AND t.relname = :table
                    ");
                    $stmtCon->execute(['table' => $table]);
                    $constraints = $stmtCon->fetchAll();

                    foreach ($constraints as $con) {
                        $colDefs[] = "    CONSTRAINT \"{$con['conname']}\" {$con['def']}";
                    }

                    $createTableSql = "CREATE TABLE \"{$table}\" (\n" . implode(",\n", $colDefs) . "\n);\n\n";
                    $this->write($handle, $createTableSql, $compress);
                }

                // Export Data
                if ($includeData && !empty($backupCols)) {
                    $this->writeTableData($handle, $table, $backupCols, $options, $compress);
                }
            }

            // Export Views
            $stmtViews = $this->pdo->query("SELECT table_name FROM information_schema.views WHERE table_schema = 'public'");
            $allViews = $stmtViews->fetchAll(PDO::FETCH_COLUMN);
            $excludeTables = $options['exclude_tables'] ?? [];
            if (!empty($excludeTables)) {
                $allViews = array_diff($allViews, $excludeTables);
            }
            if ($includeStructure && !empty($allViews)) {
                $this->write($handle, "\n--\n-- Views\n--\n\n", $compress);
                foreach ($allViews as $view) {
                    $this->write($handle, "DROP VIEW IF EXISTS \"{$view}\" CASCADE;\n", $compress);
                    $stmtViewDef = $this->pdo->prepare("SELECT pg_get_viewdef(:view)");
                    $stmtViewDef->execute(['view' => $view]);
                    $viewSelect = $stmtViewDef->fetchColumn();
                    if (!empty($viewSelect)) {
                        $createViewSql = "CREATE OR REPLACE VIEW \"{$view}\" AS\n" . trim($viewSelect);
                        if (!str_ends_with($createViewSql, ';')) {
                            $createViewSql .= ';';
                        }
                        $this->write($handle, $createViewSql . "\n\n", $compress);
                    }
                }
            }

            // Export Functions and Procedures
            if ($includeStructure) {
                try {
                    $stmtRoutines = $this->pdo->query("
                        SELECT p.proname, pg_get_functiondef(p.oid) AS def, 
                               CASE WHEN p.prokind = 'p' THEN 'PROCEDURE' ELSE 'FUNCTION' END as type
                        FROM pg_proc p
                        JOIN pg_namespace n ON p.pronamespace = n.oid
                        WHERE n.nspname = 'public'
                    ");
                    $routines = $stmtRoutines->fetchAll();
                    if (!empty($routines)) {
                        $this->write($handle, "\n--\n-- Functions and Procedures\n--\n\n", $compress);
                        foreach ($routines as $routine) {
                            $name = $routine['proname'];
                            $type = $routine['type'];
                            $this->write($handle, "DROP {$type} IF EXISTS \"{$name}\" CASCADE;\n", $compress);
                            $createSql = trim($routine['def']);
                            if (!str_ends_with($createSql, ';')) {
                                $createSql .= ';';
                            }
                            $this->write($handle, $createSql . "\n\n", $compress);
                        }
                    }
                } catch (\Exception $e) {
                    // Fallback for older PGSQL versions where prokind does not exist
                    try {
                        $stmtRoutines = $this->pdo->query("
                            SELECT p.proname, pg_get_functiondef(p.oid) AS def, 'FUNCTION' as type
                            FROM pg_proc p
                            JOIN pg_namespace n ON p.pronamespace = n.oid
                            WHERE n.nspname = 'public' AND p.proisagg = false
                        ");
                        $routines = $stmtRoutines->fetchAll();
                        if (!empty($routines)) {
                            $this->write($handle, "\n--\n-- Functions and Procedures\n--\n\n", $compress);
                            foreach ($routines as $routine) {
                                $name = $routine['proname'];
                                $type = $routine['type'];
                                $this->write($handle, "DROP {$type} IF EXISTS \"{$name}\" CASCADE;\n", $compress);
                                $createSql = trim($routine['def']);
                                if (!str_ends_with($createSql, ';')) {
                                    $createSql .= ';';
                                }
                                $this->write($handle, $createSql . "\n\n", $compress);
                            }
                        }
                    } catch (\Exception $ex) {
                        // Ignore fallback failures
                    }
                }
            }

            // Export Triggers
            if ($includeStructure) {
                $stmtTriggers = $this->pdo->query("
                    SELECT tgname, pg_get_triggerdef(t.oid) AS def, c.relname AS tbl
                    FROM pg_trigger t
                    JOIN pg_class c ON t.tgrelid = c.oid
                    JOIN pg_namespace n ON c.relnamespace = n.oid
                    WHERE n.nspname = 'public' AND t.tgisinternal = false
                ");
                $triggers = $stmtTriggers->fetchAll();
                if (!empty($triggers)) {
                    $this->write($handle, "\n--\n-- Triggers\n--\n\n", $compress);
                    foreach ($triggers as $trigger) {
                        $name = $trigger['tgname'];
                        $tbl = $trigger['tbl'];
                        $this->write($handle, "DROP TRIGGER IF EXISTS \"{$name}\" ON \"{$tbl}\" CASCADE;\n", $compress);
                        $createSql = trim($trigger['def']);
                        if (!str_ends_with($createSql, ';')) {
                            $createSql .= ';';
                        }
                        $this->write($handle, $createSql . "\n\n", $compress);
                    }
                }
            }

            // Write sequence resets to ensure Serial values sync
            if (!empty($sequenceResets)) {
                $this->write($handle, "\n--\n-- Syncing sequences for Serial columns\n--\n\n", $compress);
                foreach ($sequenceResets as $resetSql) {
                    $this->write($handle, $resetSql . "\n", $compress);
                }
            }

            $this->write($handle, "\nSET session_replication_role = 'origin';\n", $compress);

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            $this->close($handle, $compress);
        }
    }

    /**
     * Restore database
     */
    public function restore(string $sourceFilePath, array $options = []): bool
    {
        if (!file_exists($sourceFilePath)) {
            return false;
        }

        $compress = $options['compress'] ?? str_ends_with(strtolower($sourceFilePath), '.gz');

        try {
            $handle = $this->open($sourceFilePath, 'r', $compress);
        } catch (RuntimeException $e) {
            return false;
        }

        try {
            $this->pdo->exec("SET session_replication_role = 'replica';");

            $query = '';
            $inSingleQuote = false;
            $inDoubleQuote = false;
            $dollarQuoteTag = null;
            $escaped = false;
            $beginNesting = 0;
            $currentWord = '';

            while (!$this->feof($handle, $compress)) {
                $line = $this->fgets($handle, $compress);
                if ($line === false) {
                    break;
                }

                $trimmed = trim($line);
                if (!$inSingleQuote && !$inDoubleQuote && $dollarQuoteTag === null) {
                    if (empty($trimmed) || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*')) {
                        if (str_starts_with($trimmed, '/*') && !str_contains($trimmed, '*/')) {
                            while (!$this->feof($handle, $compress)) {
                                $commentLine = $this->fgets($handle, $compress);
                                if ($commentLine === false || str_contains($commentLine, '*/')) {
                                    break;
                                }
                            }
                        }
                        continue;
                    }
                }

                $length = strlen($line);
                for ($i = 0; $i < $length; $i++) {
                    $char = $line[$i];

                    if ($escaped) {
                        $query .= $char;
                        $escaped = false;
                        $currentWord = '';
                        continue;
                    }

                    if ($char === '\\') {
                        $query .= $char;
                        $escaped = true;
                        $currentWord = '';
                        continue;
                    }

                    if (!$inSingleQuote && !$inDoubleQuote && $dollarQuoteTag === null) {
                        if ($char === '#' || ($char === '-' && $i + 1 < $length && $line[$i + 1] === '-')) {
                            if ($currentWord !== '') {
                                $upperWord = strtoupper($currentWord);
                                if ($upperWord === 'BEGIN' || $upperWord === 'CASE') {
                                    $beginNesting++;
                                } elseif ($upperWord === 'END') {
                                    $beginNesting = max(0, $beginNesting - 1);
                                }
                                $currentWord = '';
                            }
                            break;
                        }
                    }

                    // Handle Dollar Quoting toggle
                    if ($char === '$' && !$inSingleQuote && !$inDoubleQuote) {
                        if ($dollarQuoteTag !== null) {
                            $tagLen = strlen($dollarQuoteTag);
                            if (substr($line, $i, $tagLen) === $dollarQuoteTag) {
                                $query .= $dollarQuoteTag;
                                $i += $tagLen - 1;
                                $dollarQuoteTag = null;
                                $currentWord = '';
                                continue;
                            }
                        } else {
                            $nextDollar = strpos($line, '$', $i + 1);
                            if ($nextDollar !== false) {
                                $tag = substr($line, $i, $nextDollar - $i + 1);
                                $tagContent = substr($tag, 1, -1);
                                if ($tagContent === '' || preg_match('/^[a-zA-Z0-9_]+$/', $tagContent)) {
                                    $dollarQuoteTag = $tag;
                                    $query .= $tag;
                                    $i = $nextDollar;
                                    $currentWord = '';
                                    continue;
                                }
                            }
                        }
                    }

                    if ($dollarQuoteTag !== null) {
                        $query .= $char;
                        continue;
                    }

                    if ($char === "'" && !$inDoubleQuote) {
                        $inSingleQuote = !$inSingleQuote;
                        $currentWord = '';
                    } elseif ($char === '"' && !$inSingleQuote) {
                        $inDoubleQuote = !$inDoubleQuote;
                        $currentWord = '';
                    }

                    // Track keywords BEGIN, CASE, END
                    if (!$inSingleQuote && !$inDoubleQuote) {
                        if (preg_match('/^[a-zA-Z0-9_]$/', $char)) {
                            $currentWord .= $char;
                        } else {
                            if ($currentWord !== '') {
                                $upperWord = strtoupper($currentWord);
                                if ($upperWord === 'BEGIN' || $upperWord === 'CASE') {
                                    $beginNesting++;
                                } elseif ($upperWord === 'END') {
                                    $beginNesting = max(0, $beginNesting - 1);
                                }
                                $currentWord = '';
                            }
                        }
                    } else {
                        $currentWord = '';
                    }

                    if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && $beginNesting === 0) {
                        $stmt = trim($query);
                        if (!empty($stmt)) {
                            $this->pdo->exec($stmt);
                        }
                        $query = '';
                    } else {
                        $query .= $char;
                    }
                }

                // Process trailing word at end of line
                if (!$inSingleQuote && !$inDoubleQuote && $dollarQuoteTag === null && $currentWord !== '') {
                    $upperWord = strtoupper($currentWord);
                    if ($upperWord === 'BEGIN' || $upperWord === 'CASE') {
                        $beginNesting++;
                    } elseif ($upperWord === 'END') {
                        $beginNesting = max(0, $beginNesting - 1);
                    }
                    $currentWord = '';
                }
            }

            $stmt = trim($query);
            if (!empty($stmt)) {
                $this->pdo->exec($stmt);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            $this->close($handle, $compress);
            $this->pdo->exec("SET session_replication_role = 'origin';");
        }
    }

    private function getTablesToBackup(array $options): array
    {
        $stmt = $this->pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
              AND table_type = 'BASE TABLE'
        ");
        $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $tables = $options['tables'] ?? [];
        if (!empty($tables)) {
            $allTables = array_intersect($allTables, $tables);
        }

        $excludeTables = $options['exclude_tables'] ?? [];
        if (!empty($excludeTables)) {
            $allTables = array_diff($allTables, $excludeTables);
        }

        return array_values($allTables);
    }

    /**
     * Sort tables topologically based on foreign key dependencies
     */
    private function sortTablesTopologically(array $tables): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    tc.table_name, 
                    ccu.table_name AS referenced_table_name
                FROM 
                    information_schema.table_constraints AS tc 
                    JOIN information_schema.key_column_usage AS kcu
                      ON tc.constraint_name = kcu.constraint_name
                      AND tc.table_schema = kcu.table_schema
                    JOIN information_schema.constraint_column_usage AS ccu
                      ON ccu.constraint_name = tc.constraint_name
                      AND ccu.table_schema = tc.table_schema
                WHERE 
                    tc.constraint_type = 'FOREIGN KEY' 
                    AND tc.table_schema = 'public'
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Fallback to original order if information_schema query fails
            return $tables;
        }

        $dependencies = [];
        foreach ($tables as $table) {
            $dependencies[$table] = [];
        }

        foreach ($rows as $row) {
            $table = $row['table_name'] ?? $row['TABLE_NAME'] ?? '';
            $refTable = $row['referenced_table_name'] ?? $row['REFERENCED_TABLE_NAME'] ?? '';
            if ($table !== '' && $refTable !== '' && $table !== $refTable) {
                if (in_array($table, $tables) && in_array($refTable, $tables)) {
                    $dependencies[$table][] = $refTable;
                }
            }
        }

        $visited = [];
        $ordered = [];

        $dfs = function ($table) use (&$dfs, &$visited, &$ordered, $dependencies) {
            if (isset($visited[$table])) {
                if ($visited[$table] === 1) {
                    // Cycle detected, stop recursion
                    return;
                }
                return;
            }

            $visited[$table] = 1;

            foreach ($dependencies[$table] as $dep) {
                $dfs($dep);
            }

            $visited[$table] = 2;
            $ordered[] = $table;
        };

        foreach ($tables as $table) {
            if (!isset($visited[$table])) {
                $dfs($table);
            }
        }

        return $ordered;
    }

    private function writeTableData($handle, string $table, array $columns, array $options, bool $compress): void
    {
        $colSelect = '"' . implode('", "', $columns) . '"';
        $colList = '"' . implode('", "', $columns) . '"';
        $chunkSize = (int) ($options['chunk_size'] ?? 1000);
        $offset = 0;

        $this->write($handle, "\n--\n-- Dumping data for table \"{$table}\"\n--\n\n", $compress);

        $insertBuffer = [];
        while (true) {
            $stmt = $this->pdo->prepare("SELECT {$colSelect} FROM \"{$table}\" LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $chunkSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $val) {
                    if (is_null($val)) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = $this->pdo->quote($val);
                    }
                }
                $insertBuffer[] = '(' . implode(', ', $values) . ')';

                if (count($insertBuffer) >= 200) {
                    $sql = "INSERT INTO \"{$table}\" ({$colList}) VALUES \n" . implode(",\n", $insertBuffer) . ";\n";
                    $this->write($handle, $sql, $compress);
                    $insertBuffer = [];
                }
            }

            $offset += $chunkSize;
        }

        if (!empty($insertBuffer)) {
            $sql = "INSERT INTO \"{$table}\" ({$colList}) VALUES \n" . implode(",\n", $insertBuffer) . ";\n";
            $this->write($handle, $sql, $compress);
        }
    }

    private function open(string $filePath, string $mode, bool $compress)
    {
        $handle = $compress ? @gzopen($filePath, $mode) : @fopen($filePath, $mode);
        if (!$handle) {
            throw new RuntimeException("Failed to open file: {$filePath}");
        }
        return $handle;
    }

    private function write($handle, string $data, bool $compress): void
    {
        if (($this->prefix !== '' || $this->prefixReplace !== null) && !empty($this->identifiers)) {
            $data = $this->prefixSql($data);
        }

        $result = $compress ? @gzwrite($handle, $data) : @fwrite($handle, $data);
        if ($result === false) {
            throw new RuntimeException("Failed to write data.");
        }
    }

    private function fgets($handle, bool $compress)
    {
        return $compress ? @gzgets($handle) : @fgets($handle);
    }

    private function feof($handle, bool $compress): bool
    {
        return $compress ? (bool) @gzeof($handle) : @feof($handle);
    }

    private function close($handle, bool $compress): void
    {
        if ($compress) {
            @gzclose($handle);
        } else {
            @fclose($handle);
        }
    }

    private function transformIdentifier(string $name): string
    {
        if ($this->prefixReplace !== null) {
            $old = $this->prefixReplace[0] ?? '';
            $new = $this->prefixReplace[1] ?? '';
            $oldLen = strlen($old);
            if ($oldLen > 0 && substr($name, 0, $oldLen) === $old) {
                return $new . substr($name, $oldLen);
            }
        }
        if ($this->prefix !== '') {
            return $this->prefix . $name;
        }
        return $name;
    }

    private function prefixSql(string $sql): string
    {
        if ($this->prefix === '' && $this->prefixReplace === null) {
            return $sql;
        }
        if (empty($this->identifiers)) {
            return $sql;
        }

        $pattern = "/'[^']*'|`([^`]*)`|\"([^\"]*)\"|\[([^\]]*)\]|\b([a-zA-Z0-9_]+)\b/";

        return preg_replace_callback($pattern, function ($matches) {
            $fullMatch = $matches[0];

            if ($fullMatch[0] === "'") {
                return $fullMatch;
            }

            if (isset($matches[1]) && $matches[1] !== '') {
                $inner = $matches[1];
                if (in_array($inner, $this->identifiers)) {
                    return '`' . $this->transformIdentifier($inner) . '`';
                }
                return $fullMatch;
            }

            if (isset($matches[2]) && $matches[2] !== '') {
                $inner = $matches[2];
                if (in_array($inner, $this->identifiers)) {
                    return '"' . $this->transformIdentifier($inner) . '"';
                }
                return $fullMatch;
            }

            if (isset($matches[3]) && $matches[3] !== '') {
                $inner = $matches[3];
                if (in_array($inner, $this->identifiers)) {
                    return '[' . $this->transformIdentifier($inner) . ']';
                }
                return $fullMatch;
            }

            if (isset($matches[4]) && $matches[4] !== '') {
                $inner = $matches[4];
                if (in_array($inner, $this->identifiers)) {
                    return $this->transformIdentifier($inner);
                }
                return $fullMatch;
            }

            return $fullMatch;
        }, $sql);
    }

    private function gatherIdentifiers(array $options): array
    {
        $tables = $this->getTablesToBackup($options);

        $views = [];
        try {
            $stmtViews = $this->pdo->query("SELECT table_name FROM information_schema.views WHERE table_schema = 'public'");
            $views = $stmtViews->fetchAll(PDO::FETCH_COLUMN);
            $excludeTables = $options['exclude_tables'] ?? [];
            if (!empty($excludeTables)) {
                $views = array_diff($views, $excludeTables);
            }
            $views = array_values($views);
        } catch (\Exception $e) {}

        $routines = [];
        try {
            $stmtRoutines = $this->pdo->query("
                SELECT p.proname
                FROM pg_proc p
                JOIN pg_namespace n ON p.pronamespace = n.oid
                WHERE n.nspname = 'public'
            ");
            $routines = $stmtRoutines->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {}

        $triggers = [];
        try {
            $stmtTriggers = $this->pdo->query("
                SELECT tgname
                FROM pg_trigger t
                JOIN pg_class c ON t.tgrelid = c.oid
                JOIN pg_namespace n ON c.relnamespace = n.oid
                WHERE n.nspname = 'public' AND t.tgisinternal = false
            ");
            $triggers = $stmtTriggers->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {}

        $identifiers = array_merge($tables, $views, $routines, $triggers);

        usort($identifiers, function($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        return $identifiers;
    }
}
