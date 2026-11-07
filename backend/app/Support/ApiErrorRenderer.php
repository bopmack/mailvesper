<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Services\Platform\Brand;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Every error leaves the API in one shape: { error: { code, message, fields? } }. */
class ApiErrorRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::json($e->errorCode, $e->getMessage(), $e->status, $e->fields, $e->retryAfter, $e->details),
            $e instanceof ValidationException => self::validation($e),
            $e instanceof AuthenticationException => self::json('unauthenticated', 'Please log in to continue.', 401),
            $e instanceof AuthorizationException => self::json('forbidden', 'You don’t have permission to do that.', 403),
            $e instanceof ThrottleRequestsException => self::throttled($e),
            $e instanceof TokenMismatchException => self::json('session_expired', 'Your session expired. Refresh the page and try again.', 419),
            $e instanceof ModelNotFoundException => self::json('not_found', 'Not found.', 404),
            $e instanceof HttpExceptionInterface => self::http($e),
            default => self::json('server_error', config('app.debug') ? $e->getMessage() : 'Something went wrong on our side. Please try again.', 500),
        };
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, mixed>  $details
     */
    public static function json(string $code, string $message, int $status, array $fields = [], ?int $retryAfter = null, array $details = []): JsonResponse
    {
        $body = ['error' => array_filter(['code' => $code, 'message' => $message, 'fields' => $fields ?: null, 'retry_after' => $retryAfter, 'details' => $details ?: null], fn ($v) => $v !== null)];
        $response = new JsonResponse($body, $status);
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    private static function validation(ValidationException $e): JsonResponse
    {
        $fields = array_map(fn (array $messages) => (string) $messages[0], $e->errors());

        return self::json('validation_failed', (string) (array_values($fields)[0] ?? 'Check the highlighted fields.'), 422, $fields);
    }

    private static function throttled(ThrottleRequestsException $e): JsonResponse
    {
        $retry = (int) ($e->getHeaders()['Retry-After'] ?? 60);

        return self::json('rate_limited', "Too many attempts. Try again in {$retry} seconds.", 429, retryAfter: $retry);
    }

    private static function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        [$code, $message] = match ($status) {
            404 => ['not_found', 'Not found.'],
            405 => ['method_not_allowed', 'Method not allowed.'],
            419 => ['session_expired', 'Your session expired. Refresh the page and try again.'],
            403 => ['forbidden', 'You don’t have permission to do that.'],
            503 => ['unavailable', Brand::name().' is briefly unavailable. Try again in a minute.'],
            default => ['http_error', $e->getMessage() ?: 'Request failed.'],
        };

        return self::json($code, $message, $status);
    }
}
