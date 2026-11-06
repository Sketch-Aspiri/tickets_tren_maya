<?php

declare(strict_types=1);

namespace App\Http\Requests\Push;

use App\Rules\PushServiceEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Suscripción Web Push del propio usuario. Solo actúa sobre las suscripciones del usuario autenticado,
 * por lo que no hay un modelo ajeno que autorizar con Policy.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH, new PushServiceEndpoint],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-+\/=]+$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-+\/=]+$/'],
            'contentEncoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ];
    }
}
