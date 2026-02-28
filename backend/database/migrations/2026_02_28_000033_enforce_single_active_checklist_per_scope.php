<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->deactivateLegacyScopeCollisions();

        Schema::table('checklists', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_global_scope_user_id')
                ->nullable()
                ->storedAs("CASE WHEN is_active = 1 AND scope = 'global' THEN user_id ELSE NULL END")
                ->after('strategy_model_id');
            $table->unsignedBigInteger('active_account_scope_id')
                ->nullable()
                ->storedAs("CASE WHEN is_active = 1 AND scope = 'account' THEN account_id ELSE NULL END")
                ->after('active_global_scope_user_id');
            $table->unsignedBigInteger('active_strategy_scope_id')
                ->nullable()
                ->storedAs("CASE WHEN is_active = 1 AND scope = 'strategy' THEN strategy_model_id ELSE NULL END")
                ->after('active_account_scope_id');

            $table->unique(['active_global_scope_user_id'], 'checklists_unique_active_global_scope');
            $table->unique(['user_id', 'active_account_scope_id'], 'checklists_unique_active_account_scope');
            $table->unique(['user_id', 'active_strategy_scope_id'], 'checklists_unique_active_strategy_scope');
        });
    }

    public function down(): void
    {
        Schema::table('checklists', function (Blueprint $table): void {
            $table->dropUnique('checklists_unique_active_global_scope');
            $table->dropUnique('checklists_unique_active_account_scope');
            $table->dropUnique('checklists_unique_active_strategy_scope');
            $table->dropColumn([
                'active_global_scope_user_id',
                'active_account_scope_id',
                'active_strategy_scope_id',
            ]);
        });
    }

    private function deactivateLegacyScopeCollisions(): void
    {
        $this->deactivateCollisionsForScope('global', ['user_id']);
        $this->deactivateCollisionsForScope('account', ['user_id', 'account_id']);
        $this->deactivateCollisionsForScope('strategy', ['user_id', 'strategy_model_id']);
    }

    /**
     * @param array<int,string> $groupColumns
     */
    private function deactivateCollisionsForScope(string $scope, array $groupColumns): void
    {
        $grouped = DB::table('checklists')
            ->select($groupColumns)
            ->where('scope', $scope)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->when(in_array('account_id', $groupColumns, true), fn ($query) => $query->whereNotNull('account_id'))
            ->when(in_array('strategy_model_id', $groupColumns, true), fn ($query) => $query->whereNotNull('strategy_model_id'))
            ->groupBy($groupColumns)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($grouped as $row) {
            $rows = DB::table('checklists')
                ->select('id')
                ->where('scope', $scope)
                ->where('is_active', true)
                ->where('user_id', (int) $row->user_id)
                ->when(
                    in_array('account_id', $groupColumns, true),
                    fn ($query) => $query->where('account_id', (int) $row->account_id)
                )
                ->when(
                    in_array('strategy_model_id', $groupColumns, true),
                    fn ($query) => $query->where('strategy_model_id', (int) $row->strategy_model_id)
                )
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (count($rows) <= 1) {
                continue;
            }

            $winnerId = $rows[0];
            $deactivateIds = array_values(array_filter($rows, fn (int $id): bool => $id !== $winnerId));
            if ($deactivateIds === []) {
                continue;
            }

            DB::table('checklists')
                ->whereIn('id', $deactivateIds)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }
    }
};
