<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Accepts the single token from API_TOKEN. An empty API_TOKEN rejects every request.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        #[Autowire(env: 'API_TOKEN')]
        #[\SensitiveParameter]
        private readonly string $apiToken,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if ('' === $this->apiToken || !hash_equals($this->apiToken, $accessToken)) {
            throw new BadCredentialsException('Invalid API token.');
        }

        return new UserBadge('api', static fn (string $identifier): InMemoryUser => new InMemoryUser($identifier, null, ['ROLE_API']));
    }
}
