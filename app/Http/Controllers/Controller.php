<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Utilisateur connecté s'il y en a un, y compris sur les routes publiques
     * (jeton Bearer Flutter ou session web).
     */
    protected function viewer(Request $request): ?User
    {
        return $request->user() ?? auth('sanctum')->user() ?? auth('web')->user();
    }
}
