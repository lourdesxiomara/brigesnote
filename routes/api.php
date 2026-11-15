<?php

use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

// Endpoints del catálogo de ubicaciones y colegios (datos de MINEDU/ESCALE)
Route::get('/regiones', [LocationController::class, 'getRegions']);
Route::get('/provincias/{idRegion}', [LocationController::class, 'getProvinces']);
Route::get('/distritos/{idProvince}', [LocationController::class, 'getDistricts']);
Route::get('/colegios/{ubigeo}', [LocationController::class, 'getSchools']);
