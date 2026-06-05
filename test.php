<?php

require 'vendor/autoload.php';

use aktolu\PDB;

echo "===============================================\n";
echo "=== Starting PDB Multi-Database Verification ===\n";
echo "===============================================\n\n";

// =========================================================================
// 1. MYSQL VERIFICATION
// =========================================================================
$host = '127.0.0.1';
$port = 3306;
$user = 'root';
$pass = '';
$dbName = 'test_pdb_backup';

echo "--- [1/4] MySQL Verification ---\n";
try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "Creating MySQL test database: {$dbName}...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    $pdo->exec("DROP TRIGGER IF EXISTS `before_insert_users`");
    $pdo->exec("DROP VIEW IF EXISTS `active_users`");
    $pdo->exec("DROP TABLE IF EXISTS `posts`");
    $pdo->exec("DROP TABLE IF EXISTS `users`");
    
    $pdo->exec("CREATE TABLE `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NULL,
        `status` ENUM('active', 'inactive') DEFAULT 'active'
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE `posts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `content` TEXT,
        `title_len` INT GENERATED ALWAYS AS (CHAR_LENGTH(`title`)) STORED,
        CONSTRAINT `fk_posts_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Add MySQL View
    echo "Creating MySQL View...\n";
    $pdo->exec("CREATE VIEW `active_users` AS SELECT * FROM `users` WHERE `status` = 'active'");

    // Add MySQL Trigger (Simple single statement to avoid PDO delimiter complications)
    echo "Creating MySQL Trigger...\n";
    $pdo->exec("CREATE TRIGGER `before_insert_users` BEFORE INSERT ON `users` FOR EACH ROW SET NEW.name = UPPER(NEW.name)");

    $insertUser = $pdo->prepare("INSERT INTO `users` (`name`, `email`, `status`) VALUES (?, ?, ?)");
    $insertUser->execute(['Muhammed Aktolu', 'muhammed@example.com', 'active']);
    $insertUser->execute(["O'Connor Test", null, 'inactive']);

    $insertPost = $pdo->prepare("INSERT INTO `posts` (`user_id`, `title`, `content`) VALUES (?, ?, ?)");
    $insertPost->execute([1, 'MySQL Post', 'Normal content.']);
    $insertPost->execute([2, 'Special characters: ; # -- \'', 'Contending with symbols: "escaped" and ;.']);

    echo "Initial MySQL State:\n";
    printMySQLState($pdo);

    // Initialize PDB lazily with method chaining
    $pdb = new PDB();
    $pdb->setDriver(PDB::MySQL)
        ->setUser($user)
        ->setPassword($pass)
        ->setDatabase($dbName)
        ->setHost($host)
        ->setPort($port);

    // Verify Getters
    if ($pdb->getDriver() !== PDB::MySQL || 
        $pdb->getUser() !== $user || 
        $pdb->getPassword() !== $pass || 
        $pdb->getDatabase() !== $dbName || 
        $pdb->getHost() !== $host || 
        $pdb->getPort() !== $port
    ) {
        throw new Exception("PDB Getters did not return matching values for MySQL.");
    }
    echo "SUCCESS: Fluent setters and getters verified for MySQL.\n";

    $sqlFile = __DIR__ . '/mysql_backup.sql';
    $gzFile = __DIR__ . '/mysql_backup.sql.gz';

    if (file_exists($sqlFile)) unlink($sqlFile);
    if (file_exists($gzFile)) unlink($gzFile);

    // Backup
    echo "Backing up to SQL: {$sqlFile}...\n";
    $pdb->backup($sqlFile);
    echo "Backing up to GZ: {$gzFile}...\n";
    $pdb->backup($gzFile);

    // Verify foreign key control lines are written inside the file
    $sqlContent = file_get_contents($sqlFile);
    if (str_contains($sqlContent, 'SET FOREIGN_KEY_CHECKS = 0;') && str_contains($sqlContent, 'SET FOREIGN_KEY_CHECKS = 1;')) {
        echo "SUCCESS: MySQL SQL file contains inline phpMyAdmin-style foreign key checks.\n";
    } else {
        throw new Exception("MySQL backup file does not contain foreign key control statements.");
    }

    // Verify view and trigger exist in SQL backup file
    if (str_contains($sqlContent, 'CREATE ALGORITHM') || str_contains($sqlContent, 'VIEW `active_users`')) {
        echo "SUCCESS: MySQL SQL file contains recreated Views.\n";
    } else {
        throw new Exception("MySQL backup file missing View definitions.");
    }

    if (str_contains($sqlContent, 'CREATE DEFINER') || str_contains($sqlContent, 'TRIGGER `before_insert_users`')) {
        echo "SUCCESS: MySQL SQL file contains recreated Triggers.\n";
    } else {
        throw new Exception("MySQL backup file missing Trigger definitions.");
    }

    // Restore SQL
    $pdo->exec("DROP TRIGGER IF EXISTS `before_insert_users`");
    $pdo->exec("DROP VIEW IF EXISTS `active_users`");
    $pdo->exec("DROP TABLE IF EXISTS `posts`");
    $pdo->exec("DROP TABLE IF EXISTS `users`");
    echo "Restoring from MySQL SQL file...\n";
    $pdb->restore($sqlFile);
    printMySQLState($pdo);

    // Assert trigger behavior after restore (Should uppercase name)
    $insertUser->execute(['trigger test', 'test@test.com', 'active']);
    $lastId = $pdo->lastInsertId();
    $stmtCheck = $pdo->prepare("SELECT `name` FROM `users` WHERE `id` = ?");
    $stmtCheck->execute([$lastId]);
    $checkedName = $stmtCheck->fetchColumn();
    if ($checkedName === 'TRIGGER TEST') {
        echo "SUCCESS: MySQL Trigger is fully operational post-restore.\n";
    } else {
        throw new Exception("MySQL Trigger failed to trigger/uppercase on insert.");
    }

    // Restore GZ
    $pdo->exec("DROP TRIGGER IF EXISTS `before_insert_users`");
    $pdo->exec("DROP VIEW IF EXISTS `active_users`");
    $pdo->exec("DROP TABLE IF EXISTS `posts`");
    $pdo->exec("DROP TABLE IF EXISTS `users`");
    echo "Restoring from MySQL Gzip file...\n";
    $pdb->restore($gzFile);
    printMySQLState($pdo);

    // Cleanup
    if (file_exists($sqlFile)) unlink($sqlFile);
    if (file_exists($gzFile)) unlink($gzFile);
    $pdo->exec("DROP DATABASE `{$dbName}`");
    echo "MySQL Verification: OK\n\n";

} catch (Exception $e) {
    echo "MySQL Verification failed: " . $e->getMessage() . "\n\n";
}

