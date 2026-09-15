<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memberi setiap request satu id korelasi.
 *
 * Disimpan di Context Laravel, yang ikut terbawa ke job antrian yang
 * di-dispatch selama request ini. Hasilnya, baris audit dari worker bisa
 * ditelusuri balik ke request yang memicunya.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid7();
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
