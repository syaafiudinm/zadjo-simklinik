<?php

declare(strict_types=1);

namespace App\Support;

use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Pesan untuk halaman 403.
 *
 * Pesan bawaan Laravel (`This action is unauthorized.`) dan spatie berbahasa
 * Inggris dan bisa menyebut nama permission yang kurang. Hanya pesan yang
 * sengaja ditulis lewat `abort(403, '...')` — mis. mode hanya-baca — yang
 * diteruskan ke pengguna.
 */
final class ForbiddenMessage
{
    public const DEFAULT = 'Anda tidak memiliki hak akses untuk membuka halaman ini. Hubungi admin klinik jika Anda memerlukannya.';

    public static function for(Throwable $e): string
    {
        // Gate/policy ditolak → AccessDeniedHttpException; permission spatie
        // ditolak → UnauthorizedException. Keduanya pesan generik.
        if ($e instanceof UnauthorizedException || $e instanceof AccessDeniedHttpException) {
            return self::DEFAULT;
        }

        if ($e instanceof HttpExceptionInterface && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        return self::DEFAULT;
    }
}
