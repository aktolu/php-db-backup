<?php

namespace aktolu\Drivers;

interface DriverInterface
{
    /**
     * Backup the database to a file
     * 
     * @param string $destinationFilePath Path to the backup file
     * @param array $options Optional configurations for the backup process
     * @return bool True on success, false on failure
     */
    public function backup(string $destinationFilePath, array $options = []): bool;

    /**
     * Restore the database from a backup file
     * 
     * @param string $sourceFilePath Path to the backup file
     * @param array $options Optional configurations for the restore process
     * @return bool True on success, false on failure
     */
    public function restore(string $sourceFilePath, array $options = []): bool;
}
