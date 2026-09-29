<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
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
        $jpeg = $this->normalize($file);
        $disk = $this->disk();
        $path = (string) config('tickets.avatars.directory').'/'.Str::random(40).'.jpg';
        $previous = $user->avatar_path;

        if (! $disk->put($path, $jpeg)) {
            throw BusinessRuleException::because('profile.avatar.errors.upload_failed');
        }

        try {
            DB::transaction(function () use ($user, $path): void {
                $user->forceFill(['avatar_path' => $path])->save();
                $this->audit->record('users', 'avatar_updated', $user, $user);
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $user;
    }

    public function remove(User $user): User
    {
        $previous = $user->avatar_path;

        if ($previous === null) {
            return $user;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['avatar_path' => null])->save();
            $this->audit->record('users', 'avatar_removed', $user, $user);
        });

        $this->disk()->delete($previous);

        return $user;
    }

    /**
     * Devuelve los bytes del JPEG cuadrado ya reprocesado. Revisa las dimensiones ANTES de decodificar (una
     * imagen pequena en bytes puede ocupar gigas en memoria al descomprimirse).
     */
    private function normalize(UploadedFile $file): string
    {
        $info = @getimagesize($file->getRealPath());
        $maxDimension = (int) config('tickets.avatars.max_source_dimension');

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw BusinessRuleException::because('profile.avatar.errors.type');
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] > $maxDimension || $info[1] > $maxDimension) {
            throw BusinessRuleException::because('profile.avatar.errors.dimensions', ['max' => $maxDimension]);
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

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
