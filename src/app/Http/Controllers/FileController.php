<?php

namespace PatrykSawicki\Helper\app\Http\Controllers;

use PatrykSawicki\Helper\app\Models\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends Controller
{
    function __construct()
    {
        $auth=config('filesSettings.middleware.auth');
        if(isset($auth))
            $this->middleware('auth:'.$auth);

        $permission=config('filesSettings.middleware.permission');
        if(isset($permission))
            $this->middleware('permission:'.$permission);
    }

    public function downloadById(File $file): BinaryFileResponse
    {
        return response()->download('../storage/app'.$file, $file->name);
    }
}
