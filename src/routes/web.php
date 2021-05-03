<?php

use Illuminate\Support\Facades\Route;
use PatrykSawicki\Helper\Http\Controllers\FileController;

Route::get('/file/{file}', [FileController::class, 'downloadById']);