<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Einmaliger Datenimport aus der alten SQLite-Datenbank in die aktuelle
 * (Postgres-)Datenbank. Kopiert Tabellen in FK-sicherer Reihenfolge und
 * setzt anschliessend die Sequenzen fuer Auto-Increment-Spalten.
 */
class WwcImportSqliteCommand extends Command
{
    protected $signature = 'wwc:import-sqlite
        {path=database/database.sqlite : Pfad zur SQLite-Datei}
        {--force : Auch importieren, wenn Zieltabellen bereits Daten enthalten}';

    protected $description = 'Importiert Daten aus einer SQLite-Datei in die aktuelle Datenbank';

    /** FK-sichere Reihenfolge; nicht gelistete Laufzeit-Tabellen (cache, jobs, sessions) werden uebersprungen. */
    private const TABLES = [
        'users',
        'password_reset_tokens',
        'organizations',
        'memberships',
        'clients',
        'projects',
        'sites',
        'pairing_codes',
        'agent_jobs',
        'site_events',
        'audit_logs',
        'vulnerabilities',
        'vulnerability_findings',
        'invoice_sequences',
        'invoices',
        'invoice_items',
        'maintenance_runs',
        'site_backups',
        'personal_access_tokens',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            $this->error("SQLite-Datei nicht gefunden: {$path}");

            return self::FAILURE;
        }

        config(['database.connections.wwc_sqlite_import' => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        $source = DB::connection('wwc_sqlite_import');
        $target = DB::connection();

        foreach (self::TABLES as $table) {
            if (! Schema::connection('wwc_sqlite_import')->hasTable($table) || ! Schema::hasTable($table)) {
                $this->line("- {$table}: uebersprungen (Tabelle fehlt)");

                continue;
            }

            $existing = (int) $target->table($table)->count();
            if ($existing > 0 && ! $this->option('force')) {
                $this->line("- {$table}: uebersprungen (Ziel enthaelt bereits {$existing} Zeilen)");

                continue;
            }

            $boolColumns = $this->booleanColumns($table);
            $rows = $source->table($table)->get();
            $count = 0;
            foreach ($rows->chunk(200) as $chunk) {
                $payload = $chunk->map(function ($row) use ($boolColumns) {
                    $data = (array) $row;
                    foreach ($boolColumns as $col) {
                        if (array_key_exists($col, $data) && $data[$col] !== null) {
                            $data[$col] = (bool) $data[$col];
                        }
                    }

                    return $data;
                })->all();
                $target->table($table)->insert($payload);
                $count += count($payload);
            }
            $this->info("- {$table}: {$count} Zeilen importiert");
        }

        $this->fixSequences();
        $this->info('Import abgeschlossen.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function booleanColumns(string $table): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        return DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('data_type', 'boolean')
            ->pluck('column_name')
            ->all();
    }

    private function fixSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_default', 'like', 'nextval%')
            ->get(['table_name', 'column_name']);

        foreach ($columns as $col) {
            if (! in_array($col->table_name, self::TABLES, true)) {
                continue;
            }
            DB::statement(sprintf(
                "SELECT setval(pg_get_serial_sequence('%s', '%s'), COALESCE((SELECT MAX(%s) FROM %s), 0) + 1, false)",
                $col->table_name,
                $col->column_name,
                $col->column_name,
                $col->table_name
            ));
        }
        $this->line('- Sequenzen aktualisiert');
    }
}
