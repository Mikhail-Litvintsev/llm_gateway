<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

class CreateTestDatabase extends Command
{
    protected $signature = 'llm:create-test-db
        {--host=127.0.0.1 : Database host}
        {--port=3307 : Database port}
        {--root-password=root_secret : Root password}';

    protected $description = 'Create the test database if it does not exist';

    public function handle(): int
    {
        $host = $this->option('host');
        $port = $this->option('port');
        $rootPassword = $this->option('root-password');
        $dbName = 'llm_gateway_test';
        $user = config('database.connections.mysql.username', 'llm_user');

        try {
            $pdo = new PDO("mysql:host={$host};port={$port}", 'root', $rootPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$user}'@'%'");
            $pdo->exec('FLUSH PRIVILEGES');

            $this->info("Database '{$dbName}' is ready.");

            return self::SUCCESS;
        } catch (\PDOException $e) {
            $this->error("Failed to create test database: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
