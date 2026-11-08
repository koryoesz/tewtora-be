<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Editing, publishing or cancelling a class that has already been cancelled or completed.
 */
class ClassNotEditableException extends AppException
{
    public function __construct()
    {
        parent::__construct('A cancelled or completed class can\'t be changed.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'class_not_editable';
    }
}
