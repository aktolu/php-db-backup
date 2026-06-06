<?php

namespace aktolu\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class MySQLDriver implements DriverInterface
{
    private PDO $pdo;
    private string $dbName;
    private string $prefix = '';
    private ?array $prefixReplace = null;
    private array $identifiers = [];

    /**
     * MySQLDriver Constructor
     * 
     * @param string $user Database username
     * @param string $pass Database password
     * @param string $dbName Database name
     * @param string $host Database host
     * @param int $port Database port
     * @param array $options Driver and connection options
     */
    public function __construct(
        string $user,
        string $pass,
        string $dbName,
        string $host = 'localhost',
        int $port = 3306,
        array $options = []
    ) {
        $this->dbName = $dbName;
        
        $charset = $options['charset'] ?? 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
        
        $pdoAttr = $options['pdo_attributes'] ?? [];
        $defaultAttr = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        // Merge attributes, letting user-provided override
        $attributes = $pdoAttr + $defaultAttr;

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $attributes);
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Backup the database to a file
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
            $header = "-- PHP DB Backup\n" .
                      "-- Host: " . $this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS) . "\n" .
                      "-- Generation Time: " . date('Y-m-d H:i:s') . "\n" .
                      "-- Database: `{$this->dbName}`\n" .
                      "-- PHP Version: " . PHP_VERSION . "\n\n" .
                      "SET FOREIGN_KEY_CHECKS = 0;\n" .
                      "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n";
            $this->write($handle, $header, $compress);

            $tables = $this->getTablesToBackup($options);
            $tables = $this->sortTablesTopologically($tables);
            $includeStructure = $options['include_structure'] ?? true;
            $includeData = $options['include_data'] ?? true;

            foreach ($tables as $table) {
                // Export Structure
                if ($includeStructure) {
                    $this->write($handle, "\n--\n-- Table structure for table `{$table}`\n--\n\n", $compress);
                    $this->write($handle, "DROP TABLE IF EXISTS `{$table}`;\n", $compress);

                    $stmt = $this->pdo->query("SHOW CREATE TABLE `{$table}`");
                    $createResult = $stmt->fetch();
                    $createTableSql = $createResult['Create Table'] ?? $createResult['create table'] ?? '';
                    if (!empty($createTableSql)) {
                        $this->write($handle, $createTableSql . ";\n\n", $compress);
                    }
                }

                // Export Data
                if ($includeData) {
                    $this->writeTableData($handle, $table, $options, $compress);
                }
            }

            // Export Views
            $stmtViews = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
            $allViews = $stmtViews->fetchAll(PDO::FETCH_COLUMN);
            $excludeTables = $options['exclude_tables'] ?? [];
            if (!empty($excludeTables)) {
                $allViews = array_diff($allViews, $excludeTables);
            }
            if ($includeStructure && !empty($allViews)) {
                $this->write($handle, "\n--\n-- Views\n--\n\n", $compress);
                foreach ($allViews as $view) {
                    $this->write($handle, "DROP VIEW IF EXISTS `{$view}`;\n", $compress);
                    $stmtView = $this->pdo->query("SHOW CREATE VIEW `{$view}`");
                    $viewResult = $stmtView->fetch();
                    $createViewSql = $viewResult['Create View'] ?? $viewResult['create view'] ?? '';
                    if (!empty($createViewSql)) {
                        $this->write($handle, $createViewSql . ";\n\n", $compress);
                    }
                }
            }

            // Export Procedures and Functions
            $stmtRoutines = $this->pdo->prepare("
                SELECT ROUTINE_NAME, ROUTINE_TYPE 
                FROM information_schema.ROUTINES 
                WHERE ROUTINE_SCHEMA = :dbName
            ");
            $stmtRoutines->execute(['dbName' => $this->dbName]);
            $routines = $stmtRoutines->fetchAll();

            if ($includeStructure && !empty($routines)) {
                $this->write($handle, "\n--\n-- Procedures and Functions\n--\n\n", $compress);
                foreach ($routines as $routine) {
                    $name = $routine['ROUTINE_NAME'];
                    $type = $routine['ROUTINE_TYPE'];
                    
                    $this->write($handle, "DROP {$type} IF EXISTS `{$name}`;\n", $compress);
                    $stmtCreate = $this->pdo->query("SHOW CREATE {$type} `{$name}`");
                    $createResult = $stmtCreate->fetch();
                    $createSql = $createResult['Create ' . ucfirst(strtolower($type))] ?? '';
                    if (!empty($createSql)) {
                        $this->write($handle, "DELIMITER //\n", $compress);
                        $this->write($handle, $createSql . "//\n", $compress);
                        $this->write($handle, "DELIMITER ;\n\n", $compress);
                    }
                }
            }

            // Export Triggers
            $stmtTriggers = $this->pdo->prepare("
                SELECT TRIGGER_NAME 
                FROM information_schema.TRIGGERS 
                WHERE TRIGGER_SCHEMA = :dbName
            ");
            $stmtTriggers->execute(['dbName' => $this->dbName]);
            $triggers = $stmtTriggers->fetchAll(PDO::FETCH_COLUMN);

            if ($includeStructure && !empty($triggers)) {
                $this->write($handle, "\n--\n-- Triggers\n--\n\n", $compress);
                foreach ($triggers as $trigger) {
                    $this->write($handle, "DROP TRIGGER IF EXISTS `{$trigger}`;\n", $compress);
                    $stmtCreate = $this->pdo->query("SHOW CREATE TRIGGER `{$trigger}`");
                    $createResult = $stmtCreate->fetch();
                    $createSql = $createResult['SQL Original Statement'] ?? $createResult['Create Trigger'] ?? '';
                    if (!empty($createSql)) {
                        $this->write($handle, "DELIMITER //\n", $compress);
                        $this->write($handle, $createSql . "//\n", $compress);
                        $this->write($handle, "DELIMITER ;\n\n", $compress);
                    }
                }
            }

            $this->write($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n", $compress);

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            $this->close($handle, $compress);
        }
    }

    /**
     * Restore the database from a backup file
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
            // Disable foreign key checks for clean restore
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $this->pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");

            $query = '';
            $inSingleQuote = false;
            $inDoubleQuote = false;
            $inBacktick = false;
            $escaped = false;
            $beginNesting = 0;
            $currentWord = '';
            $delimiter = ';';

            while (!$this->feof($handle, $compress)) {
                $line = $this->fgets($handle, $compress);
                if ($line === false) {
                    break;
                }

                $trimmed = trim($line);

                // Handle DELIMITER commands
                if (str_starts_with(strtoupper($trimmed), 'DELIMITER ')) {
                    $delimiter = trim(substr($trimmed, 10));
                    continue;
                }

                // Skip comments if not inside query quotes
                if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                    if (empty($trimmed) || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*')) {
                        // Skip multiline comments
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

                    // Check for inline line comments
                    if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
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

                    if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                        $inSingleQuote = !$inSingleQuote;
                        $currentWord = '';
                    } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                        $inDoubleQuote = !$inDoubleQuote;
                        $currentWord = '';
                    } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                        $inBacktick = !$inBacktick;
                        $currentWord = '';
                    }

                    // Track keywords BEGIN, CASE, END
                    if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
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

                    // Match custom delimiter
                    $delimLen = strlen($delimiter);
                    $matchDelim = true;
                    for ($d = 0; $d < $delimLen; $d++) {
                        if ($i + $d >= $length || $line[$i + $d] !== $delimiter[$d]) {
                            $matchDelim = false;
                            break;
                        }
                    }

                    if ($matchDelim && !$inSingleQuote && !$inDoubleQuote && !$inBacktick && $beginNesting === 0) {
                        $stmt = trim($query);
                        if (!empty($stmt)) {
                            $this->pdo->exec($stmt);
                        }
                        $query = '';
                        $i += $delimLen - 1;
                    } else {
                        $query .= $char;
                    }
                }

                // Process trailing word at end of line
                if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick && $currentWord !== '') {
                    $upperWord = strtoupper($currentWord);
                    if ($upperWord === 'BEGIN' || $upperWord === 'CASE') {
                        $beginNesting++;
                    } elseif ($upperWord === 'END') {
                        $beginNesting = max(0, $beginNesting - 1);
                    }
                    $currentWord = '';
                }
            }

            // Execute any trailing statements
            $stmt = trim($query);
            if (!empty($stmt)) {
                $this->pdo->exec($stmt);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            $this->close($handle, $compress);
            
            // Re-enable foreign keys
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }
    }

    /**
     * Retrieve list of tables to backup
     */
    private function getTablesToBackup(array $options): array
    {
        $stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
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
            $stmt = $this->pdo->prepare("
                SELECT TABLE_NAME, REFERENCED_TABLE_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = :dbName 
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute(['dbName' => $this->dbName]);
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
            $table = $row['TABLE_NAME'] ?? $row['table_name'] ?? '';
            $refTable = $row['REFERENCED_TABLE_NAME'] ?? $row['referenced_table_name'] ?? '';
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
                    // Cycle detected, stop recursion to avoid infinite loops
                    return;
                }
                return;
            }

            $visited[$table] = 1; // Mark as visiting

            foreach ($dependencies[$table] as $dep) {
                $dfs($dep);
            }

            $visited[$table] = 2; // Mark as visited
            $ordered[] = $table;
        };

        foreach ($tables as $table) {
            if (!isset($visited[$table])) {
                $dfs($table);
            }
        }

        return $ordered;
    }

    /**
     * Exports table data in chunked batches
     */
    private function writeTableData($handle, string $table, array $options, bool $compress): void
    {
        // Get column details to skip virtual/generated columns
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
        $columnsInfo = $stmt->fetchAll();
        $columns = [];
        foreach ($columnsInfo as $col) {
            $extra = strtolower($col['Extra'] ?? '');
            if (str_contains($extra, 'generated') || str_contains($extra, 'virtual')) {
                continue;
            }
            $columns[] = $col['Field'];
        }

        if (empty($columns)) {
            return;
        }

        $colSelect = '`' . implode('`, `', $columns) . '`';
        $colList = implode('`, `', $columns);
        $chunkSize = (int) ($options['chunk_size'] ?? 1000);
        $offset = 0;

        $this->write($handle, "\n--\n-- Dumping data for table `{$table}`\n--\n\n", $compress);

        $insertBuffer = [];
        while (true) {
            $stmt = $this->pdo->prepare("SELECT {$colSelect} FROM `{$table}` LIMIT :limit OFFSET :offset");
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

                // Write insert statements in batches of 200 to speed up restoration
                if (count($insertBuffer) >= 200) {
                    $sql = "INSERT INTO `{$table}` (`{$colList}`) VALUES \n" . implode(",\n", $insertBuffer) . ";\n";
                    $this->write($handle, $sql, $compress);
                    $insertBuffer = [];
                }
            }

            $offset += $chunkSize;
        }

        if (!empty($insertBuffer)) {
            $sql = "INSERT INTO `{$table}` (`{$colList}`) VALUES \n" . implode(",\n", $insertBuffer) . ";\n";
            $this->write($handle, $sql, $compress);
        }
    }

    /**
     * Helper to open file / gzstream
     */
    private function open(string $filePath, string $mode, bool $compress)
    {
        if ($compress) {
            $handle = @gzopen($filePath, $mode);
        } else {
            $handle = @fopen($filePath, $mode);
        }

        if (!$handle) {
            throw new RuntimeException("Failed to open file: {$filePath}");
        }

        return $handle;
    }

    /**
     * Helper to read line from file / gzstream
     */
    private function fgets($handle, bool $compress)
    {
        if ($compress) {
            return @gzgets($handle);
        } else {
            return @fgets($handle);
        }
    }

    /**
     * Helper to check end of file on file / gzstream
     */
    private function feof($handle, bool $compress): bool
    {
        if ($compress) {
            return (bool) @gzeof($handle);
        } else {
            return @feof($handle);
        }
    }

    /**
     * Helper to close file / gzstream
     */
    private function close($handle, bool $compress): void
    {
        if ($compress) {
            @gzclose($handle);
        } else {
            @fclose($handle);
        }
    }

    private function write($handle, string $data, bool $compress): void
    {
        if (($this->prefix !== '' || $this->prefixReplace !== null) && !empty($this->identifiers)) {
            $data = $this->prefixSql($data);
        }

        if ($compress) {
            if (@gzwrite($handle, $data) === false) {
                throw new RuntimeException("Failed to write compressed data.");
            }
        } else {
            if (@fwrite($handle, $data) === false) {
                throw new RuntimeException("Failed to write data.");
            }
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
        
        $allViews = [];
        try {
            $stmtViews = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
            $allViews = $stmtViews->fetchAll(PDO::FETCH_COLUMN);
            $excludeTables = $options['exclude_tables'] ?? [];
            if (!empty($excludeTables)) {
                $allViews = array_diff($allViews, $excludeTables);
            }
            $allViews = array_values($allViews);
        } catch (\Exception $e) {}

        $routines = [];
        try {
            $stmtRoutines = $this->pdo->prepare("
                SELECT ROUTINE_NAME 
                FROM information_schema.ROUTINES 
                WHERE ROUTINE_SCHEMA = :dbName
            ");
            $stmtRoutines->execute(['dbName' => $this->dbName]);
            $routines = $stmtRoutines->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {}

        $triggers = [];
        try {
            $stmtTriggers = $this->pdo->prepare("
                SELECT TRIGGER_NAME 
                FROM information_schema.TRIGGERS 
                WHERE TRIGGER_SCHEMA = :dbName
            ");
            $stmtTriggers->execute(['dbName' => $this->dbName]);
            $triggers = $stmtTriggers->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {}

        $identifiers = array_merge($tables, $allViews, $routines, $triggers);
        
        usort($identifiers, function($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        return $identifiers;
    }
}
