<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Espaces : une catégorie (Éducation, Arts, Sport…) et une sous-catégorie facultative.
 * Les anciennes catégories (celles des compétitions : rap, danse…) sont rangées dans la nouvelle liste.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('spaces', 'subcategory')) {
            Schema::table('spaces', fn (Blueprint $t) => $t->string('subcategory', 40)->nullable()->after('category'));
        }
        $known = array_keys(config('spaces.categories'));
        $map = ['sport' => 'sport', 'eloquence' => 'education'];
        DB::table('spaces')->orderBy('id')->each(function ($s) use ($known, $map) {
            $cat = $s->category;
            if (($s->kind ?? null) === 'school') {
                $cat = 'education';
            } elseif (! in_array($cat, $known, true)) {
                $cat = $cat ? ($map[$cat] ?? 'arts') : null;
            }
            DB::table('spaces')->where('id', $s->id)->update(['category' => $cat, 'kind' => $cat === 'education' ? 'school' : 'group']);
        });
    }

    public function down(): void
    {
        Schema::table('spaces', fn (Blueprint $t) => $t->dropColumn('subcategory'));
    }
};
