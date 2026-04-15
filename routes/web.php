<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InstitutionController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/institutions', [InstitutionController::class, 'index'])->name('institutions.index');
Route::get('/institutions/create', [InstitutionController::class, 'create'])->name('institutions.create');
Route::post('/institutions', [InstitutionController::class, 'store'])->name('institutions.store');

Route::get('/institutions/{institution}/edit', [InstitutionController::class, 'edit'])->name('institutions.edit');
Route::put('/institutions/{institution}', [InstitutionController::class, 'update'])->name('institutions.update');
Route::delete('/institutions/{institution}', [InstitutionController::class, 'destroy'])->name('institutions.destroy');