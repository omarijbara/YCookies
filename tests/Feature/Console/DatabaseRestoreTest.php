<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseRestoreTest extends TestCase
{
    protected string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = storage_path('framework/testing/database-restore-test.sqlite');

        File::ensureDirectoryExists(dirname($this->databasePath));
        File::delete($this->databasePath);
        File::put($this->databasePath, '');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'backup.backup.database_dump_compressor' => null,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Storage::disk('local')->deleteDirectory($this->backupDirectory());

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    #[Test]
    public function backup_run_only_db_completes_successfully(): void
    {
        // Clean up any previous test backups
        Storage::disk('local')->deleteDirectory(
            config('backup.backup.name', 'laravel-backup')
        );

        $this->artisan('backup:run', ['--only-db' => true, '--disable-notifications' => true])
            ->assertSuccessful();

        // Verify the backup zip was created
        $this->assertNotNull($this->latestBackupZip(), 'A backup zip file should exist after backup:run');
    }

    #[Test]
    public function backup_zip_contains_database_dump(): void
    {
        // Ensure we have a backup
        Storage::disk('local')->deleteDirectory(
            config('backup.backup.name', 'laravel-backup')
        );

        $this->artisan('backup:run', ['--only-db' => true, '--disable-notifications' => true])
            ->assertSuccessful();

        // Open the zip and verify it contains a SQL dump
        $content = $this->readSqlDumpFromLatestBackup();

        $this->assertNotEmpty($content, 'SQL dump should not be empty');
        $this->assertTrue(
            str_contains($content, 'CREATE TABLE') || str_contains($content, 'INSERT'),
            'SQL dump should contain DDL or DML statements'
        );
    }

    #[Test]
    public function backup_restore_produces_valid_schema(): void
    {
        // Step 1: Create a backup of the current (migrated) database
        Storage::disk('local')->deleteDirectory(
            config('backup.backup.name', 'laravel-backup')
        );

        $this->artisan('backup:run', ['--only-db' => true, '--disable-notifications' => true])
            ->assertSuccessful();

        // Step 2: Extract the SQL dump from the backup
        $sqlContent = $this->readSqlDumpFromLatestBackup();

        // Step 3: Restore into a fresh in-memory SQLite database
        $restoreDb = new \PDO('sqlite::memory:');
        $restoreDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Execute the SQL dump — SQLite dump is a series of statements
        $restoreDb->exec($sqlContent);

        // Step 4: Verify critical tables exist in the restored database
        $criticalTables = ['users', 'domains', 'groups', 'consent_logs', 'services', 'cookie_groups'];
        foreach ($criticalTables as $table) {
            $result = $restoreDb->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'"
            )->fetch();

            $this->assertNotFalse(
                $result,
                "Restored database should contain the '{$table}' table"
            );
        }
    }

    #[Test]
    public function backup_clean_command_runs_successfully(): void
    {
        $this->artisan('backup:clean', ['--disable-notifications' => true])
            ->assertSuccessful();
    }

    #[Test]
    public function backup_list_command_runs_successfully(): void
    {
        $this->artisan('backup:list')
            ->assertSuccessful();
    }

    protected function tearDown(): void
    {
        // Clean up test backup files
        Storage::disk('local')->deleteDirectory($this->backupDirectory());
        File::delete($this->databasePath);

        parent::tearDown();
    }

    protected function backupDirectory(): string
    {
        return config('backup.backup.name', 'laravel-backup');
    }

    protected function latestBackupZip(): ?string
    {
        $files = Storage::disk('local')->allFiles($this->backupDirectory());
        $zips = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.zip')));

        if ($zips === []) {
            return null;
        }

        sort($zips);

        return end($zips) ?: null;
    }

    protected function readSqlDumpFromLatestBackup(): string
    {
        $latestZip = $this->latestBackupZip();

        $this->assertNotNull($latestZip, 'Backup zip should exist');

        $zipPath = Storage::disk('local')->path($latestZip);
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::RDONLY);

        $this->assertTrue($opened === true, 'Backup zip should be openable');

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $content = $zip->getFromIndex($i);

                if ($content === false) {
                    continue;
                }

                if (str_ends_with($name, '.sql.gz')) {
                    $decoded = gzdecode($content);

                    if ($decoded !== false) {
                        return $decoded;
                    }

                    continue;
                }

                if (str_ends_with($name, '.sql')) {
                    return $content;
                }
            }
        } finally {
            $zip->close();
        }

        $this->fail('Backup zip should contain a SQL dump entry.');
    }
}
