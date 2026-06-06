<?php

namespace aktolu;

use InvalidArgumentException;
use RuntimeException;

class PDB
{
    public const MySQL = 'mysql';
    public const PostgreSQL = 'pgsql';
    public const SQLite = 'sqlite';
    public const MSSQL = 'mssql';

    private ?string $driverType = null;
    private ?string $user = null;
    private ?string $pass = null;
    private ?string $dbName = null;
    private ?string $host = null;
    private ?int $port = null;
    private array $options = [];
    private ?string $tablePrefix = null;
    private ?array $prefixReplace = null;

    /**
     * PDB Constructor
     * 
     * @param string|null $driverType Database driver type (self::MySQL, self::PostgreSQL, self::SQLite, self::MSSQL)
     * @param string|null $user Database username
     * @param string|null $pass Database password
     * @param string|null $dbName Database name (or file path for SQLite)
     * @param string|null $host Database host
     * @param int|null $port Database port
     * @param array $options Optional database configuration options
     */
    public function __construct(
        ?string $driverType = null,
        ?string $user = null,
        ?string $pass = null,
        ?string $dbName = null,
        ?string $host = null,
        ?int $port = null,
        array $options = []
    ) {
        if ($driverType !== null) $this->setDriver($driverType);
        if ($user !== null) $this->setUser($user);
        if ($pass !== null) $this->setPassword($pass);
        if ($dbName !== null) $this->setDatabase($dbName);
        if ($host !== null) $this->setHost($host);
        if ($port !== null) $this->setPort($port);
        $this->setOptions($options);
    }

    // Setters (Fluent Interface)

    public function setDriver(string $driverType): self
    {
        $driverType = strtolower($driverType);
        $validDrivers = [
            self::MySQL,
            self::PostgreSQL,
            'postgres',
            self::SQLite,
            self::MSSQL,
            'sqlsrv',
            'dblib'
        ];

        if (!in_array($driverType, $validDrivers)) {
            throw new InvalidArgumentException("Unsupported database driver: {$driverType}");
        }

        if ($driverType === 'postgres') {
            $this->driverType = self::PostgreSQL;
        } elseif ($driverType === 'sqlsrv' || $driverType === 'dblib') {
            $this->driverType = self::MSSQL;
        } else {
            $this->driverType = $driverType;
        }

        return $this;
    }

