<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class GetMediaController extends Controller
{

    public function file($path)
    {
        $path = preg_replace(
            '/,(?=[^,]*$)/',
            '.',
            preg_replace('#&#', '/', $path)
        );
        $newPath = storage_path() . '/app/public/' . $path;
        return response()->file($newPath);
    }
}
