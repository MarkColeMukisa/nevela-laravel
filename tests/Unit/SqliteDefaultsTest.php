<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Support\SqliteDefaults;
use PHPUnit\Framework\TestCase;

final class SqliteDefaultsTest extends TestCase
{
    /** Laravel's own config/database.php, as a new app has it. */
    private const LARAVEL = [
        'sqlite' => ['driver' => 'sqlite', 'database' => 'database.sqlite', 'busy_timeout' => null, 'journal_mode' => null, 'synchronous' => null, 'transaction_mode' => 'DEFERRED'],
        'pgsql' => ['driver' => 'pgsql', 'host' => '127.0.0.1'],
    ];

    public function test_sqlite_as_laravel_leaves_it_waits_for_writes_and_lets_readers_through(): void
    {
        $connections = SqliteDefaults::apply(self::LARAVEL);

        $this->assertSame(5000, $connections['sqlite']['busy_timeout']);
        $this->assertSame('wal', $connections['sqlite']['journal_mode']);
        $this->assertSame('normal', $connections['sqlite']['synchronous']);
        $this->assertSame('IMMEDIATE', $connections['sqlite']['transaction_mode']);
        // Another database is left as it is.
        $this->assertSame(self::LARAVEL['pgsql'], $connections['pgsql']);
    }

    public function test_what_the_app_set_itself_is_kept_and_the_environment_has_the_last_word(): void
    {
        $own = self::LARAVEL;
        $own['sqlite']['busy_timeout'] = 20000;
        $own['sqlite']['journal_mode'] = 'delete';
        $own['sqlite']['transaction_mode'] = 'EXCLUSIVE';
        $connections = SqliteDefaults::apply($own, ['busy_timeout' => '9000', 'journal_mode' => null, 'synchronous' => 'full', 'transaction_mode' => '']);

        $this->assertSame(9000, $connections['sqlite']['busy_timeout']);
        $this->assertSame('delete', $connections['sqlite']['journal_mode']);
        $this->assertSame('full', $connections['sqlite']['synchronous']);
        $this->assertSame('EXCLUSIVE', $connections['sqlite']['transaction_mode']);
    }
}
