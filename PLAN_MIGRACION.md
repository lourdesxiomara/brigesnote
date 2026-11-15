# Plan de migración: brigesnote → PHP 8.3 / Laravel 13

**Rama:** `migracion-php83`
**Autora:** Lourdes Xiomara
**Fecha:** 15/11/2026

---

## 1. Contexto

`brigesnote` es una API REST que sincroniza desde la API pública de MINEDU/ESCALE
las regiones, provincias, distritos y colegios del Perú, los guarda en MySQL y los
sirve en 4 endpoints.

Está construida con **Laravel 7 + PHP 7.4**, versiones sin soporte. Laravel 7 no
funciona con PHP 8.1 o superior, así que no se puede ejecutar en PHP 8.3 sin migrar.

## 2. Objetivo

Migrar el proyecto a **PHP 8.3** usando **Laravel 13** (versión actual; requiere PHP 8.3
como mínimo y tiene soporte de seguridad hasta el primer trimestre de 2028),
**manteniendo exactamente el mismo comportamiento**:

- El comando `php artisan sync:locations` sigue consumiendo la API de ESCALE.
- Los 4 endpoints responden con la misma estructura JSON.

## 3. Entorno de trabajo

| Herramienta | Versión |
|---|---|
| Laragon | 6.0 |
| PHP | 8.3.36 (Thread Safe, x64) |
| Composer | 2.11.2 |
| MySQL | 8.0.30 |
| Git | 2.37.3 |

## 4. Inventario del código actual (lo que hay que conservar)

| Archivo | Qué hace |
|---|---|
| `app/Console/Commands/SyncLocations.php` | Comando `sync:locations`: llama a ESCALE y guarda los datos |
| `app/Http/Controllers/LocationController.php` | Lógica de los 4 endpoints |
| `app/Models/Region.php`, `Province.php`, `District.php`, `School.php` | Modelos Eloquent |
| `database/migrations/2024_12_22_*` | Tablas `regions`, `provinces`, `districts`, `schools` |
| `routes/api.php` | Definición de los endpoints |
| `README.md` | Documentación (actualizar requisitos) |

Endpoints:

- `GET /api/regiones`
- `GET /api/provincias/{idRegion}`
- `GET /api/distritos/{idProvince}`
- `GET /api/colegios/{ubigeo}`

## 5. Estrategia

Como el proyecto es pequeño, **no** se actualizará versión por versión (7→8→…→13).
Se creará un esqueleto limpio de Laravel 13 y se trasladará el código propio:

1. Crear un proyecto Laravel 13 nuevo en una carpeta temporal.
2. Reemplazar en este repositorio los archivos del framework por los del esqueleto nuevo
   (conservando el historial de Git).
3. Copiar el código propio del inventario (sección 4) y adaptarlo.

## 6. Cambios conocidos de Laravel 7 a Laravel 13

| Tema | Laravel 7 | Laravel 13 |
|---|---|---|
| Configuración de la app | `app/Http/Kernel.php`, `app/Console/Kernel.php`, `RouteServiceProvider` | Todo en `bootstrap/app.php` |
| Rutas API | Cargadas por `RouteServiceProvider` | Se activan con `php artisan install:api` |
| Proxies / CORS | Paquetes `fideloper/proxy` y `fruitcake/laravel-cors` | Incluidos en el framework (se eliminan los paquetes) |
| Seeders | `database/seeds/` | `database/seeders/` con namespace |
| Factories | Funciones `$factory->define` | Clases con `HasFactory` |
| Modelo User | `app/User.php` | `app/Models/User.php` |
| Base de datos por defecto | MySQL | SQLite → cambiar a MySQL en `.env` |
| Comandos Artisan | Registro en `Console/Kernel.php` | Se descubren solos en `app/Console/Commands` |

Limpieza adicional:

- Eliminar el modelo duplicado `app/Region.php` (vacío; el bueno es `app/Models/Region.php`).
- Eliminar la ruta `/api/user` con `auth:api` (no se usa y ese guard ya no existe).

## 7. Pasos (un commit por paso)

- [x] **Paso 0:** Crear este plan y hacer commit. *(antes de ejecutar el agente)*
- [x] **Paso 1:** Crear el esqueleto Laravel 13 y reemplazar los archivos del framework.
- [x] **Paso 2:** Trasladar modelos y migraciones; eliminar `app/Region.php`.
- [x] **Paso 3:** Trasladar `LocationController` y las rutas (registradas a mano en `bootstrap/app.php`; no se usó `install:api` porque instala Sanctum, que el proyecto no necesita).
- [x] **Paso 4:** Trasladar el comando `SyncLocations`.
- [x] **Paso 5:** Configurar `.env.example` para MySQL (base `escalebridge`).
- [x] **Paso 6:** Actualizar el `README.md` (requisitos: PHP 8.3, Laravel 13).
- [x] **Paso 7:** Verificación completa (sección 8).

## 8. Verificación

1. `composer install` termina sin errores.
2. `php artisan migrate` crea las 4 tablas en MySQL.
3. `php artisan sync:locations` descarga datos de ESCALE sin errores
   (para probar rápido, se puede limitar a una región).
4. Los 4 endpoints responden con `status: success` y datos:
   - `http://brigesnote.test/api/regiones`
   - `http://brigesnote.test/api/provincias/15`
   - `http://brigesnote.test/api/distritos/1501`
   - `http://brigesnote.test/api/colegios/150101`
5. Comparar la estructura del JSON con la versión original.

## 9. Resultados de la verificación

- `php artisan migrate`: las 4 tablas propias y las de Laravel se crean en MySQL (`escalebridge`).
- `php artisan sync:locations`: descarga datos reales de ESCALE (probado con las regiones 01 a 08).
- Los 4 endpoints responden con `status: success` y la misma estructura JSON que antes.

**Diferencia de comportamiento encontrada y corregida:** el cliente HTTP de Laravel 13
corta las peticiones a los 30 segundos (Laravel 7 no tenía límite). ESCALE tardó más
en el distrito 080306 y la sincronización se detuvo. Se añadió en `SyncLocations` un
método `fetch()` con tiempo de espera de 120 s, 3 reintentos y manejo del error para
continuar con el siguiente distrito.

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| El agente rompe algo | Commit antes de cada paso; volver con `git reset --hard` |
| La API de ESCALE cambió o no responde | Probar con una sola región antes de la sincronización completa |
| La sincronización completa tarda mucho | Ejecutarla al final, una sola vez |

## 11. Fuera de alcance (para después)

- Migración a OpenAPI / arquitectura de microservicios (clase del jueves).
- Mejoras detectadas en la sincronización, a tratar en commits aparte:
  - Paginación en la consulta de colegios (posibles colegios faltantes).
  - Campos sin `?? null` que pueden detener la sincronización.
  - Pausas entre peticiones a ESCALE (los reintentos ya se añadieron).
