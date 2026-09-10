<?php

namespace App\Http\Requests\Api\V1;

class ClinicReceptionistInvitationRequest extends ClinicMemberStoreRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
