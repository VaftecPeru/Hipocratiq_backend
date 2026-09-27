<?php

namespace App\Enums;

enum PatientConsentScope: string
{
    case ClinicalCare = 'clinical_care';
    case DataProcessing = 'data_processing';
    case WhatsAppNotifications = 'whatsapp_notifications';
    case Telemedicine = 'telemedicine';
    case AiClinicalAssistance = 'ai_clinical_assistance';
    case EpidemiologyAnonymized = 'epidemiology_anonymized';
}
