<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use Tests\TestCase;

/** The SQLite harness schema must expose exactly the same tables and columns as the MySQL migrations. */
final class SchemaParityTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function columns(string $sql): array
    {
        $out = [];
        foreach (Migrator::splitStatements($sql) as $stmt) {
            // Later migrations add columns with "ALTER TABLE t ADD COLUMN c TYPE".
            if (preg_match('/^ALTER TABLE\s+(\w+)\s+ADD COLUMN\s+([a-z_][a-z0-9_]*)\s+/is', $stmt, $a)) {
                $out[strtolower($a[1])][] = strtolower($a[2]);
                sort($out[strtolower($a[1])]);
                continue;
            }
            if (!preg_match('/^CREATE TABLE\s+(\w+)\s*\((.*)\)[^)]*$/is', $stmt, $m)) {
                continue;
            }
            $cols = [];
            foreach (preg_split('/,\s*\n/', $m[2]) as $line) {
                if (preg_match('/^\s*([a-z_][a-z0-9_]*)\s+(BIGINT|INT|INTEGER|VARCHAR|CHAR|TEXT|MEDIUMTEXT|DATETIME|ENUM|TINYINT|SMALLINT|DECIMAL|REAL)\b/i', $line, $c)) {
                    $cols[] = strtolower($c[1]);
                }
            }
            sort($cols);
            $out[strtolower($m[1])] = $cols;
        }
        ksort($out);
        return $out;
    }

    public function testSqliteMirrorMatchesMysqlMigrations(): void
    {
        $mysql = '';
        foreach (glob(BASE_PATH . '/database/migrations/*.sql') as $f) {
            $mysql .= file_get_contents($f) . "\n";
        }
        $my = $this->columns($mysql);
        $lite = $this->columns((string) file_get_contents(BASE_PATH . '/database/sqlite/schema.sql'));
        $this->assertSame(array_keys($my), array_keys($lite), 'table sets differ');
        foreach ($my as $table => $cols) {
            $this->assertSame($cols, $lite[$table], "columns differ in $table");
        }
        $this->assertTrue(count($my['books']) > 20);
    }
}