    public function setUser(string $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function setPassword(string $pass): self
    {
        $this->pass = $pass;
        return $this;
    }

    public function setDatabase(string $dbName): self
    {
        $this->dbName = $dbName;
        return $this;
    }

    public function setHost(string $host): self
    {
        $this->host = $host;
        return $this;
    }

    public function setPort(int $port): self
    {
        $this->port = $port;
        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function setTablePrefix(string $prefix): self
    {
        $this->tablePrefix = $prefix;
        return $this;
    }

    public function setPrefixReplace(string $oldPrefix, string $newPrefix): self
    {
        $this->prefixReplace = [$oldPrefix, $newPrefix];
        return $this;
    }

    // Getters

    public function getDriver(): ?string
    {
        return $this->driverType;
    }

    public function getUser(): ?string
    {
        return $this->user;
    }

    public function getPassword(): ?string
    {
        return $this->pass;
    }

    public function getDatabase(): ?string
    {
        return $this->dbName;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getTablePrefix(): ?string
    {
        return $this->tablePrefix;
    }

    public function getPrefixReplace(): ?array
    {
        return $this->prefixReplace;
    }

    /**
     * Instantiates the concrete database driver on demand
     * 
     * @return Drivers\DriverInterface
     */
    private function getDriverInstance(): Drivers\DriverInterface
    {
        if ($this->driverType === null) {
            throw new RuntimeException("Database driver is not configured.");
        }

        $host = $this->host ?? 'localhost';
        
        switch ($this->driverType) {
            case self::MySQL:
                $port = $this->port ?? 3306;
                return new Drivers\MySQLDriver(
                    $this->user ?? '',
                    $this->pass ?? '',
                    $this->dbName ?? '',
                    $host,
                    $port,
                    $this->options
                );
            case self::PostgreSQL:
                $port = $this->port ?? 5432;
                return new Drivers\PgSQLDriver(
                    $this->user ?? '',
                    $this->pass ?? '',
                    $this->dbName ?? '',
                    $host,
                    $port,
                    $this->options
                );
            case self::SQLite:
                if (empty($this->dbName)) {
                    throw new RuntimeException("SQLite database file path is not set.");
                }
                return new Drivers\SQLiteDriver($this->dbName, $this->options);
            case self::MSSQL:
                $port = $this->port ?? 1433;
                return new Drivers\MSSQLDriver(
                    $this->user ?? '',
                    $this->pass ?? '',
                    $this->dbName ?? '',
                    $host,
                    $port,
                    $this->options
                );
            default:
                throw new RuntimeException("Unsupported driver type: {$this->driverType}");
        }
    }

    /**
     * Backup the database to a file
     * 
     * @param string $destinationFilePath Path to the backup file. If it ends with '.gz', it will be gzipped.
     * @param array $options Optional configurations for the backup process
     * @return bool True on success, false on failure
     */
    public function backup(string $destinationFilePath, array $options = []): bool
    {
        $dir = dirname($destinationFilePath);
        if (!empty($dir) && $dir !== '.' && $dir !== '/' && !is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                // Ignore failure if already created via race condition
            }
        }

        if ($this->tablePrefix !== null && !isset($options['prefix'])) {
            $options['prefix'] = $this->tablePrefix;
        }
        if ($this->prefixReplace !== null && !isset($options['prefix_replace'])) {
            $options['prefix_replace'] = $this->prefixReplace;
        }
        return $this->getDriverInstance()->backup($destinationFilePath, $options);
    }

    /**
     * Restore the database from a backup file
     * 
     * @param string $sourceFilePath Path to the backup SQL or .sql.gz file.
     * @param array $options Optional configurations for the restore process
     * @return bool True on success, false on failure
     */
    public function restore(string $sourceFilePath, array $options = []): bool
    {
        return $this->getDriverInstance()->restore($sourceFilePath, $options);
    }

    /**
     * Rename table prefix in an SQL backup file.
     * Supports MySQL (backticks), MS SQL/SQLite (brackets), and PostgreSQL/SQLite (double quotes).
     * 
     * @param string $sourceFilePath Path to the source SQL or .sql.gz file
     * @param string $oldPrefix The prefix to be replaced (e.g., 'old_')
     * @param string $newPrefix The new prefix (e.g., 'new_')
     * @param string|null $destinationFilePath Optional path to save the modified SQL. If null, overwrites source.
     * @return bool True on success, false on failure
     */
    public function renamePrefixInBackup(
        string $sourceFilePath,
        string $oldPrefix,
        string $newPrefix,
        ?string $destinationFilePath = null
    ): bool {
        if (!file_exists($sourceFilePath)) {
            return false;
        }

        $dest = $destinationFilePath ?? $sourceFilePath;

        // Read source content (decompressing if needed)
        $isSourceCompressed = str_ends_with(strtolower($sourceFilePath), '.gz');
        if ($isSourceCompressed) {
            $handle = @gzopen($sourceFilePath, 'rb');
            if (!$handle) {
                return false;
            }
            $content = '';
            while (!@gzeof($handle)) {
                $chunk = @gzread($handle, 4096);
                if ($chunk === false) {
                    break;
                }
                $content .= $chunk;
            }
            @gzclose($handle);
        } else {
            $content = @file_get_contents($sourceFilePath);
            if ($content === false) {
                return false;
            }
        }

        // Perform the prefix replacements using safe regexes
        $quotedOldPrefix = preg_quote($oldPrefix, '/');

        // 1. MySQL Backticks
        $content = preg_replace(
            '/`(' . $quotedOldPrefix . ')([a-zA-Z0-9_]+)`/',
            '`' . $newPrefix . '$2`',
            $content
        );

        // 2. PostgreSQL / SQLite Double Quotes
        $content = preg_replace(
            '/"(' . $quotedOldPrefix . ')([a-zA-Z0-9_]+)"/',
            '"' . $newPrefix . '$2"',
            $content
        );

        // 3. MS SQL / SQLite Brackets
        $content = preg_replace(
            '/\[(' . $quotedOldPrefix . ')([a-zA-Z0-9_]+)\]/',
            '[' . $newPrefix . '$2]',
            $content
        );

        // Write destination content (compressing if needed)
        $isDestCompressed = str_ends_with(strtolower($dest), '.gz');
        if ($isDestCompressed) {
            $handle = @gzopen($dest, 'wb9');
            if (!$handle) {
                return false;
            }
            $writeResult = @gzwrite($handle, $content);
            @gzclose($handle);
            return $writeResult !== false;
        } else {
            $writeResult = @file_put_contents($dest, $content);
            return $writeResult !== false;
        }
    }
}
