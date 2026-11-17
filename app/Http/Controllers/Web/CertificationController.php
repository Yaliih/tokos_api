<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\VerificationRequest;
use App\Services\CertificationService;
use Illuminate\Http\Request;

/** Demander le badge certifié, pour soi ou pour un Espace qu'on gère. */
class CertificationController extends Controller
{
    public function __construct(private CertificationService $service)
    {
    }

    public function show(Request $request)
    {
        $u = $request->user();
        $spaces = Space::whereIn('id', $u->manageableSpaceIds() ?: [0])->get();
        $space = $request->query('espace') ? $spaces->firstWhere('slug', $request->query('espace')) : null;
        $last = VerificationRequest::where('user_id', $u->id)->where('space_id', $space?->id)->latest('id')->first();

        return view('web.pages.certification', [
            'title' => 'Certification', 'hideRail' => true, 'current' => 'profile',
            'spaces' => $spaces, 'space' => $space, 'last' => $last,
            'verified' => $space ? (bool) $space->is_verified : $u->verified_at !== null,
            'code' => CertificationService::selfieCode($u),
            'hasAvatar' => (bool) ($space ? $space->getRawOriginal('avatar') : $u->getRawOriginal('avatar')),
        ]);
    }

    public function submit(Request $request)
    {
        $u = $request->user();
        $space = $request->input('space') ? Space::whereIn('id', $u->manageableSpaceIds() ?: [0])->where('slug', $request->input('space'))->firstOrFail() : null;
        $kind = $space ? 'space' : 'user';
        $kb = (int) config('certification.max_file_kb');
        $needsProof = $space || $request->input('category') === 'student';
        $data = $request->validate([
            'category' => 'required|in:'.implode(',', array_keys(config('certification.categories.'.$kind))),
            'full_name' => 'required|string|min:3|max:120',
            'document_type' => 'required|in:'.implode(',', array_keys(config('certification.documents'))),
            'document' => "required|file|mimes:jpg,jpeg,png,webp,pdf|max:$kb",
            'selfie' => "required|image|max:$kb",
            'proof' => ($needsProof ? 'required' : 'nullable')."|file|mimes:jpg,jpeg,png,webp,pdf|max:$kb",
            'links' => 'nullable|string|max:600',
            'note' => 'nullable|string|max:600',
        ], [
            'document.required' => 'Ajoute la photo de ta pièce.',
            'selfie.required' => 'Ajoute le selfie avec ta pièce et le code.',
            'proof.required' => $space ? 'Ajoute un document officiel de l\'organisation.' : 'Ajoute ta carte d\'étudiant ou ton attestation d\'inscription.',
        ]);
        $this->service->submit($u, $data, $space);

        return redirect('/parametres/certification'.($space ? '?espace='.$space->slug : ''))->with('ok', 'Demande envoyée. Tu seras prévenu de la décision.');
    }
}
