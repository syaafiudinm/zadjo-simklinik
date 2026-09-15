<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Seluruh test Feature memakai Tests\TestCase, yang menyiapkan database pusat
| sekali per proses dan membersihkan database tenant di setiap test. Lihat
| komentar di kelas itu soal kenapa RefreshDatabase tidak dipakai.
|
*/

pest()->extend(Tests\TestCase::class)->in('Feature');

require_once __DIR__.'/Support/helpers.php';
