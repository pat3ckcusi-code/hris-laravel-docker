<?php

namespace Tests\Feature\RoleBased;

use App\Http\Controllers\HRManagerController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Regression coverage for the HR Manager database backup/restore feature.
 *
 * Covers the bug where a quote-unaware statement splitter in
 * HRManagerController::restoreDatabase() would break on free-text values
 * (e.g. Office Order memos) containing a literal `;` followed by whitespace
 * and a newline, slicing the dump mid-string-literal.
 */
class HRManagerBackupRestoreTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    protected function tearDown(): void
    {
        DB::statement('DROP TABLE IF EXISTS `hris_restore_regression_test`');

        parent::tearDown();
    }

    public function test_restore_handles_free_text_containing_semicolon_and_newline(): void
    {
        $hr = $this->createHRManager();

        $memo = "To ensure compliance; \nplease submit the report by Friday.\n\nIt's urgent; act now.";

        $sql = "-- HRIS Database Backup\n";
        $sql .= "-- Database: HRIS_test\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
        $sql .= "-- Table: `hris_restore_regression_test`\n";
        $sql .= "DROP TABLE IF EXISTS `hris_restore_regression_test`;\n";
        $sql .= "CREATE TABLE `hris_restore_regression_test` (`id` bigint unsigned NOT NULL, `details` text, PRIMARY KEY (`id`));\n\n";
        $sql .= "INSERT INTO `hris_restore_regression_test` (`id`, `details`) VALUES\n";
        $sql .= "('1', '".addslashes($memo)."');\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $file = UploadedFile::fake()->createWithContent('backup.sql', $sql);

        $response = $this->actingAs($hr)->post(route('hr-manager.settings.restore'), [
            'backup_file' => $file,
            'restore_confirm' => '1',
        ]);

        $response->assertRedirect(route('hr-manager.settings'));
        $response->assertSessionHas('success');
        $response->assertSessionDoesntHaveErrors();

        $row = DB::table('hris_restore_regression_test')->find(1);
        $this->assertNotNull($row, 'Row should have been inserted as a single statement.');
        $this->assertSame($memo, $row->details);
    }

    public function test_split_sql_statements_keeps_semicolons_inside_quoted_strings_intact(): void
    {
        $controller = app(HRManagerController::class);
        $method = new \ReflectionMethod($controller, 'splitSqlStatements');
        $method->setAccessible(true);

        // (a) a value containing "; \n" inside single quotes must not split the statement.
        $sql = "INSERT INTO t VALUES ('a; \nb');";
        $statements = $method->invoke($controller, $sql);
        $this->assertCount(1, $statements);
        $this->assertSame("INSERT INTO t VALUES ('a; \nb')", $statements[0]);

        // (b) two real statements separated by ";\n", second preceded by a comment line.
        $sql = "INSERT INTO t VALUES ('x');\n-- comment\nINSERT INTO t VALUES ('y');";
        $statements = $method->invoke($controller, $sql);
        $this->assertCount(2, $statements);
        $this->assertSame("INSERT INTO t VALUES ('x')", $statements[0]);
        $this->assertSame("INSERT INTO t VALUES ('y')", $statements[1]);

        // (c) a double-quoted string containing "; \n".
        $sql = 'INSERT INTO t VALUES ("a; '."\n".'b");';
        $statements = $method->invoke($controller, $sql);
        $this->assertCount(1, $statements);

        // (d) an escaped quote immediately before the real closing quote must not
        // terminate the string early.
        $sql = "INSERT INTO t VALUES ('back\\'slash');";
        $statements = $method->invoke($controller, $sql);
        $this->assertCount(1, $statements);
    }

    public function test_restore_skips_row_already_inserted_concurrently_instead_of_aborting(): void
    {
        $hr = $this->createHRManager();

        // No DROP/CREATE TABLE here: the table is created below and already
        // holds row '1', simulating a concurrent writer (e.g. the attendance
        // auto-import cron, which runs every minute) inserting that same row
        // sometime between the restore's own DROP+CREATE and its INSERT for
        // this table. The restore's INSERT below must skip the collision
        // rather than aborting the rest of the restore.
        $sql = "-- HRIS Database Backup\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
        // Two rows in one statement, same as the chunked multi-row INSERT the
        // backup generator emits.
        $sql .= "INSERT INTO `hris_restore_regression_test` (`id`, `details`) VALUES\n";
        $sql .= "('1', 'already there'),\n";
        $sql .= "('2', 'brand new');\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        DB::statement('DROP TABLE IF EXISTS `hris_restore_regression_test`');
        DB::statement('CREATE TABLE `hris_restore_regression_test` (`id` bigint unsigned NOT NULL, `details` text, PRIMARY KEY (`id`))');
        DB::table('hris_restore_regression_test')->insert(['id' => 1, 'details' => 'pre-existing from concurrent writer']);

        $file = UploadedFile::fake()->createWithContent('backup.sql', $sql);

        $response = $this->actingAs($hr)->post(route('hr-manager.settings.restore'), [
            'backup_file' => $file,
            'restore_confirm' => '1',
        ]);

        $response->assertRedirect(route('hr-manager.settings'));
        $response->assertSessionHas('success');
        $response->assertSessionDoesntHaveErrors();

        // The pre-existing row is left untouched (IGNORE keeps the original,
        // it doesn't overwrite it) and the rest of the statement still lands.
        $this->assertSame('pre-existing from concurrent writer', DB::table('hris_restore_regression_test')->find(1)->details);
        $this->assertSame('brand new', DB::table('hris_restore_regression_test')->find(2)->details);
    }
}