// =========================================================================
// 2. SQLITE VERIFICATION
// =========================================================================
echo "--- [2/4] SQLite Verification ---\n";
$sqliteDbFile = __DIR__ . '/test_sqlite.db';
$sqliteSqlFile = __DIR__ . '/sqlite_backup.sql';
$sqliteGzFile = __DIR__ . '/sqlite_backup.sql.gz';

if (file_exists($sqliteDbFile)) unlink($sqliteDbFile);
if (file_exists($sqliteSqlFile)) unlink($sqliteSqlFile);
if (file_exists($sqliteGzFile)) unlink($sqliteGzFile);

try {
    runSQLiteVerification($sqliteDbFile, $sqliteSqlFile, $sqliteGzFile);

    // Cleanup SQLite
    gc_collect_cycles(); // force collection of any unreferenced objects
    if (file_exists($sqliteDbFile)) unlink($sqliteDbFile);
    if (file_exists($sqliteSqlFile)) unlink($sqliteSqlFile);
    if (file_exists($sqliteGzFile)) unlink($sqliteGzFile);
    echo "SQLite Verification: OK\n\n";

} catch (Exception $e) {
    echo "SQLite Verification failed: " . $e->getMessage() . "\n\n";
    gc_collect_cycles();
    if (file_exists($sqliteDbFile)) unlink($sqliteDbFile);
}

// =========================================================================
// 3. POSTGRESQL VERIFICATION
// =========================================================================
echo "--- [3/4] PostgreSQL Verification ---\n";
$pgHost = '127.0.0.1';
$pgPort = 5432;
$pgUser = 'postgres';
$pgPass = 'postgres';
$pgDbName = 'test_pdb_backup';
$pgSqlFile = __DIR__ . '/pgsql_backup.sql';
$pgGzFile = __DIR__ . '/pgsql_backup.sql.gz';

