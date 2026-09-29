<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAvatarRequest;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotos de perfil: el archivo vive en el disco privado y solo se sirve por aqui (con Policy). Siempre es un JPEG
 * generado por el servidor (AvatarService), asi que el tipo se fija sin depender del contenido subido.
 */
class AvatarController extends Controller
{
    public function __construct(private readonly AvatarService $avatars) {}

    public function show(User $user): StreamedResponse
    {
        $this->authorize('viewAvatar', $user);

        $disk = Storage::disk((string) config('tickets.attachments.disk'));

        abort_unless($disk->exists((string) $user->avatar_path), 404);

        return $disk->response((string) $user->avatar_path, 'avatar.jpg', [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // La URL lleva `v` (cambia con cada foto): se puede guardar en cache del navegador sin riesgo de verse vieja.
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    public function update(UpdateAvatarRequest $request): RedirectResponse
    {
        $this->avatars->update($request->user(), $request->file('avatar'));

        return redirect()->route('profile.edit')->with('status', 'avatar-updated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->authorize('updateProfile', $request->user());

        $this->avatars->remove($request->user());

        return redirect()->route('profile.edit')->with('status', 'avatar-removed');
    }
}
