<?php

namespace Cloudflare\Exception;

/**
 * This class represents an error in creating the request to be sent to the
 * API. For example, if the array cannot be encoded as JSON or if there
 * is a missing or invalid field.
 */
class InvalidInputException extends InvalidRequestException
{
}