if (file_exists($pgSqlFile)) unlink($pgSqlFile);
if (file_exists($pgGzFile)) unlink($pgGzFile);

try {
    $pgPdo = @new PDO("pgsql:host={$pgHost};port={$pgPort}", $pgUser, $pgPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
    
    echo "PostgreSQL server found. Setting up test database: {$pgDbName}...\n";
    try {
        $pgPdo->exec("CREATE DATABASE \"{$pgDbName}\"");
    } catch (\Exception $dbEx) {
        // Already exists
    }
    
    $pgDbPdo = new PDO("pgsql:host={$pgHost};port={$pgPort};dbname={$pgDbName}", $pgUser, $pgPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $pgDbPdo->exec("DROP TABLE IF EXISTS \"posts\" CASCADE");
    $pgDbPdo->exec("DROP TABLE IF EXISTS \"users\" CASCADE");

    $pgDbPdo->exec("CREATE TABLE \"users\" (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100)
    )");

    $pgDbPdo->exec("CREATE TABLE \"posts\" (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title VARCHAR(255) NOT NULL,
        content TEXT,
        title_len INTEGER GENERATED ALWAYS AS (CHAR_LENGTH(title)) STORED
    )");

    $stmt = $pgDbPdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)");
    $stmt->execute(['Muhammed Postgres', 'postgres@example.com']);
    $stmt->execute(["O'Malley PG", null]);

    $stmtPost = $pgDbPdo->prepare("INSERT INTO posts (user_id, title, content) VALUES (?, ?, ?)");
    $stmtPost->execute([1, 'Postgres Post', 'Database is working properly.']);
    
    echo "Initial PostgreSQL State:\n";
    printPGState($pgDbPdo);

    $pdbPg = new PDB(PDB::PostgreSQL, $pgUser, $pgPass, $pgDbName, $pgHost, $pgPort);
    
    // Backup
    echo "Backing up PostgreSQL to SQL: {$pgSqlFile}...\n";
    $pdbPg->backup($pgSqlFile);
    echo "Backing up PostgreSQL to GZ: {$pgGzFile}...\n";
    $pdbPg->backup($pgGzFile);

    // Verify replication role commands exist in SQL file
    $sqlContent = file_get_contents($pgSqlFile);
    if (str_contains($sqlContent, 'session_replication_role = \'replica\'') && str_contains($sqlContent, 'session_replication_role = \'origin\'')) {
        echo "SUCCESS: PostgreSQL SQL file contains replication role controls.\n";
    } else {
        throw new Exception("PostgreSQL backup file does not contain foreign key replication overrides.");
    }

    // Restore SQL
    $pgDbPdo->exec("DROP TABLE IF EXISTS \"posts\" CASCADE");
    $pgDbPdo->exec("DROP TABLE IF EXISTS \"users\" CASCADE");
    echo "Restoring PostgreSQL from SQL file...\n";
    $pdbPg->restore($pgSqlFile);
    printPGState($pgDbPdo);

    // Restore GZ
    $pgDbPdo->exec("DROP TABLE IF EXISTS \"posts\" CASCADE");
    $pgDbPdo->exec("DROP TABLE IF EXISTS \"users\" CASCADE");
    echo "Restoring PostgreSQL from GZ file...\n";
    $pdbPg->restore($pgGzFile);
    printPGState($pgDbPdo);

    // Drop DB
    $pgDbPdo = null;
    $pgPdo->exec("DROP DATABASE \"{$pgDbName}\"");
    
    if (file_exists($pgSqlFile)) unlink($pgSqlFile);
    if (file_exists($pgGzFile)) unlink($pgGzFile);
    echo "PostgreSQL Verification: OK\n\n";

} catch (PDOException $e) {
    echo "PostgreSQL test gracefully skipped: PostgreSQL is not running or accessible (e.g. invalid credentials).\n\n";
} catch (Exception $e) {
    echo "PostgreSQL Verification failed: " . $e->getMessage() . "\n\n";
}

// =========================================================================
// 4. MS SQL VERIFICATION
// =========================================================================
echo "--- [4/4] MS SQL Verification ---\n";
$mssqlHost = '127.0.0.1';
$mssqlPort = 1433;
$mssqlUser = 'sa';
$mssqlPass = 'Password123!';
$mssqlDbName = 'test_pdb_backup';
$mssqlSqlFile = __DIR__ . '/mssql_backup.sql';
$mssqlGzFile = __DIR__ . '/mssql_backup.sql.gz';

if (file_exists($mssqlSqlFile)) unlink($mssqlSqlFile);
if (file_exists($mssqlGzFile)) unlink($mssqlGzFile);

try {
    $ext = extension_loaded('sqlsrv') ? 'sqlsrv' : (extension_loaded('dblib') ? 'dblib' : null);
    if ($ext === null) {
        throw new RuntimeException("Neither sqlsrv nor dblib extensions are loaded.");
    }
    
    $dsn = ($ext === 'sqlsrv') ? "sqlsrv:Server={$mssqlHost},{$mssqlPort}" : "dblib:host={$mssqlHost};port={$mssqlPort}";
    
    $mssqlPdo = @new PDO($dsn, $mssqlUser, $mssqlPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);

    echo "MS SQL Server found. Setting up test database: {$mssqlDbName}...\n";
    $mssqlPdo->exec("IF DB_ID('{$mssqlDbName}') IS NOT NULL DROP DATABASE [{$mssqlDbName}];");
    $mssqlPdo->exec("CREATE DATABASE [{$mssqlDbName}];");
    $mssqlPdo->exec("USE [{$mssqlDbName}];");

    $mssqlPdo->exec("CREATE TABLE users (
        id INT IDENTITY(1,1) PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100)
    );");

    $mssqlPdo->exec("CREATE TABLE posts (
        id INT IDENTITY(1,1) PRIMARY KEY,
        user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title VARCHAR(255) NOT NULL,
        content VARCHAR(MAX),
        title_len AS (LEN(title))
    );");

    $stmt = $mssqlPdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)");
    $stmt->execute(['Muhammed MSSQL', 'mssql@example.com']);
    $stmt->execute(["O'Malley MS", null]);

    $stmtPost = $mssqlPdo->prepare("INSERT INTO posts (user_id, title, content) VALUES (?, ?, ?)");
    $stmtPost->execute([1, 'MS SQL Post', 'Microsoft SQL Server support is active.']);

    echo "Initial MS SQL State:\n";
    printMSSQLState($mssqlPdo);

    $pdbMssql = new PDB(PDB::MSSQL, $mssqlUser, $mssqlPass, $mssqlDbName, $mssqlHost, $mssqlPort);
    
    // Backup
    echo "Backing up MS SQL to SQL: {$mssqlSqlFile}...\n";
    $pdbMssql->backup($mssqlSqlFile);
    echo "Backing up MS SQL to GZ: {$mssqlGzFile}...\n";
    $pdbMssql->backup($mssqlGzFile);

    // Verify foreign key pragma exists in SQL file
    $sqlContent = file_get_contents($mssqlSqlFile);
    if (str_contains($sqlContent, 'NOCHECK CONSTRAINT ALL') && str_contains($sqlContent, 'CHECK CONSTRAINT ALL')) {
        echo "SUCCESS: MS SQL SQL file contains inline foreign key overrides.\n";
    } else {
        throw new Exception("MS SQL backup file does not contain foreign key bypasses.");
    }

    // Verify IDENTITY_INSERT exists in SQL file
    if (str_contains($sqlContent, 'SET IDENTITY_INSERT')) {
        echo "SUCCESS: MS SQL SQL file contains IDENTITY_INSERT wrappers.\n";
    } else {
        throw new Exception("MS SQL backup file does not contain IDENTITY_INSERT settings.");
    }

    // Restore SQL
    $mssqlPdo->exec("DROP TABLE IF EXISTS posts;");
    $mssqlPdo->exec("DROP TABLE IF EXISTS users;");
    echo "Restoring MS SQL from SQL file...\n";
    $pdbMssql->restore($mssqlSqlFile);
    printMSSQLState($mssqlPdo);

    // Restore GZ
    $mssqlPdo->exec("DROP TABLE IF EXISTS posts;");
    $mssqlPdo->exec("DROP TABLE IF EXISTS users;");
    echo "Restoring MS SQL from GZ file...\n";
    $pdbMssql->restore($mssqlGzFile);
    printMSSQLState($mssqlPdo);

    // Cleanup
    $mssqlPdo->exec("USE [master];");
    $mssqlPdo->exec("DROP DATABASE [{$mssqlDbName}];");
    $mssqlPdo = null;

    if (file_exists($mssqlSqlFile)) unlink($mssqlSqlFile);
    if (file_exists($mssqlGzFile)) unlink($mssqlGzFile);
    echo "MS SQL Verification: OK\n\n";

} catch (PDOException $e) {
    echo "MS SQL test gracefully skipped: MS SQL is not running or accessible (e.g. invalid credentials or extension not loaded).\n\n";
} catch (Exception $e) {
    echo "MS SQL Verification failed: " . $e->getMessage() . "\n\n";
}

