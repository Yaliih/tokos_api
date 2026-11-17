<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Push\WebPush;
use Illuminate\Http\Request;

/** Abonnement d'un navigateur aux notifications push. */
class PushController extends Controller
{
    public function key()
    {
        return response()->json(['key' => WebPush::keys()['public']]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|url|max:2000',
            'keys.p256dh' => 'required|string|max:200',
            'keys.auth' => 'required|string|max:60',
        ]);
        // Seuls les vrais services push (Google, Mozilla, Apple, Microsoft) sont acceptés
        $host = (string) parse_url($data['endpoint'], PHP_URL_HOST);
        abort_unless(preg_match('/(googleapis\.com|mozilla\.com|mozaws\.net|push\.apple\.com|notify\.windows\.com)$/', $host), 422);
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            // Sans compte : le navigateur reçoit quand même les annonces à tout le monde
            ['user_id' => $request->user()?->id, 'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255)]
        );

        return response()->json(['success' => true, 'message' => 'Notifications activées sur cet appareil.']);
    }

    public function unsubscribe(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');
        PushSubscription::where('endpoint_hash', hash('sha256', $endpoint))
            ->where(fn ($q) => $request->user() ? $q->where('user_id', $request->user()->id)->orWhereNull('user_id') : $q->whereNull('user_id'))->delete();

        return response()->json(['success' => true, 'message' => 'Notifications coupées sur cet appareil.']);
    }
}
