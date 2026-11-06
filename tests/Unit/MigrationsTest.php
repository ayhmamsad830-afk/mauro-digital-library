<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use Tests\TestCase;

/**
 * Static checks on the SQL migrations (no MySQL server is required):
 * ordering, engine/charset, required tables, and that every foreign key
 * references a table created earlier.
 */
final class MigrationsTest extends TestCase
{
    /** @return array<string, string> table => CREATE statement */
    private function tables(): array
    {
        $tables = [];
        foreach (glob(BASE_PATH . '/database/migrations/*.sql') as $file) {
            foreach (Migrator::splitStatements((string) file_get_contents($file)) as $stmt) {
                if (preg_match('/^CREATE TABLE\s+(\w+)/i', $stmt, $m)) {
                    $tables[$m[1]] = $stmt;
                }
            }
        }
        return $tables;
    }

    public function testRequiredCoreTablesExist(): void
    {
        $required = ['users', 'books', 'authors', 'categories', 'book_authors', 'book_categories', 'book_files',
            'reading_progress', 'reading_sessions', 'favorites', 'bookmarks', 'reviews', 'admin_activity_logs',
            'book_page_renders', 'book_render_jobs', 'oauth_audit_events', 'settings', 'rate_limits'];
        $tables = $this->tables();
        foreach ($required as $t) {
            $this->assertTrue(isset($tables[$t]), "Missing table $t");
        }
    }

    public function testEveryTableIsInnoDbUtf8mb4(): void
    {
        foreach ($this->tables() as $name => $sql) {
            $this->assertContains('ENGINE=InnoDB', $sql, "$name must use InnoDB");
            $this->assertContains('CHARSET=utf8mb4', $sql, "$name must use utf8mb4");
        }
    }

    public function testForeignKeysReferenceEarlierTables(): void
    {
        $seen = [];
        foreach (glob(BASE_PATH . '/database/migrations/*.sql') as $file) {
            foreach (Migrator::splitStatements((string) file_get_contents($file)) as $stmt) {
                if (preg_match('/^(?:CREATE TABLE|ALTER TABLE)\s+(\w+)/i', $stmt, $m)) {
                    $seen[$m[1]] = true; // self-references are allowed
                    preg_match_all('/REFERENCES\s+(\w+)\s*\(/i', $stmt, $refs);
                    foreach ($refs[1] as $ref) {
                        $this->assertTrue(isset($seen[$ref]), basename($file) . ": FK to $ref before it exists");
                    }
                }
            }
        }
    }

    public function testRequiredUniqueConstraints(): void
    {
        $t = $this->tables();
        $this->assertContains('UNIQUE KEY uq_users_google_sub (google_sub)', $t['users']);
        $this->assertContains('UNIQUE KEY uq_users_email (email)', $t['users']);
        $this->assertContains('UNIQUE KEY uq_books_slug (slug)', $t['books']);
        $this->assertContains('UNIQUE KEY uq_reading_progress_user_book (user_id, book_id)', $t['reading_progress']);
        $this->assertContains('UNIQUE KEY uq_favorites_user_book (user_id, book_id)', $t['favorites']);
        $this->assertContains('UNIQUE KEY uq_reviews_user_book (user_id, book_id)', $t['reviews']);
        $this->assertContains('CHECK (rating BETWEEN 1 AND 5)', $t['reviews']);
    }

    public function testNoBinaryPdfColumns(): void
    {
        foreach ($this->tables() as $name => $sql) {
            $this->assertFalse((bool) preg_match('/\b(LONGBLOB|MEDIUMBLOB|BLOB)\b/i', $sql), "$name must not store file bytes");
        }
    }

    public function testStatementSplitterIgnoresComments(): void
    {
        $sql = "-- header; with semicolon\nCREATE TABLE a (id INT);\n\n-- x\nCREATE TABLE b (id INT);\n";
        $this->assertSame(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'], Migrator::splitStatements($sql));
    }
}
