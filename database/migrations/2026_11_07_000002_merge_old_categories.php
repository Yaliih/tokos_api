<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Disciplines regroupées : « musique » rejoint « chant », « photo » rejoint « art ». */
return new class extends Migration
{
    private const MAP = ['musique' => 'chant', 'photo' => 'dessin'];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('competitions')->where('category', $old)->update(['category' => $new]);
        }
        DB::table('users')->whereNotNull('interests')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $u) {
                $list = json_decode((string) $u->interests, true);
                if (! is_array($list)) {
                    continue;
                }
                $fixed = array_values(array_unique(array_map(fn ($k) => self::MAP[$k] ?? $k, $list)));
                if ($fixed !== $list) {
                    DB::table('users')->where('id', $u->id)->update(['interests' => json_encode($fixed)]);
                }
            }
        });
    }

    public function down(): void {}
};
