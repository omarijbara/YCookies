<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScannerWorkerTimeoutTest extends TestCase
{
    public function test_scanner_worker_timeout_sigkill_behavior()
    {
        // 1. Setup a temporary physical SQLite database
        $dbPath = database_path('testing_queue_timeout.sqlite');
        File::put($dbPath, '');
        
        // 2. Override DB configuration for this test
        Config::set('database.connections.testing_sqlite', [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'testing_sqlite');
        
        // 3. Migrate the physical DB
        $this->artisan('migrate:fresh', ['--database' => 'testing_sqlite'])->run();

        // 4. Set Queue connection explicitly to physical DB
        Config::set('queue.connections.database.connection', 'testing_sqlite');
        Config::set('queue.default', 'database');

        DB::connection('testing_sqlite')->table('jobs')->insert([
            'queue' => 'scanner_test_queue',
            'payload' => json_encode([
                'uuid' => 'test-uuid-timeout',
                'displayName' => 'Tests\Feature\SleepMockJob',
                'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                'maxTries' => 1,
                'timeout' => 1,
                'data' => [
                    'commandName' => 'Tests\Feature\SleepMockJob',
                    'command' => serialize(new SleepMockJob()),
                ],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        // 5. Run the worker as a subprocess passing the test database path
        $process = new Process([
            PHP_BINARY, 'artisan', 'queue:work', 
            '--queue=scanner_test_queue', 
            '--timeout=1', 
            '--tries=1', 
            '--stop-when-empty',
            '--sleep=1'
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $dbPath,
            'QUEUE_CONNECTION' => 'database',
        ]);
        
        try {
            $process->run();
        } catch (ProcessSignaledException $e) {
            // Linux + posix: Worker::kill() uses SIGKILL on self after job timeout.
            $this->assertSame(9, $e->getSignal(), 'Expected worker to exit via SIGKILL after timeout');
        }
        $output = $process->getOutput() . $process->getErrorOutput();

        // Assert that the job is marked as failed in the database
        $this->assertDatabaseHas('failed_jobs', [
            'queue' => 'scanner_test_queue',
        ], 'testing_sqlite');

        // Cleanup
        File::delete($dbPath);
    }
}

class SleepMockJob {
    public function handle() {
        // Sleep for 3 seconds, triggering the 1-second queue worker timeout
        sleep(3);
    }
}
