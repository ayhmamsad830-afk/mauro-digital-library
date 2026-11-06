<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\HttpException;
use App\Core\Request;
use RuntimeException;
use Tests\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private mixed $debug;
    private mixed $env;

    public function setUp(): void
    {
        $this->debug = Config::get('app.debug');
        $this->env = Config::get('app.env');
    }

    public function tearDown(): void
    {
        Config::set('app.debug', $this->debug);
        Config::set('app.env', $this->env);
    }

    private function request(string $path): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path]);
    }

    public function testProductionHidesInternalErrorDetails(): void
    {
        Config::set('app.debug', false);
        Config::set('app.env', 'production');
        $res = ErrorHandler::render(new RuntimeException('DB password is hunter2'), $this->request('/'));
        $this->assertSame(500, $res->status());
        $this->assertNotContains('hunter2', $res->body());
        $this->assertContains('500', $res->body());
    }

    public function testApiErrorsAreJson(): void
    {
        Config::set('app.debug', false);
        $res = ErrorHandler::render(HttpException::notFound(), $this->request('/api/books/1'));
        $this->assertSame(404, $res->status());
        $this->assertContains('application/json', (string) $res->getHeader('Content-Type'));
        $data = json_decode($res->body(), true);
        $this->assertSame(404, $data['error']['status']);
    }

    public function testDebugFlagIgnoredInProduction(): void
    {
        Config::set('app.debug', true);
        Config::set('app.env', 'production');
        $this->assertFalse(ErrorHandler::debug());
    }
}
