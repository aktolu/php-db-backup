# PDB - Database Backup and Restore for PHP

A lightweight, high-performance, and portable PHP database backup and restore system using PDO. Since it is written in pure PHP, it does not depend on any external CLI tools (like `mysqldump`, `pg_dump`, `sqlite3`, or `sqlcmd`), making it extremely portable and suitable for shared hosting environments.

## Features

- **Flexible Configuration (Fluent Interface)**: Instantiate without arguments and configure dynamically later using setters with method chaining.
- **Lazy Connection Loading**: Connection is only established when running a database action (`backup()` or `restore()`).
- **Multi-Database Support**: Works out of the box with **MySQL**, **PostgreSQL**, **SQLite**, and **MS SQL Server**.
- **Pure PHP implementation**: Works anywhere PDO is enabled.
- **Composer integration**: Easily installable via Composer.
- **Low Memory Footprint**: Uses database chunking and file streams to support very large databases.
- **Transparent Gzip Compression**: Automatically compresses/decreases backup files if they end in `.gz`.
- **Generated/Computed Column Safeguard**: Automatically detects and skips virtual/stored generated or computed columns to prevent schema errors during restores.
- **Inline phpMyAdmin-style Foreign Key Checks**: Automatically writes constraint-disabling headers directly into the backup SQL files for seamless imports.
- **Identity Insert Compatibility (MS SQL)**: Automatically wraps table insertions with `SET IDENTITY_INSERT [table] ON` / `OFF` for tables containing auto-incrementing identity keys.
- **Auto-Sequence Syncing (PostgreSQL)**: Reconstructs sequence resets (`setval`) for Serial columns to keep sequences in sync after data restore.
- **Index Reconstruction (SQLite / MS SQL)**: Automatically detects and dumps indexes.
- **Advanced Object Backup & Delimiter Parsing**: Automatically backs up database **Views**, **Triggers**, and **Functions/Stored Procedures (Routines)**. Objects are generated in a strict dependency sequence:
  1. Constraint bypass headers
  2. Tables & Indexes
  3. Data Insertion (`INSERT INTO`)
  4. Views (`CREATE VIEW`)
  5. Functions & Procedures (`CREATE FUNCTION`/`PROCEDURE`)
  6. Triggers (`CREATE TRIGGER`)
  7. Re-enable constraints
  The restore engine uses character-level block and quote parsing (tracking standard `BEGIN ... END`, `CASE ... END`, PostgreSQL dollar quotes `$$`, and custom `DELIMITER` statements) to prevent nested block semicolons from causing premature statement splitting errors during restoration.

---

## Installation

Add this package to your `composer.json` or run:

```bash
composer require aktolu/pdb
```

*(Note: Since this is a custom package, ensure you configure your repository source or load it locally in your projects).*

---

## Usage Examples

### 1. Fluent/Method-Chained Initialization (MySQL Example)

```php
require 'vendor/autoload.php';

use aktolu\PDB;

// Create PDB without arguments and configure dynamically
$pdb = (new PDB())
    ->setDriver(PDB::MySQL)
    ->setHost('127.0.0.1')
    ->setPort(3306)
    ->setUser('root')
    ->setPassword('secret_pass')
    ->setDatabase('my_database');

// Backup (Connection is created here, lazily)
$pdb->backup('backups/backup.sql');
```

### 2. SQLite

For SQLite, pass the path of your database file as the database name parameter. Username and password can be left blank.

```php
// Initialize for SQLite
$pdb = new PDB(PDB::SQLite, '', '', 'path/to/database.db');
$pdb->backup('backups/sqlite_backup.sql.gz'); // Gzipped automatically
```

### 3. PostgreSQL

```php
// Initialize for PostgreSQL
$pdb = new PDB(PDB::PostgreSQL, 'postgres', 'password', 'database_name', 'localhost', 5432);
$pdb->backup('backups/pgsql_backup.sql');
```

### 4. Microsoft SQL Server (MS SQL)

The MS SQL driver dynamically builds DSN bindings. It adapts to the Windows-native `sqlsrv` driver if available, and automatically falls back to `dblib` on Linux.

```php
// Initialize for MS SQL Server
$pdb = new PDB(PDB::MSSQL, 'sa', 'Password123!', 'database_name', 'localhost', 1433);

// Backup (Automatically checks tables for Identity columns and handles constraints)
$pdb->backup('backups/mssql_backup.sql');
```

