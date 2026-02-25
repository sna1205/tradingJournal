<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChecklistSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('trade_checklist_responses')->delete();
        DB::table('checklist_items')->delete();
        DB::table('checklists')->delete();

        $checklist = Checklist::query()->create([
            'user_id' => null,
            'account_id' => null,
            'name' => 'Default Pre-Trade Checklist',
            'scope' => 'global',
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);

        $items = [
            [
                'title' => 'Market structure is clear on HTF',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'Structure',
                'help_text' => 'Confirm directional bias and key structure before execution.',
                'config' => [],
            ],
            [
                'title' => 'Entry trigger matches setup rules',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'Execution',
                'help_text' => 'Only execute when the predefined trigger condition is present.',
                'config' => [],
            ],
            [
                'title' => 'Risk per trade (%)',
                'type' => 'number',
                'required' => true,
                'category' => 'Risk',
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
                'title' => 'Stop loss is at invalidation level',
                'type' => 'checkbox',
                'required' => true,
                'category' => 'Risk',
                'help_text' => 'Stop must be technically valid, not arbitrary.',
                'config' => [],
            ],
            [
                'title' => 'Expected R multiple at target',
                'type' => 'number',
                'required' => false,
                'category' => 'Risk',
                'help_text' => 'Record planned reward-to-risk multiple for review.',
                'config' => [
                    'min' => 0.5,
                    'max' => 10,
                    'step' => 0.1,
                    'unit' => 'R',
                ],
            ],
            [
                'title' => 'Session quality',
                'type' => 'dropdown',
                'required' => false,
                'category' => 'Structure',
                'help_text' => 'Choose the current session condition.',
                'config' => [
                    'options' => ['A+', 'A', 'B', 'C'],
                ],
            ],
            [
                'title' => 'Emotional state before execution',
                'type' => 'scale',
                'required' => true,
                'category' => 'Psychology',
                'help_text' => 'Score calmness and control before pressing buy/sell.',
                'config' => [
                    'min' => 1,
                    'max' => 5,
                    'labels' => [
                        1 => 'Tilted',
                        3 => 'Neutral',
                        5 => 'Calm',
                    ],
                ],
            ],
            [
                'title' => 'If this fails, what is the lesson?',
                'type' => 'text',
                'required' => false,
                'category' => 'Psychology',
                'help_text' => 'Prime review quality before the outcome is known.',
                'config' => [
                    'maxLength' => 240,
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
