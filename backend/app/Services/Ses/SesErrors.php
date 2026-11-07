<?php

namespace App\Services\Ses;

use App\Exceptions\ApiException;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;

/** Turns AWS errors into plain-English API errors. */
class SesErrors
{
    public const BAD_KEYS = ['InvalidClientTokenId', 'UnrecognizedClientException', 'SignatureDoesNotMatch', 'AuthFailure', 'ExpiredToken', 'InvalidAccessKeyId', 'IncompleteSignature'];

    public const DENIED = ['AccessDenied', 'AccessDeniedException', 'AuthorizationError', 'UnauthorizedOperation'];

    public static function from(AwsException $e, string $action): ApiException
    {
        $code = (string) $e->getAwsErrorCode();
        if (in_array($code, self::BAD_KEYS, true)) {
            return new ApiException('aws_keys_invalid', 'AWS didn’t accept these keys. Check the access key ID and secret, and that the key is active in IAM.', 422);
        }
        if (in_array($code, self::DENIED, true)) {
            return new ApiException('aws_permission_missing', "These keys aren’t allowed to use $action. Attach the MailVesper policy to the IAM user, then try again.", 422, ['action' => $action]);
        }
        if ($code === 'Throttling' || $code === 'TooManyRequestsException' || $code === 'ThrottlingException') {
            return new ApiException('aws_busy', 'AWS is rate-limiting requests right now. Try again in a minute.', 503, [], 60);
        }
        if ($code === '') {
            Log::warning('AWS unreachable', ['action' => $action, 'error' => $e->getMessage()]);

            return new ApiException('aws_unreachable', 'We couldn’t reach AWS in that region. Check the region and try again.', 503);
        }
        Log::warning('AWS error', ['action' => $action, 'code' => $code, 'message' => $e->getAwsErrorMessage()]);

        return new ApiException('aws_error', 'AWS returned an error: '.($e->getAwsErrorMessage() ?: $code), 422, ['aws_code' => $code]);
    }

    public static function isDenied(AwsException $e): bool
    {
        return in_array((string) $e->getAwsErrorCode(), self::DENIED, true);
    }
}
