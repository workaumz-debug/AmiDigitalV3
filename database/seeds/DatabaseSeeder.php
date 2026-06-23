<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Master / referensi
            StandarAkreditasiSeeder::class,
            JenjangSeeder::class,
            FakultasSeeder::class,
            JurusanSeeder::class,
            ProgramStudiSeeder::class,

            // Kriteria akreditasi dari file Excel (database/data/*.xlsx)
            KriteriaBanptSeeder::class,
            // Kriteria LAMEMBA (struktur ditanam; 1 instrumen untuk semua jenjang)
            KriteriaLamembaSeeder::class,
            // Kriteria LAMDIK (dari database/data/lamdik.json hasil ekstraksi PDF)
            KriteriaLamdikSeeder::class,
            // Kriteria LAMSAMA (dari database/data/lamsama.json hasil ekstraksi PDF)
            KriteriaLamsamaSeeder::class,
            // Kriteria LAMTEKNIK (dari database/data/lamteknik.json hasil ekstraksi PDF)
            KriteriaLamteknikSeeder::class,

            // Akun login
            UserSeeder::class,
        ]);
    }
}
