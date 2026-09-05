<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Makes an existing install pick up the paperwork model on a plain
     * `migrate` (no seeders): create the two built-in types and turn every
     * existing members.paperwork_date into a "Standard Paperwork"
     * member_paperwork row, so behaviour is unchanged. On a fresh
     * `migrate:fresh --seed` this runs against empty tables and is a no-op;
     * PaperworkTypeSeeder then creates the same rows with the Pool add-on
     * id correctly resolved (both keyed on name, so they don't collide).
     */
    public function up(): void
    {
        // Resolvable on an existing install (add_ons already seeded); null
        // on a fresh migrate -- PaperworkTypeSeeder fixes it in the seed
        // phase.
        $poolAddOnId = DB::table('add_ons')->where('name', 'Pool')->value('id');

        DB::table('paperwork_types')->updateOrInsert(
            ['name' => 'Standard Paperwork'],
            [
                'description' => 'The membership paperwork / NDA every member signs once.',
                'required' => true,
                'renewal_months' => null,
                'gates_add_on_id' => null,
                'sort_order' => 0,
                'active' => true,
            ],
        );

        DB::table('paperwork_types')->updateOrInsert(
            ['name' => 'Pool Waiver'],
            [
                'description' => 'Liability waiver required before a member may use the pool. Renews yearly.',
                'required' => true,
                'renewal_months' => 12,
                'gates_add_on_id' => $poolAddOnId,
                'sort_order' => 1,
                'active' => true,
            ],
        );

        $standardId = DB::table('paperwork_types')->where('name', 'Standard Paperwork')->value('id');

        DB::table('members')
            ->whereNotNull('paperwork_date')
            ->orderBy('id')
            ->select('id', 'paperwork_date')
            ->chunkById(500, function ($members) use ($standardId): void {
                $rows = $members->map(fn ($m) => [
                    'member_id' => $m->id,
                    'paperwork_type_id' => $standardId,
                    'signed_on' => $m->paperwork_date,
                    'recorded_by' => null,
                    'created_at' => now(),
                ])->all();

                DB::table('member_paperwork')->insert($rows);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $standardId = DB::table('paperwork_types')->where('name', 'Standard Paperwork')->value('id');

        if ($standardId) {
            DB::table('member_paperwork')->where('paperwork_type_id', $standardId)->delete();
        }

        DB::table('paperwork_types')->whereIn('name', ['Standard Paperwork', 'Pool Waiver'])->delete();
    }
};
