<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('support_tickets')
            ->whereNull('due_at')
            ->orderBy('id')
            ->chunkById(200, function ($tickets): void {
                foreach ($tickets as $ticket) {
                    $hours = match ($ticket->priority) {
                        'urgent' => 2,
                        'high' => 8,
                        'low' => 72,
                        default => 24,
                    };

                    DB::table('support_tickets')
                        ->where('id', $ticket->id)
                        ->update([
                            'due_at' => Carbon::parse($ticket->created_at)->addHours($hours),
                            'last_activity_at' => $ticket->updated_at ?? $ticket->created_at,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Los vencimientos forman parte del historial operativo y no se eliminan.
    }
};
