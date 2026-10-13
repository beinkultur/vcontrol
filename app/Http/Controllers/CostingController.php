<?php

namespace App\Http\Controllers;

use App\Access\Module;
use App\Models\Costing;
use App\Support\CostingPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Costing als PDF zum Ansehen, für alle, die das Costing sehen (CostingPolicy) – nur mit dem Modul Preislisten & Costing. */
class CostingController extends Controller
{
    public function pdf(Costing $costing): Response
    {
        abort_unless(Module::PriceLists->isEnabled(), 404);
        Gate::authorize('view', $costing);

        return response(CostingPdf::render($costing), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . addcslashes($costing->fileName(), '"\\') . '"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
