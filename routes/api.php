<?php

use App\Http\Controllers\AddressAutocompleteController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExternalFetchJobController;
use App\Http\Controllers\ExternalFetchJobResultController;
use App\Http\Controllers\FauneFranceOccurrenceController;
use App\Http\Controllers\GeographicAreaController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MonitoringRuleController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\PanelFeedController;
use App\Http\Controllers\ScopedObservationController;
use App\Http\Controllers\SearchEstimateController;
use App\Http\Controllers\TaxonController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', DashboardController::class);
Route::get('/geographic-areas', GeographicAreaController::class);
Route::get('/geocoding/addresses', AddressAutocompleteController::class);
Route::get('/taxa/search', [TaxonController::class, 'search']);
Route::get('/taxa/{taxon}', [TaxonController::class, 'show']);
Route::post('/searches/estimate', SearchEstimateController::class);
Route::get('/imports', [ImportController::class, 'index']);
Route::post('/imports', [ImportController::class, 'store']);
Route::get('/imports/{import}', [ImportController::class, 'show']);
Route::patch('/imports/{import}/cancel', [ImportController::class, 'cancel']);
Route::apiResource('/observations', ObservationController::class)->only(['index', 'show']);
Route::get('/collections/{collection}/observations', [ScopedObservationController::class, 'collection']);
Route::get('/collections/{collection}/observations/map', [ScopedObservationController::class, 'collectionMap']);
Route::apiResource('/collections', CollectionController::class)->only(['index', 'store', 'show', 'destroy']);
Route::get('/monitoring/{monitoring}/observations', [ScopedObservationController::class, 'monitoring']);
Route::get('/monitoring/{monitoring}/observations/map', [ScopedObservationController::class, 'monitoringMap']);
Route::post('/monitoring/{monitoring}/sync', [MonitoringRuleController::class, 'sync']);
Route::apiResource('/monitoring', MonitoringRuleController::class)->parameters(['monitoring' => 'monitoring']);
Route::get('/panels/feed', PanelFeedController::class);
Route::post('/biodiversity/faune-france/occurrences', FauneFranceOccurrenceController::class);

Route::prefix('/bot')->middleware('faune-france.bot')->group(function (): void {
    Route::get('/jobs/next', [ExternalFetchJobController::class, 'next']);
    Route::post('/jobs/{job}/claim', [ExternalFetchJobController::class, 'claim']);
    Route::post('/jobs/{job}/results', ExternalFetchJobResultController::class);
    Route::post('/jobs/{job}/complete', [ExternalFetchJobController::class, 'complete']);
    Route::post('/jobs/{job}/fail', [ExternalFetchJobController::class, 'fail']);
    Route::post('/heartbeat', [ExternalFetchJobController::class, 'heartbeat']);
});
