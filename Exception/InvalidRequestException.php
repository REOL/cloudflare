<?php

namespace Cloudflare\Exception;

use yii\web\HttpException;

/**
 * Thrown when a Cloudflare API returns an error relating to the request.
 */
class InvalidRequestException extends HttpException
{
    /**
     * The code returned by the Cloudflare API
     */
    private $error;

    /**
     * @param string $message The exception message
     * @param int $error The error code returned by the Cloudflare API
     * @param int $httpStatus The HTTP status code of the response
     * @param string $uri The URI queries
     * @param \Exception $previous The previous exception, if any.
     */
    public function __construct(
        $result,
        $message
    ) {
        $this->error = $result;
        parent::__construct('200', $message);
    }

    public function getErrorCode()
    {
        return $this->error;
    }
}
