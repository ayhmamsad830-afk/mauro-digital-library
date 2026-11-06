<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Models\Slug;
use App\Repositories\LibraryRepositoryFactory;
use App\Repositories\MySqlLibraryRepository;
use App\Repositories\UnavailableLibraryRepository;
use App\Demo\DemoLibraryRepository;
use PDOException;
use Tests\TestCase;

final class Phase3UnitTest extends TestCase
{
    private array $saved = [];

    public function setUp(): void
    {
        foreach (['ui.demo', 'database.username', 'database.database', 'database.driver', 'app.debug', 'app.env'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        LibraryRepositoryFactory::set(null);
    }

    public function testFactoryChoosesDemoThenDatabaseThenUnavailable(): void
    {
        Config::set('ui.demo', true);
        LibraryRepositoryFactory::set(null);
        $this->assertTrue(LibraryRepositoryFactory::make() instanceof DemoLibraryRepository);

        Config::set('ui.demo', false);
        Config::set('database.driver', 'mysql');
        Config::set('database.username', 'dev');
        Config::set('database.database', 'mauro_dev');
        LibraryRepositoryFactory::set(null);
        $this->assertTrue(LibraryRepositoryFactory::make() instanceof MySqlLibraryRepository);

        Config::set('database.username', '');
        LibraryRepositoryFactory::set(null);
        $this->assertTrue(LibraryRepositoryFactory::make() instanceof UnavailableLibraryRepository);
    }

    public function testSearchTokensAndLikeEscaping(): void
    {
        $this->assertSame(['النجوم', 'للمبتديين'], MySqlLibraryRepository::tokens('  النُّجُومُ   للمبتدئين '));
        $this->assertSame(5, count(MySqlLibraryRepository::tokens('a b c d e f g')));
        $this->assertSame([], MySqlLibraryRepository::tokens('   '));
        $this->assertSame('100!% !_x!!', MySqlLibraryRepository::escapeLike('100% _x!'));
        [$in, $p] = MySqlLibraryRepository::inList([3, '7', 9], 'id');
        $this->assertSame(':id0, :id1, :id2', $in);
        $this->assertSame(['id0' => 3, 'id1' => 7, 'id2' => 9], $p);
    }

    public function testSlugTransliteration(): void
    {
        $this->assertTrue((bool) preg_match(Slug::PATTERN, Slug::from('حدائق النهر الهادئ')));
        $this->assertSame('the-cartographer-s-notebook', Slug::from("The Cartographer's Notebook"));
        $this->assertSame('item', Slug::from('!!!'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => Slug::exists('users; DROP', 'x'));
    }

    public function testDatabaseOutageIs503NotLeaky(): void
    {
        Config::set('app.debug', false);
        Config::set('app.env', 'production');
        $e = new PDOException('SQLSTATE[HY000] [2002] Connection refused (secret-host:3306)');
        $r = ErrorHandler::render($e, new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/books']));
        $this->assertSame(503, $r->status());
        $this->assertNotContains('secret-host', $r->body());
        $this->assertSame(500, ErrorHandler::render(new PDOException('SQLSTATE[42S02]: Base table not found'), new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']))->status());
    }

    public function testBrandNamesPerLocale(): void
    {
        $this->assertSame('مُعِين خيري', brand_name('ar'));
        $this->assertSame('MAURO', brand_name('en'));
        $logo = brand_logo();
        $this->assertTrue($logo !== null && str_starts_with($logo['url'], '/assets/images/brand/logo.png'), 'official logo is used');
        $this->assertSame([736, 414], [$logo['width'], $logo['height']], 'intrinsic size preserved');
        // Byte-identical to the file supplied by the owner (no re-encoding / redesign).
        $this->assertSame('09ded018110b2ed965825937ceb9298a3a6610300851c4505f203203f33c6dda', hash_file('sha256', BASE_PATH . '/public/assets/images/brand/logo.png'));
    }

    public function testSqliteHarnessIsRefusedInProduction(): void
    {
        $cfg = require BASE_PATH . '/config/database.php';
        $this->assertTrue(in_array($cfg['driver'], ['mysql', 'sqlite'], true));
        putenv('APP_ENV=production');
        putenv('DB_DRIVER=sqlite');
        try {
            $prod = require BASE_PATH . '/config/database.php';
            $this->assertSame('mysql', $prod['driver']);
        } finally {
            putenv('APP_ENV');
            putenv('DB_DRIVER');
        }
    }
}
