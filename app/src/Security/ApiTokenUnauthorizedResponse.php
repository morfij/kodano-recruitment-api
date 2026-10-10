<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Builds the 401 for a missing token (entry point) and for a wrong one (failure
 * handler). The firewall answers before API Platform, so the problem JSON used by
 * all other errors is written here.
 */
final class ApiTokenUnauthorizedResponse implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized('Missing API token.', 'Bearer');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized('Invalid API token.', 'Bearer error="invalid_token"');
    }

    private function unauthorized(string $detail, string $challenge): JsonResponse
    {
        return new JsonResponse(
            ['type' => '/errors/401', 'title' => 'An error occurred', 'detail' => $detail, 'status' => Response::HTTP_UNAUTHORIZED],
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/problem+json', 'WWW-Authenticate' => $challenge],
        );
    }
}
