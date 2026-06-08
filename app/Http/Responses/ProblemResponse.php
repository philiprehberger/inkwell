<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 7807 problem+json response. The schema matches the Problem component
 * in openapi/spec.yaml.
 */
class ProblemResponse extends JsonResponse
{
    public function __construct(
        int $status,
        string $title,
        string $detail = '',
        string $type = 'about:blank',
        ?array $errors = null,
        ?string $instance = null,
    ) {
        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== '') {
            $body['detail'] = $detail;
        }
        if ($instance !== null) {
            $body['instance'] = $instance;
        }
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        parent::__construct(
            data: $body,
            status: $status,
            headers: ['Content-Type' => 'application/problem+json'],
        );
    }

    public static function for(\Throwable $e, ?int $statusOverride = null): self
    {
        $status = $statusOverride ?? self::statusFor($e);
        $title = self::titleFor($status);

        return new self(
            status: $status,
            title: $title,
            detail: $e->getMessage(),
        );
    }

    public static function statusFor(\Throwable $e): int
    {
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return Response::HTTP_UNAUTHORIZED;
        }
        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return Response::HTTP_FORBIDDEN;
        }
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return Response::HTTP_NOT_FOUND;
        }
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return Response::HTTP_NOT_FOUND;
        }
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
            return Response::HTTP_METHOD_NOT_ALLOWED;
        }
        if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
            return Response::HTTP_TOO_MANY_REQUESTS;
        }
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return Response::HTTP_BAD_REQUEST;
        }

        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Invalid request',
            401 => 'Authentication required',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            409 => 'Conflict',
            422 => 'Unprocessable entity',
            429 => 'Too many requests',
            500 => 'Internal server error',
            default => 'Error',
        };
    }
}
