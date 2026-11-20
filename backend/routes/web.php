<?php

use App\Http\Controllers\AdminController;
use App\Http\Middleware\AuthenticateAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(base_path('../frontend/index.html')));

Route::get('/admin/login', [AdminController::class, 'loginForm'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:5,1');
Route::middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/admin', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout');
});

Route::get('/{directory}/{file}', function (string $directory, string $file) {
    abort_unless(in_array($directory, ['images', 'favicons'], true), 404);
    $path = base_path("../frontend/{$directory}/{$file}");
    abort_unless(is_file($path), 404);

    return response()->file($path);
})->where('file', '[A-Za-z0-9._-]+');
