<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * SQL-Dump der eigenen Datenbank in reinem PHP, gzip-komprimiert – wie in T1X.
 * Im Web-PHP des Shared Hosts ist proc_open gesperrt, und mysqldump bekäme das
 * Passwort in der Kommandozeile mit, die andere Kunden auf dem Server sehen.
 * Alle Tabellen werden in einer Transaktion gelesen, also als ein Stand (wie
 * mysqldump --single-transaction). Einspielen: `gunzip -c datei.sql.gz | mysql …`
 *
 * Sitzungen, Cache, Warteschlangen und Links zum Zurücksetzen des Passworts
 * stehen nur als Struktur darin – nach einer Wiederherstellung meldet sich
 * jeder neu an.
 */
final class DatabaseDump
{
    /** Tabellen ohne Daten im Dump */
    private const WITHOUT_DATA = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /** Zeilen je INSERT */
    private const BATCH = 200;

    public static function toFile(string $path): void
    {
        $connection = DB::connection();
        $mysql = $connection->getDriverName() === 'mysql';
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Die Dump-Datei lässt sich nicht anlegen.');
        }
        $write = function (string $sql) use ($gz): void {
            if (gzwrite($gz, $sql) === false) {
                throw new RuntimeException('Der Dump lässt sich nicht schreiben.');
            }
        };

        $connection->beginTransaction();
        try {
            $write(sprintf("-- VenueControl %s, Datenbank %s, Stand %s\n", parse_url((string) config('app.url'), PHP_URL_HOST), $connection->getDatabaseName(), now()->format('d.m.Y H:i:s')));
            $write($mysql ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" : "PRAGMA foreign_keys=OFF;\n\n");
            foreach (self::tables($connection) as $table => $create) {
                $write("DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
                if (!in_array($table, self::WITHOUT_DATA, true)) {
                    self::rows($connection, $table, $write);
                }
            }
            $write($mysql ? "SET FOREIGN_KEY_CHECKS=1;\n" : "PRAGMA foreign_keys=ON;\n");
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($path);

            throw $e;
        } finally {
            // Nur gelesen – nichts festzuschreiben
            $connection->rollBack();
        }

        if (!gzclose($gz)) {
            throw new RuntimeException('Der Dump lässt sich nicht abschließen.');
        }
    }

    /** @return array<string, string> Tabelle => CREATE TABLE */
    private static function tables(Connection $connection): array
    {
        // In den Tests SQLite im Speicher
        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"))
                ->mapWithKeys(fn (object $row): array => [$row->name => $row->sql])
                ->all();
        }

        $tables = [];
        foreach ($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $table = (string) array_values((array) $row)[0];
            $tables[$table] = (string) $connection->selectOne("SHOW CREATE TABLE `{$table}`")->{'Create Table'};
        }
        ksort($tables);

        return $tables;
    }

    /** @param  Closure(string): void  $write */
    private static function rows(Connection $connection, string $table, Closure $write): void
    {
        $columns = $connection->getSchemaBuilder()->getColumnListing($table);
        if ($columns === []) {
            return;
        }

        $pdo = $connection->getPdo();
        $insert = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES\n";
        $query = $connection->table($table);
        // Mit ID seitenweise, sonst (Pivot-Tabellen, Einstellungen) in einem Zug
        $rows = in_array('id', $columns, true) ? $query->lazyById(self::BATCH) : $query->cursor();

        $batch = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $batch[] = '(' . implode(', ', array_map(
                fn (string $column): string => $row[$column] === null ? 'NULL' : $pdo->quote((string) $row[$column]),
                $columns,
            )) . ')';
            if (count($batch) === self::BATCH) {
                $write($insert . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch !== []) {
            $write($insert . implode(",\n", $batch) . ";\n");
        }
        $write("\n");
    }
}
