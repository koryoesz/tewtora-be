<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * A safeguarding incident is specifically about a teacher/family
 * relationship — auth.safeguarding_incidents.teacher_id is NOT NULL. A
 * support thread (parent <-> staff, no teacher) has nowhere for that
 * report to land, and semantically doesn't need one: staff is already
 * directly in the conversation.
 */
class SupportThreadCannotBeReportedException extends AppException
{
    public function __construct()
    {
        parent::__construct('A support conversation with staff can\'t be reported — staff is already in this thread.');
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'support_thread_cannot_be_reported';
    }
}
