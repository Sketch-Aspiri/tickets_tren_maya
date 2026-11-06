<?php

declare(strict_types=1);

namespace App\Http\Requests\Push;

use Illuminate\Foundation\Http\FormRequest;
use NotificationChannels\WebPush\PushSubscription;

class DestroyPushSubscriptionRequest extends FormRequest
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
            'endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
        ];
    }
}
