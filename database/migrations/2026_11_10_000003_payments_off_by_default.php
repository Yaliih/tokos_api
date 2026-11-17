<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/*
 * Paiements :
 *  - réseau mobile money choisi pour un achat de coins (M-Pesa, Orange Money, Airtel Money) ;
 *  - tout ce qui est payant passe sur « non » (coins, votes payants, retraits), à rouvrir depuis l'admin ;
 *    l'achat se règle maintenant séparément pour le site et pour l'app (les deux coupés).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coin_purchases') && ! Schema::hasColumn('coin_purchases', 'operator')) {
            Schema::table('coin_purchases', fn (Blueprint $t) => $t->string('operator', 20)->nullable()->after('phone'));
        }
        if (Schema::hasTable('settings') && ($row = Setting::find('features'))) {
            $v = (array) $row->value;
            unset($v['buy_coins']);
            foreach (['coins', 'buy_coins_web', 'buy_coins_app', 'paid_votes', 'withdrawals'] as $k) {
                $v[$k] = false;
            }
            $row->value = $v;
            $row->save();
            Cache::forget('settings.features');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('coin_purchases', 'operator')) {
            Schema::table('coin_purchases', fn (Blueprint $t) => $t->dropColumn('operator'));
        }
    }
};
