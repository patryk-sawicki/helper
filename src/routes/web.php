<?php

use Illuminate\Support\Facades\Route;
use PatrykSawicki\Helper\app\Http\Controllers\FileController;

Route::get('/file/{file}', [FileController::class, 'downloadById']);