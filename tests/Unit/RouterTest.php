<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Router;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/', fn () => 'home')->name('home');
        $r->get('/books/{slug}', fn () => 'book')->name('books.show');
        $r->get('/api/books/{id:\d+}/pages/{page:\d+}', fn () => 'page')->name('api.page');
        $r->post('/books/{slug}', fn () => 'update');
        $r->group(['prefix' => '/admin', 'middleware' => ['AdminMw']], function (Router $r): void {
            $r->get('/books', fn () => 'admin')->name('admin.books')->middleware('Extra');
        });
        return $r;
    }

    public function testMatchesStaticAndParamRoutes(): void
    {
        $r = $this->router();
        [$route, $params] = $r->match('GET', '/');
        $this->assertSame('/', $route->path());
        [, $params] = $r->match('GET', '/books/the-prophet');
        $this->assertSame(['slug' => 'the-prophet'], $params);
    }

    public function testConstrainedParams(): void
    {
        $r = $this->router();
        [, $p] = $r->match('GET', '/api/books/12/pages/3');
        $this->assertSame(['id' => '12', 'page' => '3'], $p);
        $this->assertThrows(HttpException::class, fn () => $r->match('GET', '/api/books/x/pages/3'),
            fn (HttpException $e) => $this->assertSame(404, $e->status()));
    }

    public function testQuantifierBracesInConstraint(): void
    {
        $r = new Router();
        $r->get('/p/{n:\d{1,4}}', fn () => 'p')->name('p');
        [, $p] = $r->match('GET', '/p/1234');
        $this->assertSame(['n' => '1234'], $p);
        $this->assertThrows(HttpException::class, fn () => $r->match('GET', '/p/12345'));
        $this->assertSame('/p/7', $r->url('p', ['n' => 7]));
    }

    public function testUnknownPathIs404(): void
    {
        $this->assertThrows(HttpException::class, fn () => $this->router()->match('GET', '/nope'),
            fn (HttpException $e) => $this->assertSame(404, $e->status()));
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $this->assertThrows(HttpException::class, fn () => $this->router()->match('DELETE', '/books/x'),
            function (HttpException $e): void {
                $this->assertSame(405, $e->status());
                $this->assertSame('GET, HEAD, POST', $e->headers()['Allow']);
            });
    }

    public function testGroupPrefixAndMiddleware(): void
    {
        [$route] = $this->router()->match('GET', '/admin/books');
        $this->assertSame(['AdminMw', 'Extra'], $route->middlewareList());
    }

    public function testUrlGenerationEncodesParams(): void
    {
        $r = $this->router();
        $this->assertSame('/books/a%20b', $r->url('books.show', ['slug' => 'a b']));
        $this->assertSame('/api/books/5/pages/9', $r->url('api.page', ['id' => 5, 'page' => 9]));
        $this->assertSame('/?q=x', $r->url('home', [], ['q' => 'x']));
    }

    public function testRegexMetacharactersInPathAreLiteral(): void
    {
        $r = new Router();
        $r->get('/robots.txt', fn () => 'r');
        $this->assertThrows(HttpException::class, fn () => $r->match('GET', '/robotsXtxt'));
    }
}
