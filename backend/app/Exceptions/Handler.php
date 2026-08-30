<?php

namespace App\Exceptions;

use App\Services\ApiResponseService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Exception
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        if ($request->is('api/*')) {
            $responses = app(ApiResponseService::class);

            if ($exception instanceof AuthenticationException) {
                return $responses->error(
                    'Unauthenticated',
                    401,
                    null,
                    ['code' => 'unauthenticated']
                );
            }

            if ($exception instanceof ValidationException) {
                return $responses->error(
                    'The given data was invalid.',
                    422,
                    null,
                    [
                        'code' => 'validation_failed',
                        'errors' => $exception->errors(),
                    ]
                );
            }

            if ($exception instanceof AuthorizationException) {
                return $responses->error(
                    'This action is not allowed.',
                    403,
                    null,
                    ['code' => 'forbidden']
                );
            }

            if ($exception instanceof ModelNotFoundException) {
                return $responses->error(
                    'The requested resource was not found.',
                    404,
                    null,
                    ['code' => 'not_found']
                );
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                [$message, $code] = match ($status) {
                    403 => ['This action is not allowed.', 'forbidden'],
                    404 => ['The requested resource was not found.', 'not_found'],
                    405 => ['This request method is not allowed.', 'method_not_allowed'],
                    429 => ['Too many requests. Please try again shortly.', 'rate_limited'],
                    default => $status >= 500
                        ? ['The service could not complete the request.', 'server_error']
                        : ['The request could not be completed.', 'request_failed'],
                };

                return $responses->error(
                    $message,
                    $status,
                    null,
                    ['code' => $code],
                    $exception->getHeaders()
                );
            }

            return $responses->error(
                'The service could not complete the request.',
                500,
                null,
                ['code' => 'server_error']
            );
        }

        return parent::render($request, $exception);
    }
}
