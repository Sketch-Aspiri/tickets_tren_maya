<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\AttachmentInspector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Valida el archivo por extensión permitida + MIME real (ver AttachmentInspector).
 */
final class AllowedAttachment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || app(AttachmentInspector::class)->acceptedExtension($value) === null) {
            $fail('tickets.validation.attachment_type')->translate();
        }
    }
}
