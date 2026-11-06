<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BookAccessPolicy;
use Tests\TestCase;

final class BookAccessPolicyTest extends TestCase
{
    public function testPolicyMatrix(): void
    {
        $this->assertTrue(BookAccessPolicy::canView(['visibility' => 'public'], false));
        $this->assertTrue(BookAccessPolicy::canView(['visibility' => 'public'], true));
        $this->assertFalse(BookAccessPolicy::canView(['visibility' => 'members'], false));
        $this->assertTrue(BookAccessPolicy::canView(['visibility' => 'members'], true));
        $this->assertFalse(BookAccessPolicy::canView(['visibility' => 'secret'], true), 'unknown visibility is denied');
        $this->assertTrue(BookAccessPolicy::canView([], false), 'missing → public (demo data shape)');
    }

    public function testGuestWithoutSessionIsNotMemberAndTriggersNoQuery(): void
    {
        $_SESSION = [];
        \App\Services\Auth::reset();
        // No database is configured in this unit context: a query would throw.
        $this->assertFalse(BookAccessPolicy::viewerIsMember());
        $this->assertFalse(BookAccessPolicy::canView(['visibility' => 'members']));
    }
}