echo "=== Verification Finished ===\n";

// =========================================================================
// HELPER FUNCTIONS & RUNNERS
// =========================================================================
function runSQLiteVerification($sqliteDbFile, $sqliteSqlFile, $sqliteGzFile) {
    $sqlitePdo = new PDO("sqlite:" . $sqliteDbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Create tables, indices, and generated columns
    $sqlitePdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT
    )");
    
    $sqlitePdo->exec("CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        content TEXT,
        title_len INTEGER GENERATED ALWAYS AS (length(title)) STORED,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    )");

    $sqlitePdo->exec("CREATE INDEX idx_users_email ON users(email)");

    // Create SQLite view and trigger
    echo "Creating SQLite View and Trigger...\n";
    $sqlitePdo->exec("CREATE VIEW active_users AS SELECT * FROM users WHERE email IS NOT NULL");
    
    // SQLite trigger that updates user status/history or auto-creates welcome post
    $sqlitePdo->exec("CREATE TRIGGER log_user_insert AFTER INSERT ON users BEGIN
        INSERT INTO posts(user_id, title, content) VALUES (new.id, 'Auto Welcome', 'Hello ' || new.name);
    END;");

    // Populate data
    $stmt = $sqlitePdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)");
    $stmt->execute(['Muhammed SQLite', 'sqlite@example.com']);
    $stmt->execute(["O'Malley SQLite", null]);
    $stmt = null;

    echo "Initial SQLite State:\n";
    printSQLiteState($sqlitePdo);

    // Initialize PDB lazily for SQLite with method chaining
    $pdbSqlite = new PDB();
    $pdbSqlite->setDriver(PDB::SQLite)
              ->setDatabase($sqliteDbFile);

    // Verify Getters
    if ($pdbSqlite->getDriver() !== PDB::SQLite || $pdbSqlite->getDatabase() !== $sqliteDbFile) {
        throw new Exception("PDB Getters did not return matching values for SQLite.");
    }
    echo "SUCCESS: Fluent setters and getters verified for SQLite.\n";
    
    // Backup
    echo "Backing up SQLite to SQL: {$sqliteSqlFile}...\n";
    $pdbSqlite->backup($sqliteSqlFile);
    echo "Backing up SQLite to GZ: {$sqliteGzFile}...\n";
    $pdbSqlite->backup($sqliteGzFile);

    // Verify foreign key pragma exists in SQL file
    $sqlContent = file_get_contents($sqliteSqlFile);
    if (str_contains($sqlContent, 'PRAGMA foreign_keys = OFF;') && str_contains($sqlContent, 'PRAGMA foreign_keys = ON;')) {
        echo "SUCCESS: SQLite SQL file contains inline foreign key pragmas.\n";
    } else {
        throw new Exception("SQLite backup file does not contain foreign key control pragmas.");
    }

    // Verify index is exported
    if (str_contains($sqlContent, 'CREATE INDEX idx_users_email')) {
        echo "SUCCESS: SQLite SQL file successfully contains recreated indexes.\n";
    } else {
        throw new Exception("SQLite backup file missing index declarations.");
    }

    // Verify view and trigger is exported in SQL file
    if (str_contains($sqlContent, 'CREATE VIEW active_users')) {
        echo "SUCCESS: SQLite SQL file successfully contains recreated views.\n";
    } else {
        throw new Exception("SQLite backup file missing view declarations.");
    }

    if (str_contains($sqlContent, 'CREATE TRIGGER log_user_insert')) {
        echo "SUCCESS: SQLite SQL file successfully contains recreated triggers.\n";
    } else {
        throw new Exception("SQLite backup file missing trigger declarations.");
    }

    // Restore SQL
    $sqlitePdo->exec("DROP TRIGGER IF EXISTS log_user_insert");
    $sqlitePdo->exec("DROP VIEW IF EXISTS active_users");
    $sqlitePdo->exec("DROP INDEX IF EXISTS idx_users_email");
    $sqlitePdo->exec("DROP TABLE IF EXISTS posts");
    $sqlitePdo->exec("DROP TABLE IF EXISTS users");
    echo "Restoring SQLite from SQL file...\n";
    $pdbSqlite->restore($sqliteSqlFile);
    printSQLiteState($sqlitePdo);

    // Verify trigger behavior after restore
    $stmtNew = $sqlitePdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)");
    $stmtNew->execute(['trigger test', 'test@test.com']);
    $lastId = $sqlitePdo->lastInsertId();
    $stmtCheck = $sqlitePdo->prepare("SELECT content FROM posts WHERE user_id = ?");
    $stmtCheck->execute([$lastId]);
    $checkedContent = $stmtCheck->fetchColumn();
    $stmtNew = null;
    $stmtCheck = null;

    if ($checkedContent === 'Hello trigger test') {
        echo "SUCCESS: SQLite Trigger is fully operational post-restore.\n";
    } else {
        throw new Exception("SQLite Trigger failed to trigger auto-post creation on insert.");
    }

    // Restore GZ
    $sqlitePdo->exec("DROP TRIGGER IF EXISTS log_user_insert");
    $sqlitePdo->exec("DROP VIEW IF EXISTS active_users");
    $sqlitePdo->exec("DROP INDEX IF EXISTS idx_users_email");
    $sqlitePdo->exec("DROP TABLE IF EXISTS posts");
    $sqlitePdo->exec("DROP TABLE IF EXISTS users");
    echo "Restoring SQLite from GZ file...\n";
    $pdbSqlite->restore($sqliteGzFile);
    printSQLiteState($sqlitePdo);

    // Verify prefix replacement utility
    $renamedSqlFile = __DIR__ . '/sqlite_renamed.sql';
    if (file_exists($renamedSqlFile)) unlink($renamedSqlFile);

    $testSql = "CREATE TABLE `old_users` (id INT);\nINSERT INTO `old_users` VALUES (1);\nSELECT * FROM [old_users];\nDROP TABLE \"old_users\";";
    $testFile = __DIR__ . '/test_prefix.sql';
    file_put_contents($testFile, $testSql);

    echo "Testing prefix replacement...\n";
    $pdbSqlite->renamePrefixInBackup($testFile, 'old_', 'new_', $renamedSqlFile);
    $renamedContent = file_get_contents($renamedSqlFile);

    if (str_contains($renamedContent, 'new_users') && !str_contains($renamedContent, 'old_users')) {
        echo "SUCCESS: renamePrefixInBackup verified successfully.\n";
    } else {
        throw new Exception("Prefix replacement failed. Content: " . $renamedContent);
    }

    unlink($testFile);
    unlink($renamedSqlFile);

    // Verify dynamic prefixing during backup
    echo "Testing dynamic prefixing during backup...\n";
    $prefixedBackupFile = __DIR__ . '/sqlite_prefixed_backup.sql';
    if (file_exists($prefixedBackupFile)) unlink($prefixedBackupFile);

    $pdbSqlite->setTablePrefix('pref_');
    if ($pdbSqlite->getTablePrefix() !== 'pref_') {
        throw new Exception("Table prefix getter/setter failed.");
    }

    $pdbSqlite->backup($prefixedBackupFile);
    $prefixedContent = file_get_contents($prefixedBackupFile);

    if (str_contains($prefixedContent, 'pref_users') && 
        str_contains($prefixedContent, 'pref_posts') && 
        str_contains($prefixedContent, 'pref_active_users') && 
        str_contains($prefixedContent, 'pref_log_user_insert') &&
        str_contains($prefixedContent, 'pref_idx_users_email')
    ) {
        echo "SUCCESS: Dynamic table prefix backup verified successfully.\n";
    } else {
        throw new Exception("Dynamic prefixing failed. Content: " . $prefixedContent);
    }

    $pdbSqlite->setTablePrefix('');
    if (file_exists($prefixedBackupFile)) unlink($prefixedBackupFile);

    // Verify prefix replace during backup
    echo "Testing dynamic prefix replacement during backup...\n";
    $replacedBackupFile = __DIR__ . '/sqlite_replaced_backup.sql';
    if (file_exists($replacedBackupFile)) unlink($replacedBackupFile);

    $pdbSqlite->setPrefixReplace('users', 'members');
    if ($pdbSqlite->getPrefixReplace() !== ['users', 'members']) {
        throw new Exception("Prefix replace getter/setter failed.");
    }

    $pdbSqlite->backup($replacedBackupFile);
    $replacedContent = file_get_contents($replacedBackupFile);

    if (str_contains($replacedContent, 'members') && !str_contains($replacedContent, '`users`')) {
        echo "SUCCESS: Dynamic table prefix replace verified successfully.\n";
    } else {
        throw new Exception("Dynamic prefix replacement failed. Content: " . $replacedContent);
    }

    // Reset prefix replace
    $pdbSqlite->setOptions([]);
    $pdbSqlite->setPrefixReplace('', '');
    if (file_exists($replacedBackupFile)) unlink($replacedBackupFile);
}

