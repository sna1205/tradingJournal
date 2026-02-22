<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedReport;
use App\Models\Trade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function index(Request $request)
    {
        $scope = (string) $request->query('scope', '');

        $reports = SavedReport::query()
            ->when($scope !== '', fn ($query) => $query->where('scope', $scope))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json($reports);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $payload = $this->validatePayload($request);
        if ((bool) ($payload['is_default'] ?? false)) {
            SavedReport::query()->where('scope', $payload['scope'])->update(['is_default' => false]);
        }

        $report = SavedReport::query()->create($payload);

        return response()->json($report, 201);
    }

    public function show(SavedReport $report)
    {
        return response()->json($report);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, SavedReport $report)
    {
        $payload = $this->validatePayload($request, true);
        $scope = (string) ($payload['scope'] ?? $report->scope);
        if ((bool) ($payload['is_default'] ?? false)) {
            SavedReport::query()
                ->where('scope', $scope)
                ->where('id', '!=', $report->id)
                ->update(['is_default' => false]);
        }

        $report->fill($payload);
        $report->save();

        return response()->json($report);
    }

    public function destroy(SavedReport $report)
    {
        $report->delete();
        return response()->noContent();
    }

    public function run(Request $request, SavedReport $report)
    {
        $filters = $this->mergedFilters($report, $request);
        $perPage = max(1, min((int) $request->integer('per_page', 100), 500));

        $query = Trade::query()
            ->with(['account', 'instrument', 'strategyModel', 'setup', 'killzone', 'tags'])
            ->applyFilters($filters)
            ->orderByDesc('date')
            ->orderByDesc('id');

        $rows = $query->paginate($perPage);

        return response()->json([
            'report' => $report,
            'filters' => $filters,
            'columns' => $this->resolvedColumns($report),
            'rows' => $rows,
        ]);
    }

    public function exportCsv(Request $request, SavedReport $report): StreamedResponse
    {
        $filters = $this->mergedFilters($report, $request);
        $columns = $this->resolvedColumns($report);
        $fileName = sprintf(
            '%s-%s.csv',
            preg_replace('/[^a-z0-9_-]+/i', '-', strtolower((string) $report->name)) ?: 'report',
            now()->format('Ymd-His')
        );

        $query = Trade::query()
            ->with(['account', 'instrument', 'strategyModel', 'setup', 'killzone', 'tags', 'psychology'])
            ->applyFilters($filters)
            ->orderByDesc('date')
            ->orderByDesc('id');

        return response()->streamDownload(function () use ($query, $columns): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, $columns);

            $query->chunk(500, function ($trades) use ($columns, $handle): void {
                foreach ($trades as $trade) {
                    fputcsv($handle, $this->tradeToCsvRow($trade, $columns));
                }
            });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportCsvFromQuery(Request $request): StreamedResponse
    {
        $scope = (string) $request->query('scope', 'trades');
        $name = (string) $request->query('name', 'custom-report');
        $columnsRaw = $request->query('columns', '');
        $columns = is_string($columnsRaw) && $columnsRaw !== ''
            ? array_filter(array_map('trim', explode(',', $columnsRaw)), fn (string $value): bool => $value !== '')
            : [];

        $report = new SavedReport([
            'name' => $name,
            'scope' => $scope,
            'filters_json' => $request->query(),
            'columns_json' => $columns,
            'is_default' => false,
        ]);

        return $this->exportCsv($request, $report);
    }

    /**
     * @throws ValidationException
     * @return array{
     *   name:string,
     *   scope:string,
     *   filters_json:array<string,mixed>,
     *   columns_json:?array<int,string>,
     *   is_default:bool
     * }
     */
    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';
        $validated = Validator::make($request->all(), [
            'name' => [$required, 'string', 'max:120'],
            'scope' => [$required, 'string', 'in:trades,dashboard'],
            'filters_json' => [$required, 'array'],
            'columns_json' => ['nullable', 'array'],
            'columns_json.*' => ['string', 'max:80'],
            'is_default' => ['sometimes', 'boolean'],
        ])->validate();

        return [
            'name' => (string) ($validated['name'] ?? ''),
            'scope' => (string) ($validated['scope'] ?? 'trades'),
            'filters_json' => (array) ($validated['filters_json'] ?? []),
            'columns_json' => array_key_exists('columns_json', $validated)
                ? (is_array($validated['columns_json']) ? array_values($validated['columns_json']) : null)
                : null,
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mergedFilters(SavedReport $report, Request $request): array
    {
        $reportFilters = is_array($report->filters_json) ? $report->filters_json : [];
        $queryFilters = $request->query();

        unset($queryFilters['page'], $queryFilters['per_page']);
        return array_merge($reportFilters, $queryFilters);
    }

    /**
     * @return array<int,string>
     */
    private function resolvedColumns(SavedReport $report): array
    {
        $default = [
            'id',
            'date',
            'pair',
            'direction',
            'account',
            'strategy_model',
            'setup',
            'killzone',
            'session_enum',
            'profit_loss',
            'r_multiple',
            'followed_rules',
            'emotion',
            'tags',
            'confidence_score',
            'stress_score',
        ];
        $columns = is_array($report->columns_json) ? $report->columns_json : [];
        if (count($columns) === 0) {
            return $default;
        }

        return array_values(array_unique(array_map(fn ($value): string => (string) $value, $columns)));
    }

    /**
     * @param Trade $trade
     * @param array<int,string> $columns
     * @return array<int,string|int|float|bool|null>
     */
    private function tradeToCsvRow(Trade $trade, array $columns): array
    {
        $map = [
            'id' => (int) $trade->id,
            'date' => (string) $trade->date,
            'pair' => (string) $trade->pair,
            'direction' => (string) $trade->direction,
            'account' => (string) ($trade->account?->name ?? ''),
            'strategy_model' => (string) ($trade->strategyModel?->name ?? $trade->model ?? ''),
            'setup' => (string) ($trade->setup?->name ?? ''),
            'killzone' => (string) ($trade->killzone?->name ?? ''),
            'session_enum' => (string) ($trade->session_enum ?? ''),
            'profit_loss' => (float) ($trade->profit_loss ?? 0),
            'gross_profit_loss' => (float) ($trade->gross_profit_loss ?? 0),
            'costs_total' => (float) ($trade->costs_total ?? 0),
            'r_multiple' => (float) ($trade->realized_r_multiple ?? $trade->r_multiple ?? 0),
            'followed_rules' => (bool) $trade->followed_rules,
            'emotion' => (string) ($trade->emotion ?? ''),
            'tags' => $trade->tags->pluck('name')->implode('|'),
            'confidence_score' => $trade->psychology?->confidence_score,
            'stress_score' => $trade->psychology?->stress_score,
            'impulse_flag' => $trade->psychology?->impulse_flag,
            'fomo_flag' => $trade->psychology?->fomo_flag,
            'revenge_flag' => $trade->psychology?->revenge_flag,
        ];

        return array_map(fn (string $key) => $map[$key] ?? '', $columns);
    }
}
