<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Jobs\PushAvatarToPortal;
use App\Models\User;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Foto de perfil. El archivo subido NUNCA se guarda tal cual: se decodifica con GD, se recorta al centro en un
 * cuadrado y se vuelve a codificar como JPEG. Asi se descartan metadatos (EXIF, ubicacion GPS), contenido
 * incrustado y cualquier archivo "poliglota"; lo que se sirve siempre es una imagen generada por el servidor.
 */
final class AvatarService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(User $user, UploadedFile $file): User
    {
        return $this->setFromBytes($user, (string) file_get_contents($file->getRealPath()));
    }

    /**
     * Guarda la foto desde bytes JPG/PNG. `$propagate` es false cuando el cambio vino del portal (evita el eco).
     * Con `$actor` null la auditoria queda sin autor (cambio recibido por el puente).
     */
    public function setFromBytes(User $user, string $bytes, bool $propagate = true): User
    {
        $jpeg = $this->normalize($bytes);
        $disk = $this->disk();
        $path = (string) config('tickets.avatars.directory').'/'.Str::random(40).'.jpg';
        $previous = $user->avatar_path;

        if (! $disk->put($path, $jpeg)) {
            throw BusinessRuleException::because('profile.avatar.errors.upload_failed');
        }

        try {
            DB::transaction(function () use ($user, $path, $propagate): void {
                $user->forceFill(['avatar_path' => $path])->save();
                $this->audit->record('users', 'avatar_updated', $user, $propagate ? $user : null);
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        if ($propagate) {
            PushAvatarToPortal::dispatch($user->id);
        }

        return $user;
    }

    /** Bytes del JPEG actual o null. */
    public function bytes(User $user): ?string
    {
        if ($user->avatar_path === null || ! $this->disk()->exists($user->avatar_path)) {
            return null;
        }

        return (string) $this->disk()->get($user->avatar_path);
    }

    public function remove(User $user, bool $propagate = true): User
    {
        $previous = $user->avatar_path;

        if ($previous === null) {
            return $user;
        }

        DB::transaction(function () use ($user, $propagate): void {
            $user->forceFill(['avatar_path' => null])->save();
            $this->audit->record('users', 'avatar_removed', $user, $propagate ? $user : null);
        });

        $this->disk()->delete($previous);

        if ($propagate) {
            PushAvatarToPortal::dispatch($user->id);
        }

        return $user;
    }

    /**
     * Devuelve los bytes del JPEG cuadrado ya reprocesado. Revisa las dimensiones ANTES de decodificar (una
     * imagen pequena en bytes puede ocupar gigas en memoria al descomprimirse).
     */
    private function normalize(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        $maxDimension = (int) config('tickets.avatars.max_source_dimension');

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw BusinessRuleException::because('profile.avatar.errors.type');
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] > $maxDimension || $info[1] > $maxDimension) {
            throw BusinessRuleException::because('profile.avatar.errors.dimensions', ['max' => $maxDimension]);
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw BusinessRuleException::because('profile.avatar.errors.type');
        }

        return $this->squareJpeg($source);
    }

    private function squareJpeg(GdImage $source): string
    {
        $size = (int) config('tickets.avatars.size');
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $target = imagecreatetruecolor($size, $size);
        // Fondo blanco: las transparencias de un PNG no se vuelven negras en el JPEG.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);

        ob_start();
        imagejpeg($target, null, 85);

        return (string) ob_get_clean();
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('tickets.attachments.disk'));
    }
}
