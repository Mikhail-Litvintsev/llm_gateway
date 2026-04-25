<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ClaudeWorkspace;
use App\Models\Client;
use App\Models\Session;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class KeysReencrypt extends Command
{
    protected $signature = 'keys:reencrypt
        {--dry-run : Report what would be re-encrypted without writing}';

    protected $description = 'Re-encrypt all encrypted columns after APP_KEY rotation. Reads the previous key from APP_OLD_KEY env var.';

    public function handle(): int
    {
        $oldKeyRaw = (string) ($_SERVER['APP_OLD_KEY'] ?? $_ENV['APP_OLD_KEY'] ?? getenv('APP_OLD_KEY') ?: '');

        if ($oldKeyRaw === '') {
            $this->error('APP_OLD_KEY environment variable is required.');
            $this->line('Set it to the previous APP_KEY (the one used to encrypt existing rows) and re-run:');
            $this->line('  APP_OLD_KEY=base64:... php artisan keys:reencrypt');

            return self::FAILURE;
        }

        try {
            $oldEncrypter = $this->buildEncrypter($oldKeyRaw);
        } catch (\RuntimeException $e) {
            $this->error('APP_OLD_KEY is not a valid encryption key: '.$e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totals = ['ok' => 0, 'reencrypted' => 0, 'failed' => 0];

        foreach ($this->reencryptWorkspaces($oldEncrypter, $dryRun) as $key => $count) {
            $totals[$key] += $count;
        }

        foreach ($this->reencryptClients($oldEncrypter, $dryRun) as $key => $count) {
            $totals[$key] += $count;
        }

        foreach ($this->reencryptSessions($oldEncrypter, $dryRun) as $key => $count) {
            $totals[$key] += $count;
        }

        $this->newLine();
        $this->line(sprintf(
            '%s — already current: %d, re-encrypted: %d, failed: %d',
            $dryRun ? 'DRY RUN summary' : 'Summary',
            $totals['ok'],
            $totals['reencrypted'],
            $totals['failed'],
        ));

        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{ok: int, reencrypted: int, failed: int}
     */
    private function reencryptWorkspaces(Encrypter $oldEncrypter, bool $dryRun): array
    {
        $counts = ['ok' => 0, 'reencrypted' => 0, 'failed' => 0];

        ClaudeWorkspace::query()->each(function (ClaudeWorkspace $row) use ($oldEncrypter, $dryRun, &$counts): void {
            $outcome = $this->reencryptValue($row->api_key_encrypted, $oldEncrypter);

            $this->reportRow('claude_workspaces', $row->id, $outcome);

            if ($outcome['action'] === 'reencrypted' && $outcome['new'] !== null && ! $dryRun) {
                $row->api_key_encrypted = $outcome['new'];
                $row->saveQuietly();
            }

            $counts[$outcome['key']]++;
        });

        return $counts;
    }

    /**
     * @return array{ok: int, reencrypted: int, failed: int}
     */
    private function reencryptClients(Encrypter $oldEncrypter, bool $dryRun): array
    {
        $counts = ['ok' => 0, 'reencrypted' => 0, 'failed' => 0];

        Client::query()->withTrashed()->each(function (Client $row) use ($oldEncrypter, $dryRun, &$counts): void {
            foreach (['signing_secret_current_encrypted', 'signing_secret_previous_encrypted'] as $column) {
                if ($row->{$column} === null) {
                    continue;
                }

                $outcome = $this->reencryptValue($row->{$column}, $oldEncrypter);

                $this->reportRow("clients.$column", $row->id, $outcome);

                if ($outcome['action'] === 'reencrypted' && $outcome['new'] !== null && ! $dryRun) {
                    $row->{$column} = $outcome['new'];
                    $row->saveQuietly();
                }

                $counts[$outcome['key']]++;
            }
        });

        return $counts;
    }

    /**
     * @return array{ok: int, reencrypted: int, failed: int}
     */
    private function reencryptSessions(Encrypter $oldEncrypter, bool $dryRun): array
    {
        $counts = ['ok' => 0, 'reencrypted' => 0, 'failed' => 0];

        Session::query()->whereNotNull('mcp_servers')->each(function (Session $row) use ($oldEncrypter, $dryRun, &$counts): void {
            $servers = $row->mcp_servers;
            if (! is_array($servers)) {
                return;
            }

            $changed = false;
            foreach ($servers as &$server) {
                if (! isset($server['authorization_token']) || ! is_string($server['authorization_token'])) {
                    continue;
                }

                $outcome = $this->reencryptValue($server['authorization_token'], $oldEncrypter);

                $this->reportRow('sessions.mcp_servers.authorization_token', $row->id, $outcome);

                if ($outcome['action'] === 'reencrypted') {
                    $server['authorization_token'] = $outcome['new'];
                    $changed = true;
                }

                $counts[$outcome['key']]++;
            }
            unset($server);

            if ($changed && ! $dryRun) {
                DB::table('sessions')->where('id', $row->id)->update(['mcp_servers' => json_encode($servers)]);
            }
        });

        return $counts;
    }

    /**
     * @return array{key: 'ok'|'reencrypted'|'failed', action: 'ok'|'reencrypted'|'failed', new: ?string, error: ?string}
     */
    private function reencryptValue(string $payload, Encrypter $oldEncrypter): array
    {
        try {
            Crypt::decryptString($payload);

            return ['key' => 'ok', 'action' => 'ok', 'new' => null, 'error' => null];
        } catch (DecryptException) {
            // Fall through to old-key attempt.
        }

        try {
            $plain = $oldEncrypter->decryptString($payload);
        } catch (DecryptException $e) {
            return ['key' => 'failed', 'action' => 'failed', 'new' => null, 'error' => $e->getMessage()];
        }

        return [
            'key' => 'reencrypted',
            'action' => 'reencrypted',
            'new' => Crypt::encryptString($plain),
            'error' => null,
        ];
    }

    /**
     * @param  array{key: string, action: string, new: ?string, error: ?string}  $outcome
     */
    private function reportRow(string $location, int $id, array $outcome): void
    {
        $verbosity = match ($outcome['action']) {
            'failed' => 'error',
            'reencrypted' => 'info',
            default => 'verbose',
        };

        $message = "[{$outcome['action']}] $location#$id".($outcome['error'] !== null ? ": {$outcome['error']}" : '');

        match ($verbosity) {
            'error' => $this->error($message),
            'info' => $this->info($message),
            default => $this->line($message, null, 'v'),
        };
    }

    private function buildEncrypter(string $rawKey): Encrypter
    {
        $key = str_starts_with($rawKey, 'base64:')
            ? base64_decode(substr($rawKey, 7))
            : $rawKey;

        return new Encrypter($key, (string) config('app.cipher'));
    }
}
