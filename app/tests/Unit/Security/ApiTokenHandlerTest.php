<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ApiTokenHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class ApiTokenHandlerTest extends TestCase
{
    public function testAcceptsConfiguredToken(): void
    {
        $badge = (new ApiTokenHandler('secret'))->getUserBadgeFrom('secret');

        self::assertSame('api', $badge->getUserIdentifier());
        self::assertSame(['ROLE_API'], $badge->getUser()->getRoles());
    }

    public function testRejectsOtherToken(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler('secret'))->getUserBadgeFrom('secret2');
    }

    public function testRejectsEverythingWhenNoTokenIsConfigured(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(''))->getUserBadgeFrom('');
    }
}
