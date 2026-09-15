<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = 'Klinik '.$this->faker->unique()->firstName();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'status' => TenantStatus::Active,
            'plan' => 'pratama',
            'activated_at' => now(),
        ];
    }

    /**
     * Membuat tenant tanpa memicu pembuatan database fisik.
     *
     * Dipakai test yang hanya menyentuh registry pusat (validasi, panel vendor,
     * pencocokan rute). Membuat lalu menghapus database MySQL untuk setiap test
     * semacam itu memperlambat suite tanpa membuktikan apa pun.
     */
    public function withoutDatabase(): static
    {
        return $this->state(['create_database' => false]);
    }

    public function suspended(): static
    {
        return $this->state([
            'status' => TenantStatus::Suspended,
        ]);
    }

    public function readOnly(): static
    {
        return $this->state([
            'status' => TenantStatus::ReadOnly,
        ]);
    }

    public function provisioning(): static
    {
        return $this->state([
            'status' => TenantStatus::Provisioning,
            'activated_at' => null,
        ]);
    }
}
