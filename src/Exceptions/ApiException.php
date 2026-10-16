<?php

namespace AiEditor\Exceptions;

use RuntimeException;

/**
 * Ошибка API с HTTP-статусом и кодом для ответа.
 */
class ApiException extends RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $status;

    /** @var array */
    private $details;

    public function __construct(string $errorCode, string $message, int $status = 400, array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->details = $details;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }

    public static function validation(string $message, array $details = []): self
    {
        return new self('VALIDATION_ERROR', $message, 400, $details);
    }

    public static function notFound(string $message): self
    {
        return new self('NOT_FOUND', $message, 404);
    }
}
