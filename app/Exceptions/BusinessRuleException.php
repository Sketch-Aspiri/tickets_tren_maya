<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Violacion de una regla de negocio (integridad, transicion invalida, etc.).
 * Se muestra al usuario como mensaje amable; nunca expone trazas.
 */
final class BusinessRuleException extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $translationKey, array $replace = []): self
    {
        return new self(__($translationKey, $replace));
    }

    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => ['code' => 'business_rule', 'message' => $this->getMessage()],
                'meta' => null,
            ], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