---

## Configuration API Reference

### Getters and Setters

All setters return the `PDB` instance (`$this`) to support method chaining.

| Getter | Setter | Argument Type | Description |
| :--- | :--- | :--- | :--- |
| `getDriver()` | `setDriver(string $driver)` | `string` (`PDB::MySQL`, `PDB::PostgreSQL`, `PDB::SQLite`, `PDB::MSSQL`) | Sets the database type. |
| `getUser()` | `setUser(string $user)` | `string` | Sets the database connection username. |
| `getPassword()` | `setPassword(string $pass)` | `string` | Sets the database connection password. |
| `getDatabase()` | `setDatabase(string $dbName)` | `string` | Sets the database name (or SQLite file path). |
| `getHost()` | `setHost(string $host)` | `string` | Sets the database host server. |
| `getPort()` | `setPort(int $port)` | `int` | Sets the database connection port. |
| `getOptions()` | `setOptions(array $options)` | `array` | Sets database/driver configuration options. |
| `getTablePrefix()` | `setTablePrefix(string $prefix)` | `string` | Sets the table prefix to prepend dynamically when writing the backup. |
| `getPrefixReplace()` | `setPrefixReplace(string $old, string $new)` | `array` | Sets table prefix replacement keys to map dynamically when writing the backup. |

### Backup Options
You can configure the behavior of the backup using the 2nd argument of `$pdb->backup()`:

| Option | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `tables` | `array` | `[]` | List of tables to include. If empty, includes all tables. |
| `exclude_tables` | `array` | `[]` | List of tables to exclude from export. |
| `include_structure`| `bool` | `true` | Export table schema definitions (`CREATE TABLE`). |
| `include_data` | `bool` | `true` | Export table data (`INSERT` statements). |
| `chunk_size` | `int` | `1000` | Records fetched per cursor chunk to prevent PHP memory exhaustion. |
| `compress` | `bool` | `auto` | Force gzip compression. If not set, checks if file ends with `.gz`. |
| `prefix` | `string` | `''` | Dynamically prepends a table prefix (e.g. `wp_`) when writing the backup stream. |
| `prefix_replace` | `array` | `null` | Mapped array `['old_', 'new_']` to replace table prefixes dynamically when writing the backup. |

---

## Inline Foreign Key Control Behavior

When backing up, PDB writes standard commands to temporarily disable foreign key constraints at the top of the file, and enables them at the bottom. This mimics the behavior of native phpMyAdmin exports:

- **MySQL**:
  - Top: `SET FOREIGN_KEY_CHECKS = 0; SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";`
  - Bottom: `SET FOREIGN_KEY_CHECKS = 1;`
- **SQLite**:
  - Top: `PRAGMA foreign_keys = OFF;`
  - Bottom: `PRAGMA foreign_keys = ON;`
- **PostgreSQL**:
  - Top: `SET session_replication_role = 'replica';`
  - Bottom: `SET session_replication_role = 'origin';`
- **MS SQL Server**:
  - Top: `ALTER TABLE [tableName] NOCHECK CONSTRAINT ALL;` (for all exported tables)
  - Bottom: `ALTER TABLE [tableName] CHECK CONSTRAINT ALL;` (for all exported tables)

---

## Modifying Table Prefixes

### 1. Rename Prefixes Post-Backup
You can dynamically rename table prefixes in an SQL backup file (or compressed `.gz` backup file) using the `renamePrefixInBackup` method. This handles database-specific quotes safely without altering user data or string contents.

```php
// Rename table prefixes from 'wp_' to 'newwp_' in a finished backup file
$pdb->renamePrefixInBackup(
    'backups/backup.sql.gz',       // Source file path
    'wp_',                         // Old prefix
    'newwp_',                      // New prefix
    'backups/renamed_backup.sql.gz' // (Optional) Destination file path
);
```

### 2. Rename/Replace Prefixes During Backup Generation (Streaming/Memory-Efficient)
For very large databases, you can replace table prefixes dynamically **as the SQL backup file is being written** to avoid reading huge files into memory.

```php
// If tables in the database are named 'old_users', 'old_posts', etc.
// They will be written as 'new_users', 'new_posts' directly inside the backup file
$pdb->setPrefixReplace('old_', 'new_');
$pdb->backup('backups/backup.sql.gz');
```

## License

This project is licensed under the MIT License.
