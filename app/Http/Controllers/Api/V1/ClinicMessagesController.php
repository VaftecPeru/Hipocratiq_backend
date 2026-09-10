<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClinicMembershipStatus;
use App\Enums\RoleKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicMessageStoreRequest;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\DoctorMessage;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicMessagesController extends Controller
{
    /** @return array<string, mixed> */
    public function index(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $viewer = $request->user();
        $validated = $request->validate([
            'contact_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);
        $contacts = $this->contacts($clinic, $viewer);
        $selectedContactId = isset($validated['contact_user_id'])
            ? (int) $validated['contact_user_id']
            : $contacts->first()['user_id'] ?? null;

        if ($selectedContactId !== null && ! $contacts->contains(fn (array $contact): bool => $contact['user_id'] === $selectedContactId)) {
            abort(404, 'El contacto no pertenece a esta clínica.');
        }

        $recentMessages = $this->messageQuery($clinic, $viewer)
            ->with(['sender:id,name', 'recipient:id,name', 'patientProfile'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();
        $contacts = $this->addConversationSummary($contacts, $recentMessages, $viewer->id);
        $messages = $recentMessages
            ->filter(fn (DoctorMessage $message): bool => $selectedContactId !== null && $this->messageBelongsToContact($message, $viewer->id, $selectedContactId))
            ->reverse()
            ->values();
        $unread = (clone $this->messageQuery($clinic, $viewer))
            ->where('recipient_user_id', $viewer->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'contacts' => $contacts,
                'selected_contact_id' => $selectedContactId,
                'messages' => $messages
                    ->map(fn (DoctorMessage $message): array => $this->serializeMessage($message, $viewer))
                    ->values(),
            ],
            'meta' => [
                'total' => $messages->count(),
                'unread' => $unread,
            ],
        ]);
    }

    public function store(ClinicMessageStoreRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $sender = $request->user();
        $data = $request->validated();
        $recipientId = (int) $data['recipient_user_id'];

        if ($recipientId === $sender->id) {
            throw ValidationException::withMessages([
                'recipient_user_id' => ['No puedes enviarte un mensaje a ti mismo.'],
            ]);
        }

        $patient = isset($data['patient_profile_id']) && $data['patient_profile_id'] !== null
            ? $clinic->patientProfiles()->whereKey($data['patient_profile_id'])->firstOrFail()
            : null;
        $patientRecipient = $clinic->patientProfiles()->where('user_id', $recipientId)->first();
        $teamRecipient = $clinic->memberships()
            ->where('user_id', $recipientId)
            ->where('status', ClinicMembershipStatus::Active->value)
            ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('key', [
                RoleKey::ClinicAdmin->value,
                RoleKey::Receptionist->value,
                RoleKey::Doctor->value,
            ]))
            ->exists();

        if ($patientRecipient === null && ! $teamRecipient) {
            abort(404, 'El destinatario no pertenece a esta clínica.');
        }

        if ($patientRecipient !== null) {
            if ($patient !== null && $patient->id !== $patientRecipient->id) {
                throw ValidationException::withMessages([
                    'patient_profile_id' => ['El paciente no coincide con el destinatario.'],
                ]);
            }
            $patient ??= $patientRecipient;
        }

        $message = DoctorMessage::query()->create([
            'clinic_id' => $clinic->id,
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipientId,
            'patient_profile_id' => $patient?->id,
            'body' => trim($data['body']),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeMessage($message->fresh(['sender:id,name', 'recipient:id,name', 'patientProfile']), $sender),
        ], 201);
    }

    public function markRead(Request $request, DoctorMessage $message): JsonResponse
    {
        $clinic = $this->clinic($request);
        $viewer = $request->user();
        $message = DB::transaction(function () use ($clinic, $viewer, $message): DoctorMessage {
            $lockedMessage = $this->messageQuery($clinic, $viewer)
                ->whereKey($message->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedMessage === null) {
                abort(404, 'Mensaje no encontrado.');
            }

            if ($lockedMessage->recipient_user_id !== $viewer->id) {
                abort(403, 'Solo puedes marcar como leído un mensaje recibido.');
            }

            if ($lockedMessage->read_at === null) {
                $lockedMessage->update(['read_at' => now()]);
            }

            return $lockedMessage->fresh(['sender:id,name', 'recipient:id,name', 'patientProfile']);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeMessage($message, $viewer),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function contacts(Clinic $clinic, User $viewer): Collection
    {
        $members = $clinic->memberships()
            ->where('user_id', '!=', $viewer->id)
            ->where('status', ClinicMembershipStatus::Active->value)
            ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('key', [
                RoleKey::ClinicAdmin->value,
                RoleKey::Receptionist->value,
                RoleKey::Doctor->value,
            ]))
            ->with([
                'user:id,name,email,phone_number,status',
                'user.doctorProfile:id,user_id,specialty',
                'role:id,key,name',
            ])
            ->get()
            ->map(fn (ClinicMembership $membership): array => [
                'user_id' => $membership->user->id,
                'kind' => 'team',
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'phone_number' => $membership->user->phone_number,
                'patient_profile_id' => null,
                'role' => $membership->role->key,
                'role_name' => $membership->role->name,
                'specialty' => $membership->user->doctorProfile?->specialty,
                'status' => $membership->user->status->value,
            ]);

        $patients = $clinic->patientProfiles()
            ->whereNotNull('user_id')
            ->with('user:id,name,email,phone_number,status')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (PatientProfile $patient): array => [
                'user_id' => $patient->user->id,
                'kind' => 'patient',
                'name' => $patient->user->name,
                'email' => $patient->user->email,
                'phone_number' => $patient->user->phone_number,
                'patient_profile_id' => $patient->id,
                'role' => null,
                'role_name' => 'Paciente',
                'specialty' => null,
                'status' => $patient->user->status->value,
            ]);

        return $patients->merge($members)->unique('user_id')->values();
    }

    /** @param Collection<int, array<string, mixed>> $contacts
     * @param  Collection<int, DoctorMessage>  $messages
     * @return Collection<int, array<string, mixed>>
     */
    private function addConversationSummary(Collection $contacts, Collection $messages, int $viewerId): Collection
    {
        $lastMessages = [];
        $unread = [];

        foreach ($messages as $message) {
            $contactId = $message->sender_user_id === $message->recipient_user_id
                ? null
                : ($message->sender_user_id === $viewerId ? $message->recipient_user_id : $message->sender_user_id);

            if ($contactId === null) {
                continue;
            }

            $lastMessages[$contactId] ??= $message;
            if ($message->recipient_user_id !== $viewerId || $message->read_at !== null) {
                continue;
            }
            $unread[$contactId] = ($unread[$contactId] ?? 0) + 1;
        }

        return $contacts->map(function (array $contact) use ($lastMessages, $unread): array {
            /** @var DoctorMessage|null $lastMessage */
            $lastMessage = $lastMessages[$contact['user_id']] ?? null;

            return [
                ...$contact,
                'unread' => $unread[$contact['user_id']] ?? 0,
                'last_message' => $lastMessage === null ? null : [
                    'body' => $lastMessage->body,
                    'created_at' => $lastMessage->created_at?->toIso8601String(),
                ],
            ];
        });
    }

    /** @return Builder<DoctorMessage> */
    private function messageQuery(Clinic $clinic, User $viewer): Builder
    {
        return DoctorMessage::query()
            ->where('clinic_id', $clinic->id)
            ->where(function (Builder $query) use ($viewer): void {
                $query->where('sender_user_id', $viewer->id)->orWhere('recipient_user_id', $viewer->id);
            });
    }

    private function messageBelongsToContact(DoctorMessage $message, int $viewerId, int $contactId): bool
    {
        return ($message->sender_user_id === $viewerId && $message->recipient_user_id === $contactId)
            || ($message->recipient_user_id === $viewerId && $message->sender_user_id === $contactId);
    }

    /** @return array<string, mixed> */
    private function serializeMessage(DoctorMessage $message, User $viewer): array
    {
        return [
            'id' => $message->id,
            'patient_profile_id' => $message->patient_profile_id,
            'sender' => $message->sender?->only(['id', 'name']),
            'recipient' => $message->recipient?->only(['id', 'name']),
            'body' => $message->body,
            'is_received' => $message->recipient_user_id === $viewer->id,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function clinic(Request $request): Clinic
    {
        return $request->attributes->get('clinic');
    }
}
