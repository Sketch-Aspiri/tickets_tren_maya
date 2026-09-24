<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->empleado(),
            'original_name' => 'documento.pdf',
            'path' => 'tickets/1/'.Str::random(40).'.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
        ];
    }
}
