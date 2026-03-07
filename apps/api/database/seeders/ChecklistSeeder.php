<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChecklistSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('trade_checklist_responses')->delete();
        DB::table('checklist_items')->delete();
        DB::table('checklists')->delete();

        $demoEmail = strtolower(trim((string) env('LOCAL_DEMO_EMAIL', 'demo@tradingjournal.local')));
        $demoUserId = User::query()->where('email', $demoEmail)->value('id');

        $checklist = Checklist::query()->create([
            'user_id' => $demoUserId !== null ? (int) $demoUserId : null,
            'account_id' => null,
            'name' => 'Default Trading Rule Set',
            'scope' => 'global',
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);

        $items = [
            [
                'title' => 'Market regime matches setup',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'Before Trading',
                'help_text' => 'Pass only when current regime supports the planned setup.',
                'config' => [],
            ],
            [
                'title' => 'No high-impact news conflict',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'Before Trading',
                'help_text' => 'No planned entry within your defined high-impact news window.',
                'config' => [],
            ],
            [
                'title' => 'Daily risk available (drawdown check)',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'Before Trading',
                'help_text' => 'Account is above stop-for-day and drawdown limits.',
                'config' => [],
            ],
            [
                'title' => 'Emotional readiness score (1-5)',
                'type' => 'scale',
                'required' => true,
                'category' => 'Before Trading',
                'help_text' => 'Rate pre-trade focus and emotional control.',
                'config' => [
                    'min' => 1,
                    'max' => 5,
                    'labels' => [
                        1 => 'Distracted',
                        3 => 'Neutral',
                        5 => 'Calm/Focused',
                    ],
                ],
            ],
            [
                'title' => 'Setup + thesis recorded (1 line)',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'Before Trading',
                'help_text' => 'Single-line setup and thesis is written before entry.',
                'config' => [],
            ],
            [
                'title' => 'Risk per trade (%)',
                'type' => 'number',
                'required' => true,
                'category' => 'During Trading',
                'help_text' => 'Keep risk at or below your plan limit.',
                'config' => [
                    'min' => 0.1,
                    'max' => 2,
                    'step' => 0.1,
                    'unit' => '%',
                    'auto' => 'risk_pct_max',
                    'value' => 1,
                ],
            ],
            [
                'title' => 'Position size calculated from stop distance',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'During Trading',
                'help_text' => 'Size is derived from entry-to-stop distance and fixed risk.',
                'config' => [],
            ],
            [
                'title' => 'Stop-loss at invalidation before/at entry',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'During Trading',
                'help_text' => 'Stop must be technically valid, not arbitrary.',
                'config' => [],
            ],
            [
                'title' => 'Target + R multiple defined before entry (R)',
                'type' => 'number',
                'required' => true,
                'category' => 'During Trading',
                'help_text' => 'Record planned reward-to-risk multiple before execution.',
                'config' => [
                    'min' => 0.5,
                    'max' => 10,
                    'step' => 0.1,
                    'unit' => 'R',
                ],
            ],
            [
                'title' => 'No revenge/risk increase after loss',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'During Trading',
                'help_text' => 'Risk remains fixed regardless of previous outcome.',
                'config' => [],
            ],
            [
                'title' => 'Max trades / stop-for-day rule active',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'During Trading',
                'help_text' => 'Maximum trade count and stop-for-day controls are enforced.',
                'config' => [],
            ],
            [
                'title' => 'Plan followed (yes/no)',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'After Trading',
                'help_text' => 'Pass only if execution matched the written plan.',
                'config' => [],
            ],
            [
                'title' => 'Plan adherence score (%)',
                'type' => 'number',
                'required' => true,
                'category' => 'After Trading',
                'help_text' => 'Score process adherence from 0 to 100.',
                'config' => [
                    'min' => 0,
                    'max' => 100,
                    'step' => 5,
                    'unit' => '%',
                ],
            ],
            [
                'title' => 'Execution timing quality score (1-5)',
                'type' => 'scale',
                'required' => true,
                'category' => 'After Trading',
                'help_text' => 'Score timing quality after the trade closes.',
                'config' => [
                    'min' => 1,
                    'max' => 5,
                    'labels' => [
                        1 => 'Poor',
                        3 => 'Neutral',
                        5 => 'Excellent',
                    ],
                ],
            ],
            [
                'title' => 'Screenshot + annotations saved',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'After Trading',
                'help_text' => 'Trade image and notes were attached for review.',
                'config' => [],
            ],
            [
                'title' => 'Mistake category selected',
                'type' => 'dropdown',
                'required' => true,
                'category' => 'After Trading',
                'help_text' => 'Choose the primary mistake category, or No Mistake.',
                'config' => [
                    'options' => ['No Mistake', 'Execution', 'Risk', 'Discipline', 'Setup'],
                ],
            ],
            [
                'title' => 'Repeat/change actions logged (0-2)',
                'type' => 'number',
                'required' => true,
                'category' => 'After Trading',
                'help_text' => 'Count complete only when one repeat and one change action are logged.',
                'config' => [
                    'min' => 0,
                    'max' => 2,
                    'step' => 1,
                    'comparator' => '>=',
                    'threshold' => 2,
                ],
            ],
            [
                'title' => 'Next-session rule update status',
                'type' => 'dropdown',
                'required' => false,
                'category' => 'After Trading',
                'help_text' => 'Track whether a new rule was added for the next session.',
                'config' => [
                    'options' => ['Not needed', 'Updated', 'Pending'],
                ],
            ],
        ];

        foreach ($items as $index => $item) {
            ChecklistItem::query()->create([
                'checklist_id' => (int) $checklist->id,
                'order_index' => $index,
                'title' => (string) $item['title'],
                'type' => (string) $item['type'],
                'required' => (bool) $item['required'],
                'category' => (string) $item['category'],
                'help_text' => $item['help_text'],
                'config' => $item['config'],
                'is_active' => true,
            ]);
        }
    }
}
