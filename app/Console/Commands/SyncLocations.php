<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Models\Region;
use App\Models\Province;
use App\Models\District;
use App\Models\School;

class SyncLocations extends Command
{
    protected $signature = 'sync:locations
        {--desde= : Código de región (ej. 08) desde la cual retomar la sincronización}';
    protected $description = 'Sincroniza regiones, provincias, distritos y escuelas desde la API y las registra en la base de datos';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Iniciando sincronización de ubicaciones...');

        // Sincronizar regiones
        $this->syncRegions();

        $this->info('Sincronización completa.');
        return 0;
    }

    private function syncRegions()
    {
        $this->info('Sincronizando regiones...');
        $response = $this->fetch('https://escale.minedu.gob.pe/padron/rest/regiones');

        if (!$response || $response->failed()) {
            $this->error('Error al obtener regiones');
            return;
        }
        $regions = $response->json()['region'];

        for ($i=0;$i<count($regions);$i++) {
            $region=$regions[$i];

            // Permite retomar una sincronización interrumpida: php artisan sync:locations --desde=08
            $desde = $this->option('desde');
            if ($desde && strcmp($region['idRegion'], str_pad($desde, 2, '0', STR_PAD_LEFT)) < 0) {
                continue;
            }
            Region::updateOrCreate(
                ['idRegion' => $region['idRegion']],
                [
                    'nombreRegion' => $region['nombreRegion'],
                    'pointX' => $region['pointX'],
                    'pointY' => $region['pointY'],
                    'zoom' => $region['zoom'],
                ]
            );

            $this->syncProvinces($region['idRegion']);
        }

        $this->info('Regiones sincronizadas correctamente.');
    }

    private function syncProvinces($regionId)
    {
        $this->info("Sincronizando provincias para la región $regionId...");
        $response = $this->fetch('https://escale.minedu.gob.pe/padron/rest/provincias', [
            'idRegion' => $regionId,
        ]);

        if (!$response || $response->failed()) {
            $this->error("Error al obtener provincias para la región $regionId");
            return;
        }
        $provinces = $response->json()['provincia'];
        if(isset($provinces['idProvincia'])){
            Province::updateOrCreate(
                ['idProvincia' => $provinces['idProvincia']],
                [
                    'nombreProvincia' => $provinces['nombreProvincia'],
                    'pointX' => $provinces['pointX'],
                    'pointY' => $provinces['pointY'],
                ]
            );
            $this->syncDistricts($provinces['idProvincia']);
        }else{
            foreach ($provinces as $province) {
                Province::updateOrCreate(
                    ['idProvincia' => $province['idProvincia']],
                    [
                        'nombreProvincia' => $province['nombreProvincia'],
                        'pointX' => $province['pointX'],
                        'pointY' => $province['pointY'],
                    ]
                );

                $this->syncDistricts($province['idProvincia']);
            }
        }


        $this->info("Provincias para la región $regionId sincronizadas correctamente.");
    }

    private function syncDistricts($provinceId)
    {
        $this->info("Sincronizando distritos para la provincia $provinceId...");
        $response = $this->fetch('https://escale.minedu.gob.pe/padron/rest/distritos', [
            'idProvincia' => $provinceId,
        ]);

        if (!$response || $response->failed()) {
            $this->error("Error al obtener distritos para la provincia $provinceId");
            return;
        }

        $districts = $response->json()['distrito'];
        if(isset($districts['idDistrito'])){
                District::updateOrCreate(
                    ['idDistrito' => $districts['idDistrito']],
                    [
                        'nombreDistrito' => $districts['nombreDistrito'],
                    ]
                );

                $this->syncSchools($districts['idDistrito']);
        }else{
            for ($j=0;$j<count($districts);$j++) {
                $district=$districts[$j];
                District::updateOrCreate(
                    ['idDistrito' => $district['idDistrito']],
                    [
                        'nombreDistrito' => $district['nombreDistrito'],
                    ]
                );

                $this->syncSchools($district['idDistrito']);
            }
        }

        $this->info("Distritos para la provincia $provinceId sincronizados correctamente.");
    }

    /**
     * ESCALE devuelve como máximo 50 colegios por consulta si no se indica otra cosa.
     * Se recorren todas las páginas con los parámetros `start` (desde qué registro)
     * y `max` (cuántos registros), hasta que una página venga incompleta.
     */
    private const SCHOOLS_PAGE_SIZE = 200;

    private function syncSchools($districtId)
    {
        $this->info("Sincronizando escuelas para el distrito $districtId...");

        $start = 0;
        $total = 0;

        do {
            $response = $this->fetch('https://escale.minedu.gob.pe/padron/rest/instituciones', [
                'estados' => 1,
                'ubigeo' => $districtId,
                'start' => $start,
                'max' => self::SCHOOLS_PAGE_SIZE,
            ]);

            if (!$response || $response->failed()) {
                $this->error("Error al obtener escuelas para el distrito $districtId (desde el registro $start)");
                return;
            }

            $schools = $response->json()['items'] ?? [];

            // Si la página trae un solo colegio, ESCALE lo envía como objeto y no como lista.
            if (isset($schools['codMod'])) {
                $schools = [$schools];
            }

            foreach ($schools as $school) {
                School::updateOrCreate(
                    ['codMod' => $school['codMod']],
                    [
                        'idDistrito' => $districtId,
                        'nombreCenEdu' => $school['cenEdu'] ?? null,
                        'cenPob' => $school['cenPob'] ?? null,
                        'dirCen' => $school['dirCen'] ?? null,
                        'director' => $school['director'] ?? null,
                        'dis_crecer' => $school['dis_crecer'] ?? null,
                        'dis_juntos' => $school['dis_juntos'] ?? null,
                    ]
                );
            }

            $count = count($schools);
            $total += $count;
            $start += self::SCHOOLS_PAGE_SIZE;
        } while ($count === self::SCHOOLS_PAGE_SIZE);

        if ($total === 0) {
            $this->error("No se encontraron registros de escuelas para el distrito $districtId");
            return;
        }

        $this->info("Escuelas para el distrito $districtId sincronizadas correctamente ($total).");
    }

    /**
     * Petición GET a la API de ESCALE.
     * Laravel 13 corta las peticiones a los 30 s por defecto (Laravel 7 no tenía límite),
     * y ESCALE puede tardar más en distritos con muchos colegios. Por eso se amplía el
     * tiempo de espera, se reintenta y, si aun así falla, se registra el error y se continúa.
     */
    private function fetch(string $url, array $query = []): ?Response
    {
        try {
            return Http::withHeaders(['Accept' => 'application/json'])
                ->timeout(120)
                ->retry(3, 2000, throw: false)
                ->get($url, $query);
        } catch (ConnectionException $e) {
            $this->error("Sin respuesta de ESCALE ({$url} ".json_encode($query).'): '.$e->getMessage());

            return null;
        }
    }
}
