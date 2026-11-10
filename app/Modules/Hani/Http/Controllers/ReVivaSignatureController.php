<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Support\SafeReturnPath;
use App\Modules\Hani\Models\ReVivaSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** The Dean's "Re-viva Correction Letters" signature on Core's My Signatures page. */
class ReVivaSignatureController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['signature' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024']]);
        $old = ReVivaSignature::forUser($request->user()->id);
        $path = $request->file('signature')->store('hani/reviva-signatures', 'local');

        ReVivaSignature::updateOrCreate(['user_id' => $request->user()->id], ['path' => $path]);
        if ($old && $old->path !== $path) {
            Storage::disk('local')->delete($old->path);
        }

        $return = SafeReturnPath::validate($request->input('return'));

        return ($return ? redirect($return) : back())->with('status', 'Re-viva signature saved.');
    }

    /** Served to its owner only. */
    public function image(Request $request)
    {
        $signature = ReVivaSignature::forUser($request->user()->id);
        abort_unless($signature && Storage::disk('local')->exists($signature->path), 404);

        return Storage::disk('local')->response($signature->path, null, ['Cache-Control' => 'private, no-store']);
    }
}
