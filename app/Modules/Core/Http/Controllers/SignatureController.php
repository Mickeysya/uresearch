<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\SafeReturnPath;
use Illuminate\Http\Request;

/**
 * "My Signatures": one page for every signature a person keeps, instead of
 * one "My Signature" page per module. Each section, and the upload and remove
 * routes it posts to, comes from the module that owns it
 * (Contracts\SuppliesSignature); this only lays them out.
 */
class SignatureController extends Controller
{
    public function __invoke(Request $request, ModuleRegistry $registry)
    {
        $signatures = $registry->signaturesFor($request->user());

        abort_if($signatures === [], 404);

        // Arriving from a module's page (an appeal, a queue) brings that page
        // along as ?return=: Back goes there, and so does an upload.
        return view('core::signatures.index', [
            'signatures' => $signatures,
            'return' => SafeReturnPath::validate($request->query('return')),
        ]);
    }
}
