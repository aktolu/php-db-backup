<?php

namespace aktolu\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class SQLiteDriver implements DriverInterface
{
    private PDO $pdo;
    private string $dbFile;
    private string $prefix = '';
    private ?array $prefixReplace = null;
    private array $identifiers = [];

    /**
     * SQLiteDriver Constructor
     */
    public function __construct(string $dbFile, array $options = [])
    {
        $this->dbFile = $dbFile;

        $pdoAttr = $options['pdo_attributes'] ?? [];
        $defaultAttr = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        $attributes = $pdoAttr + $defaultAttr;

        try {
            $this->pdo = new PDO("sqlite:" . $dbFile, null, null, $attributes);
        } catch (PDOException $e) {
            throw new RuntimeException("SQLite connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Backup SQLite database
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
            $header = "-- PHP DB Backup (SQLite)\n" .
                      "-- File: {$this->dbFile}\n" .
                      "-- Generation Time: " . date('Y-m-d H:i:s') . "\n" .
                      "-- PHP Version: " . PHP_VERSION . "\n\n" .
                      "PRAGMA foreign_keys = OFF;\n\n";
            $this->write($handle, $header, $compress);

            $tables = $this->getTablesToBackup($options);
            $includeStructure = $options['include_structure'] ?? true;
            $includeData = $options['include_data'] ?? true;

            foreach ($tables as $table) {
                // Export Structure
                if ($includeStructure) {
                    $this->write($handle, "\n--\n-- Table structure for table `{$table}`\n--\n\n", $compress);
                    $this->write($handle, "DROP TABLE IF EXISTS `{$table}`;\n", $compress);

                    $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_schema WHERE type='table' AND name = :table");
                    $stmt->execute(['table' => $table]);
                    $createTableSql = $stmt->fetchColumn();

                    if (!empty($createTableSql)) {
                        $this->write($handle, $createTableSql . ";\n", $compress);
                    }

                    // Backup indexes for this table
                    $stmtIndexes = $this->pdo->prepare("
                        SELECT sql 
                        FROM sqlite_schema 
                        WHERE type='index' AND tbl_name = :table AND sql IS NOT NULL
                    ");
                    $stmtIndexes->execute(['table' => $table]);
                    $indexes = $stmtIndexes->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($indexes)) {
                        $this->write($handle, "-- Indexes for `{$table}`\n", $compress);
                        foreach ($indexes as $indexSql) {
                            $this->write($handle, $indexSql . ";\n", $compress);
                        }
                    }
                    $this->write($handle, "\n", $compress);
                }

                // Export Data
                if ($includeData) {
                    $this->writeTableData($handle, $table, $options, $compress);
                }
            }

            // Export Views
            if ($includeStructure) {
                $stmtViews = $this->pdo->query("SELECT sql FROM sqlite_schema WHERE type='view'");
                $views = $stmtViews->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($views)) {
                    $this->write($handle, "\n--\n-- Views\n--\n\n", $compress);
                    foreach ($views as $viewSql) {
                        $this->write($handle, $viewSql . ";\n\n", $compress);
                    }
                }
            }

            // Export Triggers
            if ($includeStructure) {
                $stmtTriggers = $this->pdo->query("SELECT sql FROM sqlite_schema WHERE type='trigger'");
                $triggers = $stmtTriggers->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($triggers)) {
                    $this->write($handle, "\n--\n-- Triggers\n--\n\n", $compress);
                    foreach ($triggerSqls = $triggers as $triggerSql) {
                        $this->write($handle, $triggerSql . ";\n\n", $compress);
                    }
                }
            }

            $this->write($handle, "\nPRAGMA foreign_keys = ON;\n", $compress);

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
            $this->pdo->exec("PRAGMA foreign_keys = OFF;");

            $query = '';
            $inSingleQuote = false;
            $inDoubleQuote = false;
            $inBacktick = false;
            $escaped = false;
            $beginNesting = 0;
            $currentWord = '';

            while (!$this->feof($handle, $compress)) {
                $line = $this->fgets($handle, $compress);
                if ($line === false) {
                    break;
                }

                $trimmed = trim($line);
                if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
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

                    if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick && $beginNesting === 0) {
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

            $stmt = trim($query);
            if (!empty($stmt)) {
                $this->pdo->exec($stmt);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            $this->close($handle, $compress);
            $this->pdo->exec("PRAGMA foreign_keys = ON;");
        }
    }

    private function getTablesToBackup(array $options): array
    {
        $stmt = $this->pdo->query("
            SELECT name 
            FROM sqlite_schema 
            WHERE type='table' AND name NOT LIKE 'sqlite_%'
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

    private function writeTableData($handle, string $table, array $options, bool $compress): void
    {
        $columns = [];
        try {
            $stmt = $this->pdo->query("PRAGMA table_xinfo(`{$table}`)");
            $columnsInfo = $stmt->fetchAll();
            foreach ($columnsInfo as $col) {
                $hidden = (int) ($col['hidden'] ?? 0);
                // 2 and 3 represent generated columns in SQLite
                if ($hidden === 2 || $hidden === 3) {
                    continue;
                }
                $columns[] = $col['name'];
            }
        } catch (\Exception $e) {
            // Fallback to table_info if table_xinfo fails
            $stmt = $this->pdo->query("PRAGMA table_info(`{$table}`)");
            $columnsInfo = $stmt->fetchAll();
            foreach ($columnsInfo as $col) {
                $columns[] = $col['name'];
            }
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
            $stmtViews = $this->pdo->query("SELECT name FROM sqlite_schema WHERE type='view'");
            $views = $stmtViews->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {}

        $triggers = [];
        try {
            $stmtTriggers = $this->pdo->query("SELECT name FROM sqlite_schema WHERE type='trigger'");
            $triggers = $stmtTriggers->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {}

        $indexes = [];
        try {
            $stmtIndexes = $this->pdo->query("SELECT name FROM sqlite_schema WHERE type='index' AND sql IS NOT NULL");
            $indexes = $stmtIndexes->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {}

        $identifiers = array_merge($tables, $views, $triggers, $indexes);

        usort($identifiers, function($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        return $identifiers;
    }
}
