<?php

declare(strict_types=1);

namespace App\Validators;

/** Clean data + field errors (translated messages) from a validator. */
final class ValidationResult
{
    /** @param array<string,mixed> $data @param array<string,string> $errors */
    public function __construct(public readonly array $data, public readonly array $errors)
    {
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }
}
