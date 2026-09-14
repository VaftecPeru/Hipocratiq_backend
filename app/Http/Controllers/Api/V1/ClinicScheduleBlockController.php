<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clinic\CreateClinicScheduleBlock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicScheduleBlockStoreRequest;
use App\Models\Clinic;
use App\Models\ClinicScheduleBlock;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicScheduleBlockController extends Controller
{
    public function scheduleBlocks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfWeek(Carbon::MONDAY);

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : $from->copy()->addDays(6)->endOfDay();

        $blocks = ClinicScheduleBlock::query()
            ->where('clinic_id', $this->clinic($request)->id)
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>=', $from)
            ->with([
                'doctor:id,name',
                'creator:id,name',
            ])
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $blocks->map(
                fn (ClinicScheduleBlock $block): array => $this->serializeScheduleBlock($block)
            )->values(),
        ]);
    }

    public function storeScheduleBlock(ClinicScheduleBlockStoreRequest $request, CreateClinicScheduleBlock $createScheduleBlock): JsonResponse
    {
        $block = $createScheduleBlock
            ->handle(
                $request->validated(),
                $this->clinic($request),
                $request->user(),
            )
            ->load([
                'doctor:id,name',
                'creator:id,name',
            ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeScheduleBlock($block),
        ], 201);
    }

    public function destroyScheduleBlock(Request $request, int $scheduleBlock): JsonResponse
    {
        $clinic = $this->clinic($request);

        $block = ClinicScheduleBlock::query()
            ->where('clinic_id', $clinic->id)
            ->whereKey($scheduleBlock)
            ->firstOrFail();

        $block->delete();

        return response()->json([
            'success' => true,
            'message' => 'Horario desbloqueado correctamente.',
        ]);
    }

    private function clinic(Request $request): Clinic
    {
        return $request->attributes->get('clinic');
    }

    /** @return array<string, mixed> */
    private function serializeScheduleBlock(
        ClinicScheduleBlock $block,
    ): array {
        return [
            'id' => $block->id,

            'starts_at' => $block->starts_at?->toIso8601String(),
            'ends_at' => $block->ends_at?->toIso8601String(),

            'scheduled_date' => $block->starts_at?->toDateString(),
            'start_time' => $block->starts_at?->format('H:i'),
            'end_time' => $block->ends_at?->format('H:i'),

            'reason' => $block->reason,

            'scope' => $block->doctor_user_id === null
                ? 'clinic'
                : 'doctor',

            'doctor' => $block->doctor === null
                ? null
                : [
                    'id' => $block->doctor->id,
                    'name' => $block->doctor->name,
                ],

            'created_by' => [
                'id' => $block->creator->id,
                'name' => $block->creator->name,
            ],
        ];
    }
}
