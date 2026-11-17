<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Erreur "métier" (règle non respectée). Renvoie un 422 lisible en JSON,
 * ou revient à la page précédente avec un message sur le web.
 */
class CompetitionException extends RuntimeException
{
    /** $reason : code lisible par l'app (ex. « location_required » : demander la position puis réessayer). */
    public function __construct(string $message, private int $status = 422, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    public function render(Request $request)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(array_filter(['success' => false, 'message' => $this->getMessage(), 'code' => $this->reason], fn ($v) => $v !== null), $this->status);
        }

        return back()->with('error', $this->getMessage())->withInput();
    }
}
