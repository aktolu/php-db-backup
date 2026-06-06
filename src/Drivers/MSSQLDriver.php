<?php

namespace aktolu\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class MSSQLDriver implements DriverInterface
{
    private PDO $pdo;
    private string $dbName;
    private string $prefix = '';
    private ?array $prefixReplace = null;
    private array $identifiers = [];

    /**
     * MSSQLDriver Constructor
     */
    public function __construct(
        string $user,
        string $pass,
        string $dbName,
        string $host = 'localhost',
        int $port = 1433,
        array $options = []
    ) {
        $this->dbName = $dbName;

        // Choose SQL Server extension (sqlsrv is standard on Windows, dblib on Linux)
        if (extension_loaded('sqlsrv')) {
            $dsn = "sqlsrv:Server={$host},{$port};Database={$dbName}";
        } else {
            $dsn = "dblib:host={$host};port={$port};dbname={$dbName}";
        }

        $pdoAttr = $options['pdo_attributes'] ?? [];
        $defaultAttr = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        $attributes = $pdoAttr + $defaultAttr;

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $attributes);
        } catch (PDOException $e) {
            throw new RuntimeException("MS SQL connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Backup MS SQL database
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
            $header = "-- PHP DB Backup (MS SQL Server)\n" .
                      "-- Generation Time: " . date('Y-m-d H:i:s') . "\n" .
                      "-- Database: `{$this->dbName}`\n" .
                      "-- PHP Version: " . PHP_VERSION . "\n\n";
            $this->write($handle, $header, $compress);

            $tables = $this->getTablesToBackup($options);
            $tables = $this->sortTablesTopologically($tables);
            $includeStructure = $options['include_structure'] ?? true;
            $includeData = $options['include_data'] ?? true;

            // 1. Disable constraints at the top of the file
            if (!empty($tables)) {
                $this->write($handle, "-- Temporarily disabling foreign key constraints\n", $compress);
                foreach ($tables as $table) {
                    $this->write($handle, "ALTER TABLE [{$table}] NOCHECK CONSTRAINT ALL;\n", $compress);
                }
                $this->write($handle, "\n", $compress);
            }

            foreach ($tables as $table) {
                // Fetch Identity Columns
                $stmtId = $this->pdo->prepare("
                    SELECT c.name AS column_name
                    FROM sys.identity_columns c
                    JOIN sys.tables t ON c.object_id = t.object_id
                    WHERE t.name = :table
                ");
                $stmtId->execute(['table' => $table]);
                $identityCols = $stmtId->fetchAll(PDO::FETCH_COLUMN);

                // Fetch Computed (Generated) Columns
                $stmtComp = $this->pdo->prepare("
                    SELECT c.name AS column_name
                    FROM sys.computed_columns c
                    JOIN sys.tables t ON c.object_id = t.object_id
                    WHERE t.name = :table
                ");
                $stmtComp->execute(['table' => $table]);
                $computedCols = $stmtComp->fetchAll(PDO::FETCH_COLUMN);

                // Fetch columns info
                $stmtCol = $this->pdo->prepare("
                    SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_NAME = :table
                    ORDER BY ORDINAL_POSITION
                ");
                $stmtCol->execute(['table' => $table]);
                $columnsInfo = $stmtCol->fetchAll();

                $backupCols = [];
                $colDefs = [];

                foreach ($columnsInfo as $col) {
                    $name = $col['COLUMN_NAME'];
                    
                    // Skip computed columns
                    if (in_array($name, $computedCols)) {
                        continue;
                    }

                    $backupCols[] = $name;

                    if ($includeStructure) {
                        $type = $col['DATA_TYPE'];
                        $maxLength = $col['CHARACTER_MAXIMUM_LENGTH'];
                        $nullable = $col['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
                        $defaultVal = $col['COLUMN_DEFAULT'];

                        $isIdentity = in_array($name, $identityCols);
                        $identityStr = $isIdentity ? " IDENTITY(1,1)" : "";

                        $default = '';
                        if ($defaultVal !== null) {
                            $default = " DEFAULT " . $defaultVal;
                        }

                        if ($maxLength !== null) {
                            $type = ($maxLength == -1) ? "{$type}(MAX)" : "{$type}({$maxLength})";
                        }

                        $colDefs[] = "    [{$name}] {$type}{$identityStr} {$nullable}{$default}";
                    }
                }

                // Export Structure
                if ($includeStructure) {
                    $this->write($handle, "\n--\n-- Table structure for table [{$table}]\n--\n\n", $compress);
                    if ($options['add_drop_table'] ?? true) {
                        $this->write($handle, "IF OBJECT_ID('[{$table}]', 'U') IS NOT NULL DROP TABLE [{$table}];\n", $compress);
                    }

                    // Fetch Primary Keys
                    $stmtPk = $this->pdo->prepare("
                        SELECT col.name AS column_name
                        FROM sys.indexes idx
                        JOIN sys.index_columns idxCol ON idx.object_id = idxCol.object_id AND idx.index_id = idxCol.index_id
                        JOIN sys.columns col ON idxCol.object_id = col.object_id AND idxCol.column_id = col.column_id
                        JOIN sys.tables tbl ON tbl.object_id = idx.object_id
                        WHERE idx.is_primary_key = 1 AND tbl.name = :table
                    ");
                    $stmtPk->execute(['table' => $table]);
                    $pkCols = $stmtPk->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($pkCols)) {
                        $colDefs[] = "    CONSTRAINT [PK_{$table}] PRIMARY KEY (" . implode(', ', array_map(fn($c) => "[{$c}]", $pkCols)) . ")";
                    }

                    // Fetch Foreign Keys
                    $stmtFk = $this->pdo->prepare("
                        SELECT 
                            obj.name AS foreign_key_name,
                            parent_col.name AS column_name,
                            referenced_tbl.name AS referenced_table_name,
                            referenced_col.name AS referenced_column_name,
                            fk.delete_referential_action_desc
                        FROM 
                            sys.foreign_keys fk
                            JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
                            JOIN sys.tables parent_tbl ON fk.parent_object_id = parent_tbl.object_id
                            JOIN sys.columns parent_col ON fkc.parent_object_id = parent_col.object_id AND fkc.parent_column_id = parent_col.column_id
                            JOIN sys.tables referenced_tbl ON fk.referenced_object_id = referenced_tbl.object_id
                            JOIN sys.columns referenced_col ON fkc.referenced_object_id = referenced_col.object_id AND fkc.referenced_column_id = referenced_col.column_id
                            JOIN sys.objects obj ON fk.object_id = obj.object_id
                        WHERE 
                            parent_tbl.name = :table
                    ");
                    $stmtFk->execute(['table' => $table]);
                    $fkeys = $stmtFk->fetchAll();

                    foreach ($fkeys as $fk) {
                        $action = ($fk['delete_referential_action_desc'] === 'CASCADE') ? 'ON DELETE CASCADE' : '';
                        $colDefs[] = "    CONSTRAINT [{$fk['foreign_key_name']}] FOREIGN KEY ([{$fk['column_name']}]) REFERENCES [{$fk['referenced_table_name']}] ([{$fk['referenced_column_name']}]) {$action}";
                    }

                    $createTableSql = "CREATE TABLE [{$table}] (\n" . implode(",\n", $colDefs) . "\n);\n\n";
                    $this->write($handle, $createTableSql, $compress);
                }

                // Export Data
                if ($includeData && !empty($backupCols)) {
                    $hasIdentity = !empty($identityCols);
                    if ($hasIdentity) {
                        $this->write($handle, "SET IDENTITY_INSERT [{$table}] ON;\n", $compress);
                    }

                    $this->writeTableData($handle, $table, $backupCols, $options, $compress);

                    if ($hasIdentity) {
                        $this->write($handle, "SET IDENTITY_INSERT [{$table}] OFF;\n", $compress);
                    }
                }
            }

            // Export Views
            $stmtViews = $this->pdo->query("
                SELECT TABLE_NAME 
                FROM INFORMATION_SCHEMA.VIEWS 
                WHERE TABLE_CATALOG = DB_NAME()
            ");
            $allViews = $stmtViews->fetchAll(PDO::FETCH_COLUMN);
            $excludeTables = $options['exclude_tables'] ?? [];
            if (!empty($excludeTables)) {
                $allViews = array_diff($allViews, $excludeTables);
            }
            if ($includeStructure && !empty($allViews)) {
                $this->write($handle, "\n--\n-- Views\n--\n\n", $compress);
                foreach ($allViews as $view) {
                    $this->write($handle, "IF OBJECT_ID('dbo.{$view}', 'V') IS NOT NULL DROP VIEW [{$view}];\n", $compress);
                    $stmtView = $this->pdo->prepare("SELECT definition FROM sys.all_sql_modules WHERE object_id = OBJECT_ID(:view)");
                    $stmtView->execute(['view' => $view]);
                    $viewDef = $stmtView->fetchColumn();
                    if (!empty($viewDef)) {
                        $this->write($handle, trim($viewDef) . ";\n\n", $compress);
                    }
                }
            }

            // Export Procedures and Functions (Routines)
            if ($includeStructure) {
                $stmtRoutines = $this->pdo->query("
                    SELECT obj.name, m.definition, obj.type
                    FROM sys.all_sql_modules m
                    JOIN sys.objects obj ON m.object_id = obj.object_id
                    WHERE obj.type IN ('P', 'FN', 'TF') AND obj.is_ms_shipped = 0
                ");
                $routines = $stmtRoutines->fetchAll();
                if (!empty($routines)) {
                    $this->write($handle, "\n--\n-- Procedures and Functions\n--\n\n", $compress);
                    foreach ($routines as $routine) {
                        $name = $routine['name'];
                        $type = trim($routine['type']);
                        
                        if ($type === 'P') {
                            $this->write($handle, "IF OBJECT_ID('dbo.{$name}', 'P') IS NOT NULL DROP PROCEDURE [{$name}];\n", $compress);
                        } else {
                            $this->write($handle, "IF OBJECT_ID('dbo.{$name}', 'FN') IS NOT NULL DROP FUNCTION [{$name}];\n", $compress);
                            $this->write($handle, "IF OBJECT_ID('dbo.{$name}', 'TF') IS NOT NULL DROP FUNCTION [{$name}];\n", $compress);
                        }
                        
                        $this->write($handle, trim($routine['definition']) . ";\n\n", $compress);
                    }
                }
            }

            // Export Triggers
            if ($includeStructure) {
                $stmtTriggers = $this->pdo->query("
                    SELECT obj.name AS trigger_name, m.definition 
                    FROM sys.all_sql_modules m
                    JOIN sys.objects obj ON m.object_id = obj.object_id
                    WHERE obj.type = 'TR' AND obj.is_ms_shipped = 0
                ");
                $triggers = $stmtTriggers->fetchAll();
                if (!empty($triggers)) {
                    $this->write($handle, "\n--\n-- Triggers\n--\n\n", $compress);
                    foreach ($triggers as $trigger) {
                        $name = $trigger['trigger_name'];
                        $this->write($handle, "IF OBJECT_ID('dbo.{$name}', 'TR') IS NOT NULL DROP TRIGGER [{$name}];\n", $compress);
                        $this->write($handle, trim($trigger['definition']) . ";\n\n", $compress);
                    }
                }
            }

            // 2. Re-enable constraints at the bottom of the file
            if (!empty($tables)) {
                $this->write($handle, "\n-- Re-enabling foreign key constraints\n", $compress);
                foreach ($tables as $table) {
                    $this->write($handle, "ALTER TABLE [{$table}] CHECK CONSTRAINT ALL;\n", $compress);
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            $this->close($handle, $compress);
        }
    }

    /**
     * Restore MS SQL database
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
            // Disable foreign key constraints globally during session imports
            try {
                $this->pdo->exec("EXEC sp_MSforeachtable 'ALTER TABLE ? NOCHECK CONSTRAINT ALL'");
            } catch (\Exception $ex) {
                // Ignore if undocumented stored procedure is disabled/missing, 
                // file-level inline ALTER TABLE will handle it.
            }

            $query = '';
            $inSingleQuote = false;
            $inDoubleQuote = false;
            $inBracket = false;
            $escaped = false;
            $beginNesting = 0;
            $currentWord = '';

            while (!$this->feof($handle, $compress)) {
                $line = $this->fgets($handle, $compress);
                if ($line === false) {
                    break;
                }

                $trimmed = trim($line);
                if (!$inSingleQuote && !$inDoubleQuote && !$inBracket) {
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

                    if (!$inSingleQuote && !$inDoubleQuote && !$inBracket) {
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

                    if ($char === "'" && !$inDoubleQuote && !$inBracket) {
                        $inSingleQuote = !$inSingleQuote;
                        $currentWord = '';
                    } elseif ($char === '"' && !$inSingleQuote && !$inBracket) {
                        $inDoubleQuote = !$inDoubleQuote;
                        $currentWord = '';
                    } elseif ($char === '[' && !$inSingleQuote && !$inDoubleQuote) {
                        $inBracket = true;
                        $currentWord = '';
                    } elseif ($char === ']' && !$inSingleQuote && !$inDoubleQuote) {
                        $inBracket = false;
                        $currentWord = '';
                    }

                    // Track keywords BEGIN, CASE, END
                    if (!$inSingleQuote && !$inDoubleQuote && !$inBracket) {
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

                    if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBracket && $beginNesting === 0) {
                        $stmt = trim($query);
                        if (!empty($stmt)) {
                            $skip = false;
                            if (!($options['drop_tables'] ?? true)) {
                                if (preg_match('/^\s*(DROP\s+TABLE|IF\s+OBJECT_ID\b.*DROP\s+TABLE)\b/i', $stmt)) {
                                    $skip = true;
                                }
                            }
                            if (!$skip) {
                                $this->pdo->exec($stmt);
                            }
                        }
                        $query = '';
                    } else {
                        $query .= $char;
                    }
                }

                // Process trailing word at end of line
                if (!$inSingleQuote && !$inDoubleQuote && !$inBracket && $currentWord !== '') {
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
                $skip = false;
                if (!($options['drop_tables'] ?? true)) {
                    if (preg_match('/^\s*(DROP\s+TABLE|IF\s+OBJECT_ID\b.*DROP\s+TABLE)\b/i', $stmt)) {
                        $skip = true;
                    }
                }
                if (!$skip) {
                    $this->pdo->exec($stmt);
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            $this->close($handle, $compress);
            try {
                $this->pdo->exec("EXEC sp_MSforeachtable 'ALTER TABLE ? WITH CHECK CHECK CONSTRAINT ALL'");
            } catch (\Exception $ex) {
                // Ignore fallback
            }
        }
    }

    private function getTablesToBackup(array $options): array
    {
        $stmt = $this->pdo->query("
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_CATALOG = DB_NAME()
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
                    parent_tbl.name AS table_name,
                    referenced_tbl.name AS referenced_table_name
                FROM 
                    sys.foreign_keys fk
                    JOIN sys.tables parent_tbl ON fk.parent_object_id = parent_tbl.object_id
                    JOIN sys.tables referenced_tbl ON fk.referenced_object_id = referenced_tbl.object_id
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Fallback to original order if system tables query fails
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
        $colSelect = '[' . implode('], [', $columns) . ']';
        $colList = '[' . implode('], [', $columns) . ']';
        $chunkSize = (int) ($options['chunk_size'] ?? 1000);
        $offset = 0;

        $this->write($handle, "\n--\n-- Dumping data for table [{$table}]\n--\n\n", $compress);

        // SQL Server pagination uses OFFSET FETCH NEXT (requires SQL Server 2012+)
        // Order by the first column to support OFFSET/FETCH NEXT
        $firstCol = $columns[0];

        $insertBuffer = [];
        while (true) {
            $stmt = $this->pdo->prepare("
                SELECT {$colSelect} 
                FROM [{$table}] 
                ORDER BY [{$firstCol}]
                OFFSET :offset ROWS 
                FETCH NEXT :limit ROWS ONLY
            ");
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
                        // Double-escape single quotes for SQL Server
                        $escapedVal = str_replace("'", "''", $val);
                        $values[] = "'" . $escapedVal . "'";
                    }
                }
                $insertBuffer[] = '(' . implode(', ', $values) . ')';

                if (count($insertBuffer) >= 200) {
                    $sql = "INSERT INTO [{$table}] ({$colList}) VALUES \n" . implode(",\n", $insertBuffer) . ";\n";
                    $this->write($handle, $sql, $compress);
                    $insertBuffer = [];
                }
            }

            $offset += $chunkSize;
        }

        if (!empty($insertBuffer)) {
            $sql = "INSERT INTO [{$table}] ({$colList}) VALUES \n" . implode(",\n", $insertBuffer) . ";\n";
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
            $stmtViews = $this->pdo->query("
                SELECT TABLE_NAME 
                FROM INFORMATION_SCHEMA.VIEWS 
                WHERE TABLE_CATALOG = DB_NAME()
            ");
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
                SELECT obj.name
                FROM sys.all_sql_modules m
                JOIN sys.objects obj ON m.object_id = obj.object_id
                WHERE obj.type IN ('P', 'FN', 'TF') AND obj.is_ms_shipped = 0
            ");
            $routines = $stmtRoutines->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {}

        $triggers = [];
        try {
            $stmtTriggers = $this->pdo->query("
                SELECT obj.name 
                FROM sys.all_sql_modules m
                JOIN sys.objects obj ON m.object_id = obj.object_id
                WHERE obj.type = 'TR' AND obj.is_ms_shipped = 0
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