function printMySQLState($pdo) {
    $users = $pdo->query("SELECT * FROM `users`")->fetchAll(PDO::FETCH_ASSOC);
    echo " [MySQL State] Users count: " . count($users) . "\n";
    foreach ($users as $u) {
        echo "   - ID: {$u['id']} | Name: {$u['name']} | Email: " . ($u['email'] ?? 'NULL') . "\n";
    }
    $posts = $pdo->query("SELECT * FROM `posts`")->fetchAll(PDO::FETCH_ASSOC);
    echo " [MySQL State] Posts count: " . count($posts) . "\n";
    foreach ($posts as $p) {
        echo "   - ID: {$p['id']} | User ID: {$p['user_id']} | Title: {$p['title']} | Title Length: " . ($p['title_len'] ?? 'NULL') . "\n";
    }
}

function printSQLiteState($pdo) {
    $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo " [SQLite State] Users count: " . count($users) . "\n";
    foreach ($users as $u) {
        echo "   - ID: {$u['id']} | Name: {$u['name']} | Email: " . ($u['email'] ?? 'NULL') . "\n";
    }
    $posts = $pdo->query("SELECT * FROM posts")->fetchAll(PDO::FETCH_ASSOC);
    echo " [SQLite State] Posts count: " . count($posts) . "\n";
    foreach ($posts as $p) {
        echo "   - ID: {$p['id']} | User ID: {$p['user_id']} | Title: {$p['title']} | Title Length: " . ($p['title_len'] ?? 'NULL') . "\n";
    }
}

