<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class EntradaController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('catalog');
        }

        return Inertia::render('Entrada');
    }
}
