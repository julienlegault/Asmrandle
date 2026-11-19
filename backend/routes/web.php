<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(base_path('../frontend/index.html')));

Route::get('/{directory}/{file}', function (string $directory, string $file) {
    abort_unless(in_array($directory, ['images', 'favicons'], true), 404);
    $path = base_path("../frontend/{$directory}/{$file}");
    abort_unless(is_file($path), 404);

    return response()->file($path);
})->where('file', '[A-Za-z0-9._-]+');
