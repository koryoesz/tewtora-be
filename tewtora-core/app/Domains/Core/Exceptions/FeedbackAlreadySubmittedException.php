<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/** feedback.status is already 'submitted' — filing it again is a no-op, not a silent overwrite. */
class FeedbackAlreadySubmittedException extends AppException
{
    public function __construct(private readonly int $feedbackId)
    {
        parent::__construct('Feedback for this session has already been submitted.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'feedback_already_submitted';
    }

    public function context(): array
    {
        return ['feedback_id' => $this->feedbackId];
    }
}
