<?php

namespace App\Services\Ops;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Daily database backups (mysqldump, or plain PHP where the host doesn't allow it; gzip, sha256, row counts per table), and a restore drill that loads the newest
 * backup into a separate scratch database and checks every table came back. MySQL/MariaDB only.
 */
class Backups
{
    public const KEEP = 14;

    public function dir(): string
    {
        $dir = (string) (config('sendline.backup_path') ?: storage_path('app/backups'));
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return rtrim($dir, '/');
    }

    /** @return array<string, mixed> the ops_backups row */
    public function run(): array
    {
        $id = DB::table('ops_backups')->insertGetId(['status' => 'running', 'started_at' => now()]);
        try {
            $c = $this->connection();
            $tables = $this->counts(DB::connection());
            $file = $this->dir().'/sendline-'.now()->format('Ymd-His').'.sql.gz';
            if (! $this->mysqldump($c, $file)) {
                $this->phpDump(DB::connection(), $file); // shared hosting without mysqldump or proc_open
            }
            if (! is_file($file) || filesize($file) < 100) {
                @unlink($file);
                throw new \RuntimeException('The backup file came out empty.');
            }
            DB::table('ops_backups')->where('id', $id)->update(['status' => 'ok', 'file' => basename($file), 'size' => filesize($file), 'sha256' => hash_file('sha256', $file),
                'tables' => json_encode($tables), 'finished_at' => now()]);
            $this->prune();
            $this->copyOffsite($id, $file);
        } catch (Throwable $e) {
            DB::table('ops_backups')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
        }

        return (array) DB::table('ops_backups')->find($id);
    }

    /** Copies a finished backup to the off-site bucket, when one is set up. A failure is recorded (and alerted on), not thrown. */
    public function copyOffsite(int $id, string $file): void
    {
        if (! Offsite::configured()) {
            return;
        }
        try {
            DB::table('ops_backups')->where('id', $id)->update(['offsite_status' => 'ok', 'offsite_key' => Offsite::upload($file), 'offsite_error' => null, 'offsite_at' => now()]);
        } catch (Throwable $e) {
            DB::table('ops_backups')->where('id', $id)->update(['offsite_status' => 'failed', 'offsite_error' => Offsite::explain($e), 'offsite_at' => now()]);
        }
    }

    /**
     * Restores the newest good backup into the drill database and compares row counts with the backup's own.
     *
     * @return array<string, mixed> the ops_backups row, with drill_* filled in
     */
    public function drill(): array
    {
        $b = DB::table('ops_backups')->where('status', 'ok')->orderByDesc('id')->first();
        if (! $b) {
            return ['drill_status' => 'failed', 'drill_detail' => 'There’s no backup to restore yet.'];
        }
        $finish = function (string $status, string $detail) use ($b) {
            DB::table('ops_backups')->where('id', $b->id)->update(['drill_status' => $status, 'drill_detail' => mb_substr($detail, 0, 500), 'drill_at' => now()]);

            return (array) DB::table('ops_backups')->find($b->id);
        };
        try {
            $d = (array) config('sendline.backup_drill');
            if (empty($d['database']) || empty($d['username'])) {
                return $finish('failed', 'No drill database is set up (BACKUP_DRILL_DB, BACKUP_DRILL_USER, BACKUP_DRILL_PASSWORD).');
            }
            if ($d['database'] === $this->connection()['database']) {
                return $finish('failed', 'The drill database must not be the live database.');
            }
            $file = $this->dir().'/'.$b->file;
            if (! is_file($file) || hash_file('sha256', $file) !== $b->sha256) {
                return $finish('failed', 'The backup file is missing or its checksum doesn’t match.');
            }
            Config::set('database.connections.drill', array_merge((array) config('database.connections.'.config('database.default')), [
                'host' => $d['host'] ?: $this->connection()['host'], 'port' => $d['port'] ?: $this->connection()['port'], 'database' => $d['database'], 'username' => $d['username'], 'password' => (string) $d['password']]));
            DB::purge('drill');
            $drill = DB::connection('drill');
            $this->empty($drill);
            $r = Process::env(['MYSQL_PWD' => (string) $d['password'], 'IN' => $file])->timeout(3600)->run(['bash', '-c',
                'set -o pipefail; gunzip -c "$IN" | mysql -h "$0" -P "$1" -u "$2" "$3"', (string) ($d['host'] ?: $this->connection()['host']), (string) ($d['port'] ?: $this->connection()['port']), (string) $d['username'], (string) $d['database']]);
            if (! $r->successful()) {
                return $finish('failed', 'Restoring failed: '.mb_substr(trim($r->errorOutput()), 0, 300));
            }
            $problems = self::compare((array) json_decode((string) $b->tables, true), $this->counts($drill));
            $restored = array_sum($this->counts($drill));
            $this->empty($drill); // don't keep a second copy of everyone's data around

            return $problems ? $finish('failed', implode(' ', array_slice($problems, 0, 5)))
                : $finish('ok', 'Restored '.count((array) json_decode((string) $b->tables, true)).' tables and '.number_format($restored).' rows from '.$b->file.'.');
        } catch (Throwable $e) {
            return $finish('failed', $e->getMessage());
        }
    }

    /**
     * Tables must all come back; row counts may differ only by what was written while the backup ran.
     *
     * @param  array<string, int>  $expected  counts recorded when the backup started
     * @param  array<string, int>  $restored
     * @return list<string> problems (empty = the drill passed)
     */
    public static function compare(array $expected, array $restored): array
    {
        $problems = [];
        foreach ($expected as $table => $n) {
            if (! array_key_exists($table, $restored)) {
                $problems[] = "Table $table is missing.";

                continue;
            }
            $tolerance = max(50, (int) ceil($n * 0.01));
            if ($restored[$table] < $n - $tolerance) {
                $problems[] = "Table $table has {$restored[$table]} rows, expected about $n.";
            }
        }

        return $problems;
    }

    /**
     * mysqldump when the server lets PHP run it; false when it can't (the PHP dump takes over).
     *
     * @param  array{host: string, port: string|int, database: string, username: string, password: string}  $c
     */
    private function mysqldump(array $c, string $file): bool
    {
        if (! self::canRunCommands()) {
            return false;
        }
        try {
            $r = Process::env(['MYSQL_PWD' => $c['password'], 'OUT' => $file])->timeout(3600)->run(['bash', '-c',
                'set -o pipefail; umask 077; mysqldump --single-transaction --quick --no-tablespaces -h "$0" -P "$1" -u "$2" "$3" | gzip > "$OUT"', $c['host'], (string) $c['port'], $c['username'], $c['database']]);
            if ($r->successful() && is_file($file) && filesize($file) >= 100) {
                return true;
            }
        } catch (Throwable) {
        }
        @unlink($file);

        return false;
    }

    public static function canRunCommands(): bool
    {
        return function_exists('proc_open') && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    /** The same kind of .sql.gz file mysqldump writes (tables, then rows in batches), in plain PHP. */
    private function phpDump(Connection $db, string $file): void
    {
        $old = umask(077);
        $gz = gzopen($file, 'wb6');
        umask($old);
        if ($gz === false) {
            throw new \RuntimeException("Couldn’t write $file.");
        }
        $pdo = $db->getPdo();
        gzwrite($gz, '-- MailVesper backup '.now()->toIso8601String()."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($db->select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'') as $row) {
            $t = str_replace('`', '', (string) array_values((array) $row)[0]);
            $create = (array) $db->selectOne("SHOW CREATE TABLE `$t`");
            gzwrite($gz, "\nDROP TABLE IF EXISTS `$t`;\n".array_values($create)[1].";\n");
            $key = array_column($db->select("SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'"), 'Column_name');
            $order = $key ? ' ORDER BY '.implode(', ', array_map(fn ($k) => '`'.str_replace('`', '', (string) $k).'`', $key)) : '';
            for ($offset = 0; ; $offset += 500) {
                $rows = $pdo->query("SELECT * FROM `$t`$order LIMIT 500 OFFSET $offset")->fetchAll(\PDO::FETCH_ASSOC);
                if (! $rows) {
                    break;
                }
                $cols = '(`'.implode('`, `', array_map(fn ($c) => str_replace('`', '', (string) $c), array_keys($rows[0]))).'`)';
                $values = array_map(fn ($r) => '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : (mb_check_encoding((string) $v, 'UTF-8') ? $pdo->quote((string) $v) : '0x'.bin2hex((string) $v)), $r)).')', $rows);
                gzwrite($gz, "INSERT INTO `$t` $cols VALUES\n".implode(",\n", $values).";\n");
                if (count($rows) < 500) {
                    break;
                }
            }
        }
        gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    }

    /** @return array<string, int> */
    private function counts(Connection $db): array
    {
        $out = [];
        foreach ($db->select('SHOW TABLES') as $row) {
            $t = (string) array_values((array) $row)[0];
            $out[$t] = (int) $db->table($t)->count();
        }
        ksort($out);

        return $out;
    }

    private function empty(Connection $db): void
    {
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->select('SHOW TABLES') as $row) {
            $db->statement('DROP TABLE IF EXISTS `'.str_replace('`', '', (string) array_values((array) $row)[0]).'`');
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @return array{host: string, port: string|int, database: string, username: string, password: string} */
    private function connection(): array
    {
        $c = (array) config('database.connections.'.config('database.default'));
        if (! in_array($c['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Backups need MySQL or MariaDB (this installation uses '.($c['driver'] ?? 'unknown').').');
        }

        return ['host' => (string) ($c['host'] ?? '127.0.0.1'), 'port' => $c['port'] ?? 3306, 'database' => (string) $c['database'], 'username' => (string) $c['username'], 'password' => (string) ($c['password'] ?? '')];
    }

    private function prune(): void
    {
        $keep = DB::table('ops_backups')->where('status', 'ok')->orderByDesc('id')->limit(self::KEEP)->pluck('id');
        foreach (DB::table('ops_backups')->where('status', 'ok')->whereNotIn('id', $keep)->whereNotNull('file')->get() as $old) {
            @unlink($this->dir().'/'.$old->file);
            DB::table('ops_backups')->where('id', $old->id)->update(['file' => null]);
        }
        DB::table('ops_backups')->where('started_at', '<', now()->subDays(90))->delete();
    }
}