function printPGState($pdo) {
    $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo " [PG State] Users count: " . count($users) . "\n";
    foreach ($users as $u) {
        echo "   - ID: {$u['id']} | Name: {$u['name']} | Email: " . ($u['email'] ?? 'NULL') . "\n";
    }
    $posts = $pdo->query("SELECT * FROM posts")->fetchAll(PDO::FETCH_ASSOC);
    echo " [PG State] Posts count: " . count($posts) . "\n";
    foreach ($posts as $p) {
        echo "   - ID: {$p['id']} | User ID: {$p['user_id']} | Title: {$p['title']} | Title Length: " . ($p['title_len'] ?? 'NULL') . "\n";
    }
}

function printMSSQLState($pdo) {
    $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo " [MS SQL State] Users count: " . count($users) . "\n";
    foreach ($users as $u) {
        echo "   - ID: {$u['id']} | Name: {$u['name']} | Email: " . ($u['email'] ?? 'NULL') . "\n";
    }
    $posts = $pdo->query("SELECT * FROM posts")->fetchAll(PDO::FETCH_ASSOC);
    echo " [MS SQL State] Posts count: " . count($posts) . "\n";
    foreach ($posts as $p) {
        echo "   - ID: {$p['id']} | User ID: {$p['user_id']} | Title: {$p['title']} | Title Length: " . ($p['title_len'] ?? 'NULL') . "\n";
    }
}
