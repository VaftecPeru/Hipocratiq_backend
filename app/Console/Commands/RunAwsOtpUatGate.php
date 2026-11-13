<?php

namespace App\Console\Commands;

use App\Contracts\PhoneOtpProvider;
use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OtpDeliveryChannel;
use App\Enums\OtpDeliveryStatus;
use App\Enums\OtpPurpose;
use App\Models\Clinic;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\AwsOtpReadiness;
use App\Support\PhoneNumber;
use Aws\EndUserMessaging\EndUserMessagingClient;
use Aws\PinpointSMSVoiceV2\PinpointSMSVoiceV2Client;
use Aws\Sts\StsClient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunAwsOtpUatGate extends Command
{
    protected $signature = 'otp:aws-uat-gate
        {--phone= : Número E.164 que recibirá el SMS real}
        {--database=hipocratiq_b109_uat : Base UAT esperada}
        {--region=us-east-2 : Región AWS Notify}
        {--target-country= : País ISO objetivo; por defecto APP_PRIMARY_COUNTRY (MX)}
        {--code-configuration= : Notify Code Configuration ID a utilizar}
        {--origination-identity= : Identidad AWS de origen opcional; si se omite se descubre de forma segura}
        {--keep-data : Conserva usuario y clínica UAT creados por el gate}';

    protected $description = 'Ejecuta el gate UAT OTP directo con AWS End User Messaging -> verificación -> Sanctum móvil.';

    private ?int $uatUserId = null;

    private ?int $uatClinicId = null;

    public function handle(HttpKernel $kernel, AwsOtpReadiness $readiness): int
    {
        if ($this->guardEnvironment() === false) {

            return self::FAILURE;
        }

        $targetCountry = $this->resolveTargetCountry();
        if ($targetCountry === null) {

            return self::FAILURE;
        }

        $phone = $this->resolvePhone($targetCountry);
        if ($phone === null) {

            return self::FAILURE;
        }

        $phoneNumber = PhoneNumber::fromE164($phone);
        if ($phoneNumber->countryCode !== $targetCountry) {
            $this->error(
                "B-109 está configurado para {$targetCountry}; el número ingresado pertenece a {$phoneNumber->countryCode}.",
            );

            return self::FAILURE;
        }

        $region = trim((string) $this->option('region'));

        try {
            $this->newLine();
            $this->components->info('1/7 IAM y configuración AWS OTP directa');
            $this->line("País objetivo: {$targetCountry}");
            $aws = $this->discoverAwsConfiguration($region, $targetCountry);

            $this->activateRuntimeConfiguration(
                $region,
                $aws['origination_identity'],
                $aws['code_configuration_id'],
            );

            $this->newLine();
            $this->components->info('2/7 otp:aws-check');
            $checkExit = Artisan::call('otp:aws-check');
            $this->output->write(Artisan::output());

            if ($checkExit !== self::SUCCESS || $readiness->status()['ready'] !== true) {
                throw new RuntimeException('otp:aws-check no quedó en verde.');
            }

            $this->newLine();
            $this->components->info('3/7 Preparar datos UAT aislados');
            $clinic = $this->createUatClinic($phone);

            if (User::query()->where('phone_number', $phone)->exists()) {
                throw new RuntimeException(
                    'El teléfono ya existe en la base UAT. Usa otro número de prueba o limpia el usuario UAT anterior.',
                );
            }

            $deviceName = 'HIPOCRATIQ B-109 UAT';
            $deviceId = 'hipocratiq-b109-uat-device-'.Str::lower(Str::random(12));

            $this->newLine();
            $this->components->info('4/7 Enviar SMS OTP real por el flujo HTTP de Laravel');

            $invalidPhone = 'invalid-'.Str::lower(Str::random(10));
            [$invalidPhoneStatus, $invalidPhoneBody] = $this->jsonRequest(
                $kernel,
                'POST',
                '/api/v1/auth/otp/request',
                [
                    'purpose' => OtpPurpose::Registration->value,
                    'client_type' => AuthenticationClient::Mobile->value,
                    'actor_context' => AuthenticationContext::Patient->value,
                    'requested_role' => 'patient',
                    'name' => 'B-109 Invalid Phone',
                    'country_code' => $targetCountry,
                    'phone_number' => $invalidPhone,
                    'clinic_id' => $clinic->id,
                    'device_name' => $deviceName,
                    'device_id' => $deviceId,
                    'terms_accepted' => true,
                ],
            );

            if ($invalidPhoneStatus !== 422) {
                throw new RuntimeException(
                    'El negativo de teléfono inválido respondió HTTP '
                    .$invalidPhoneStatus.': '.$this->safeJson($invalidPhoneBody),
                );
            }

            $this->line('OK: teléfono inválido rechazado antes de AWS.');

            [$requestStatus, $requestBody] = $this->jsonRequest($kernel, 'POST', '/api/v1/auth/otp/request', [
                'purpose' => OtpPurpose::Registration->value,
                'client_type' => AuthenticationClient::Mobile->value,
                'actor_context' => AuthenticationContext::Patient->value,
                'requested_role' => 'patient',
                'name' => 'B-109 AWS UAT '.now()->format('His'),
                'email' => 'b109-'.Str::lower(Str::random(12)).'@example.invalid',
                'phone_number' => $phone,
                'clinic_id' => $clinic->id,
                'device_name' => $deviceName,
                'device_id' => $deviceId,
                'terms_accepted' => true,
            ]);

            if ($requestStatus !== 202) {
                throw new RuntimeException(
                    'La solicitud OTP respondió HTTP '.$requestStatus.': '.$this->safeJson($requestBody),
                );
            }

            $challengeId = data_get($requestBody, 'data.challenge_id');
            if (is_string($challengeId) === false || $challengeId === '') {
                throw new RuntimeException('La respuesta OTP no devolvió challenge_id.');
            }

            foreach (['code', 'provider_message_id', 'provider_verification_id', 'access_token'] as $forbiddenKey) {
                if (data_get($requestBody, 'data.'.$forbiddenKey) !== null) {
                    throw new RuntimeException("La respuesta pública expuso [{$forbiddenKey}].");
                }
            }
            $this->line('OK: respuesta pública sin OTP ni metadata interna de AWS.');

            $challenge = OtpChallenge::query()->findOrFail($challengeId);
            $this->uatUserId = (int) $challenge->user_id;

            if (
                $challenge->verification_provider !== 'aws_notify'
                || $challenge->delivery_status !== OtpDeliveryStatus::Sent
                || $challenge->code_hash !== null
            ) {
                throw new RuntimeException('El challenge real no quedó persistido como AWS Notify externo.');
            }

            $this->line('SMS enviado. Challenge: '.$challengeId);
            $this->line('Provider: aws_notify');
            $this->line('Message ID: '.($challenge->provider_message_id ? 'registrado' : 'no devuelto'));
            $this->line('Verification ID: '.($challenge->provider_verification_id ? 'registrado' : 'no devuelto'));

            $code = trim((string) $this->secret('Ingresa el OTP de 6 dígitos recibido por SMS'));
            if (preg_match('/^\d{6}$/', $code) !== 1) {
                throw new RuntimeException('El OTP ingresado debe contener exactamente 6 dígitos.');
            }

            $wrongCode = $this->differentCode($code);

            $this->newLine();
            $this->components->info('5/7 Escenarios negativos');

            [$wrongDeviceStatus] = $this->jsonRequest($kernel, 'POST', '/api/v1/auth/otp/verify', [
                'purpose' => OtpPurpose::Registration->value,
                'client_type' => AuthenticationClient::Mobile->value,
                'challenge_id' => $challengeId,
                'code' => $wrongCode,
                'device_name' => $deviceName,
                'device_id' => $deviceId.'-mismatch',
            ]);

            $challenge->refresh();
            if ($wrongDeviceStatus !== 422 || $challenge->attempts !== 0) {
                throw new RuntimeException('Falló el negativo de device binding: debe rechazar sin consumir intento AWS.');
            }
            $this->line('OK: dispositivo distinto rechazado sin consumir intento.');

            [$wrongCodeStatus] = $this->jsonRequest($kernel, 'POST', '/api/v1/auth/otp/verify', [
                'purpose' => OtpPurpose::Registration->value,
                'client_type' => AuthenticationClient::Mobile->value,
                'challenge_id' => $challengeId,
                'code' => $wrongCode,
                'device_name' => $deviceName,
                'device_id' => $deviceId,
            ]);

            $challenge->refresh();
            if ($wrongCodeStatus !== 422 || $challenge->attempts !== 1 || $challenge->invalidated_at !== null) {
                throw new RuntimeException('Falló el negativo de código incorrecto AWS.');
            }
            $this->line('OK: código incorrecto rechazado y contabilizado como 1 intento.');

            $this->newLine();
            $this->components->info('6/7 OTP válido y Sanctum móvil');

            [$verifyStatus, $verifyBody] = $this->jsonRequest($kernel, 'POST', '/api/v1/auth/otp/verify', [
                'purpose' => OtpPurpose::Registration->value,
                'client_type' => AuthenticationClient::Mobile->value,
                'challenge_id' => $challengeId,
                'code' => $code,
                'device_name' => $deviceName,
                'device_id' => $deviceId,
            ]);

            if ($verifyStatus !== 200) {
                throw new RuntimeException(
                    'El OTP real no pudo validarse. HTTP '.$verifyStatus.': '.$this->safeJson($verifyBody),
                );
            }

            $token = data_get($verifyBody, 'data.access_token');
            if (
                is_string($token) === false
                || $token === ''
                || data_get($verifyBody, 'data.token_type') !== 'Bearer'
                || data_get($verifyBody, 'data.actor_context') !== AuthenticationContext::Patient->value
            ) {
                throw new RuntimeException('La verificación no emitió un Bearer Sanctum móvil de Paciente válido.');
            }

            $challenge->refresh();
            if ($challenge->consumed_at === null) {
                throw new RuntimeException('El challenge válido no quedó consumido.');
            }

            $headers = [
                'Authorization' => 'Bearer '.$token,
                'X-Device-Name' => $deviceName,
                'X-Device-ID' => $deviceId,
            ];

            [$meStatus, $meBody] = $this->jsonRequest($kernel, 'GET', '/api/v1/auth/me', [], $headers);
            if (
                $meStatus !== 200
                || data_get($meBody, 'data.client_type') !== AuthenticationClient::Mobile->value
                || data_get($meBody, 'data.actor_context') !== AuthenticationContext::Patient->value
            ) {
                throw new RuntimeException('El Bearer Sanctum no superó /api/v1/auth/me.');
            }
            $this->line('OK: Bearer Sanctum móvil autenticó /api/v1/auth/me.');

            [$reuseStatus] = $this->jsonRequest($kernel, 'POST', '/api/v1/auth/otp/verify', [
                'purpose' => OtpPurpose::Registration->value,
                'client_type' => AuthenticationClient::Mobile->value,
                'challenge_id' => $challengeId,
                'code' => $code,
                'device_name' => $deviceName,
                'device_id' => $deviceId,
            ]);
            if ($reuseStatus !== 422) {
                throw new RuntimeException('Un OTP consumido pudo reutilizarse.');
            }
            $this->line('OK: reutilización del OTP consumido rechazada.');

            $this->assertExpiredChallengeRejected($kernel, $deviceName, $deviceId);

            [$logoutStatus] = $this->jsonRequest($kernel, 'POST', '/api/v1/auth/logout', [], $headers);
            if ($logoutStatus !== 204) {
                throw new RuntimeException('Logout móvil no revocó el token como se esperaba.');
            }

            [$revokedStatus] = $this->jsonRequest($kernel, 'GET', '/api/v1/auth/me', [], $headers);
            if ($revokedStatus !== 401) {
                throw new RuntimeException('El Bearer continuó válido después de logout.');
            }
            $this->line('OK: logout revocó el Bearer Sanctum.');

            $this->newLine();
            $this->components->info('7/7 Resultado');
            $this->table(
                ['Control', 'Resultado'],
                [
                    ['IAM role / STS', 'PASS'],
                    ['Origination identity', $aws['origination_identity']],
                    ['Origination source', $aws['origination_source']],
                    ['Code configuration', $aws['code_configuration_id']],
                    ['Code configuration source', $aws['code_configuration_source']],
                    ['otp:aws-check', 'PASS'],
                    ['Política 6 dígitos / 5 min / 5 intentos', 'PASS'],
                    ['Teléfono inválido', 'PASS'],
                    ['SMS real', 'PASS'],
                    ['No exposición OTP/metadata', 'PASS'],
                    ['Device mismatch', 'PASS'],
                    ['Código incorrecto', 'PASS'],
                    ['OTP válido', 'PASS'],
                    ['OTP expirado', 'PASS'],
                    ['Reutilización OTP', 'PASS'],
                    ['Sanctum móvil', 'PASS'],
                    ['Logout / revocación', 'PASS'],
                ],
            );

            $this->info('B-109 AWS NOTIFY UAT GATE: PASS');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error('B-109 AWS NOTIFY UAT GATE: FAIL');
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($this->option('keep-data') === false) {
                $this->cleanupUatData();
            }
        }
    }

    private function guardEnvironment(): bool
    {
        $environment = (string) config('app.env');
        if (in_array($environment, ['testing', 'uat', 'local'], true) === false) {
            $this->error("Entorno no permitido [{$environment}]. Este comando es exclusivo para UAT.");

            return false;
        }

        $expectedDatabase = trim((string) $this->option('database'));
        $actualDatabase = (string) DB::connection()->getDatabaseName();

        if ($expectedDatabase === '' || $actualDatabase !== $expectedDatabase) {
            $this->error(
                "Base de datos insegura. Esperada [{$expectedDatabase}], actual [{$actualDatabase}].",
            );

            return false;
        }

        return true;
    }

    private function resolveTargetCountry(): ?string
    {
        $country = strtoupper(trim((string) ($this->option('target-country') ?: config('app.primary_country', 'MX'))));

        if (in_array($country, ['MX', 'PE', 'US'], true) === false) {
            $this->error("País objetivo [{$country}] no soportado por HIPOCRATIQ.");

            return null;
        }

        return $country;
    }

    private function resolvePhone(string $targetCountry): ?string
    {
        $dialingCode = match ($targetCountry) {
            'MX' => '+52',
            'PE' => '+51',
            'US' => '+1',
        };

        $raw = trim((string) ($this->option('phone') ?: $this->secret(
            "Número E.164 para SMS UAT ({$targetCountry}, prefijo {$dialingCode})",
        )));

        try {

            return PhoneNumber::fromE164($raw)->value;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return null;
        }
    }

    /**
     * @return array{origination_identity:string,code_configuration_id:string}
     */
    private function discoverAwsConfiguration(string $region, string $countryCode): array
    {
        if ($region === '') {
            throw new RuntimeException('AWS region no puede estar vacía.');
        }

        $sts = new StsClient(['version' => 'latest', 'region' => $region]);
        $identity = $sts->getCallerIdentity();
        $arn = (string) ($identity['Arn'] ?? '');

        if (str_contains($arn, 'assumed-role/HIPOCRATIQ-EC2-Notify-UAT/') === false) {
            throw new RuntimeException('El SDK no está usando el rol IAM UAT esperado.');
        }

        $this->line('IAM: '.$arn);

        $smsClient = new PinpointSMSVoiceV2Client([
            'version' => '2022-03-31',
            'region' => $region,
        ]);
        $notifyClient = new EndUserMessagingClient([
            'version' => '2026-09-21',
            'region' => $region,
        ]);

        $countryResult = $smsClient->listNotifyCountries([
            'Channels' => ['SMS'],
            'UseCases' => ['CODE_VERIFICATION'],
            'Tier' => 'BASIC',
            'MaxResults' => 100,
        ]);
        $country = collect($countryResult['NotifyCountries'] ?? [])
            ->firstWhere('IsoCountryCode', $countryCode);

        if (is_array($country) === false) {
            throw new RuntimeException(
                "AWS no reporta {$countryCode} disponible para SMS/CODE_VERIFICATION en el tier BASIC.",
            );
        }

        $customerOwnedIdentityRequired = (bool) ($country['CustomerOwnedIdentityRequired'] ?? false);

        $this->table(
            ['País', 'Canales', 'Use cases', 'Tiers', 'Identidad propia obligatoria'],
            [[
                (string) ($country['CountryName'] ?? $countryCode),
                implode(',', $country['SupportedChannels'] ?? []),
                implode(',', $country['SupportedUseCases'] ?? []),
                implode(',', $country['SupportedTiers'] ?? []),
                $customerOwnedIdentityRequired ? 'Sí' : 'No',
            ]],
        );

        [$codeConfiguration, $codeConfigurationSource] = $this->resolveCodeConfiguration(
            $notifyClient,
            $countryCode,
        );
        [$originationIdentity, $originationSource] = $this->resolveOriginationIdentity(
            $smsClient,
            $countryCode,
            $customerOwnedIdentityRequired,
        );

        return [
            'origination_identity' => $originationIdentity,
            'origination_source' => $originationSource,
            'code_configuration_id' => (string) $codeConfiguration['notifyCodeConfigurationId'],
            'code_configuration_source' => $codeConfigurationSource,
        ];
    }

    private function resolveOriginationIdentity(
        PinpointSMSVoiceV2Client $smsClient,
        string $countryCode,
        bool $customerOwnedIdentityRequired,
    ): array {
        $candidates = [];

        $pools = array_values(array_filter(
            $smsClient->describePools([
                'Owner' => 'SELF',
                'MaxResults' => 100,
            ])['Pools'] ?? [],
            static function (array $pool): bool {

                return ($pool['Status'] ?? null) === 'ACTIVE'
                    && ($pool['MessageType'] ?? null) === 'TRANSACTIONAL';
            },
        ));

        foreach ($pools as $pool) {
            $poolId = (string) ($pool['PoolId'] ?? '');
            if ($poolId === '') {

                continue;
            }

            $originators = $smsClient->listPoolOriginationIdentities([
                'PoolId' => $poolId,
                'Filters' => [
                    [
                        'Name' => 'iso-country-code',
                        'Values' => [$countryCode],
                    ],
                    [
                        'Name' => 'number-capability',
                        'Values' => ['SMS'],
                    ],
                ],
                'MaxResults' => 100,
            ])['OriginationIdentities'] ?? [];

            if ($originators === []) {

                continue;
            }

            $candidates[] = [
                'priority' => 10,
                'source' => 'pool',
                'identity' => $poolId,
                'aliases' => array_values(array_filter([
                    $poolId,
                    (string) ($pool['PoolArn'] ?? ''),
                ])),
            ];
        }

        $senderIds = $smsClient->describeSenderIds([
            'Owner' => 'SELF',
            'MaxResults' => 100,
        ])['SenderIds'] ?? [];

        foreach ($senderIds as $sender) {
            if (
                ($sender['IsoCountryCode'] ?? null) !== $countryCode
                || ($sender['Registered'] ?? false) !== true
                || in_array('TRANSACTIONAL', $sender['MessageTypes'] ?? [], true) === false
            ) {

                continue;
            }

            $senderId = (string) ($sender['SenderId'] ?? '');
            $senderArn = (string) ($sender['SenderIdArn'] ?? '');
            if ($senderId === '' && $senderArn === '') {

                continue;
            }

            $candidates[] = [
                'priority' => 20,
                'source' => 'sender_id',
                'identity' => $senderArn !== '' ? $senderArn : $senderId,
                'aliases' => array_values(array_filter([$senderId, $senderArn])),
            ];
        }

        $phoneNumbers = $smsClient->describePhoneNumbers([
            'Owner' => 'SELF',
            'MaxResults' => 100,
        ])['PhoneNumbers'] ?? [];

        foreach ($phoneNumbers as $phone) {
            if (
                ($phone['Status'] ?? null) !== 'ACTIVE'
                || ($phone['MessageType'] ?? null) !== 'TRANSACTIONAL'
                || in_array('SMS', $phone['NumberCapabilities'] ?? [], true) === false
            ) {

                continue;
            }

            $isLocal = ($phone['IsoCountryCode'] ?? null) === $countryCode;
            $isInternational = ($phone['InternationalSendingEnabled'] ?? false) === true;

            if ($isLocal === false && $isInternational === false) {

                continue;
            }

            $phoneId = (string) ($phone['PhoneNumberId'] ?? '');
            $phoneArn = (string) ($phone['PhoneNumberArn'] ?? '');
            $phoneNumber = (string) ($phone['PhoneNumber'] ?? '');

            if ($phoneId === '' && $phoneArn === '' && $phoneNumber === '') {

                continue;
            }

            $candidates[] = [
                'priority' => $isLocal ? 30 : 40,
                'source' => $isLocal ? 'phone_local' : 'phone_international',
                'identity' => $phoneArn !== '' ? $phoneArn : ($phoneId !== '' ? $phoneId : $phoneNumber),
                'aliases' => array_values(array_filter([$phoneId, $phoneArn, $phoneNumber])),
            ];
        }

        usort(
            $candidates,
            static function (array $left, array $right): int {
                $priorityComparison = $left['priority'] <=> $right['priority'];
                if ($priorityComparison !== 0) {

                    return $priorityComparison;
                }

                return strcmp((string) $left['identity'], (string) $right['identity']);
            },
        );

        $requested = trim((string) ($this->option('origination-identity')
            ?: config('authentication.otp.aws_notify.origination_identity')));

        if ($requested !== '') {
            foreach ($candidates as $candidate) {
                if (in_array($requested, $candidate['aliases'], true)) {
                    $this->line('Origination identity configurada validada: '.$candidate['source']);

                    return [(string) $candidate['identity'], (string) $candidate['source']];
                }
            }

            throw new RuntimeException(
                'AWS_NOTIFY_ORIGINATION_IDENTITY está configurada, pero no corresponde a una identidad '
                ."SMS activa/transaccional utilizable para {$countryCode}.",
            );
        }

        if ($candidates !== []) {
            $selected = $candidates[0];
            $this->line('Origination identity resuelta automáticamente: '.$selected['source']);

            return [(string) $selected['identity'], (string) $selected['source']];
        }

        $managedIdentityNote = $customerOwnedIdentityRequired
            ? 'AWS exige una identidad propia para este país.'
            : 'Notify puede usar identidades administradas por AWS, pero SendNotifyCodeVerification exige '
                .'una originationIdentity propiedad de la cuenta.';

        throw new RuntimeException(
            "No existe una originationIdentity SMS activa/transaccional para {$countryCode}. "
            .$managedIdentityNote
            .' Registra/provisiona un Sender ID, número o pool antes de repetir el gate.',
        );
    }

    private function resolveCodeConfiguration(
        EndUserMessagingClient $notifyClient,
        string $countryCode,
    ): array {
        $result = $notifyClient->listNotifyCodeConfigurations(['maxResults' => 100]);
        $all = $result['notifyCodeConfigurations'] ?? [];

        $valid = array_values(array_filter(
            $all,
            static function (array $item): bool {
                $policy = $item['codeConfigurationParameters'] ?? [];

                return ($policy['codeType'] ?? null) === 'NUMERIC'
                    && (int) ($policy['codeLength'] ?? 0) === 6
                    && (int) ($policy['validityPeriodMinutes'] ?? 0) === 5
                    && (int) ($policy['maxAttempts'] ?? 0) === 5;
            },
        ));

        $requested = trim((string) ($this->option('code-configuration')
            ?: config('authentication.otp.aws_notify.notify_code_configuration')));

        if ($requested !== '') {
            foreach ($valid as $item) {
                $aliases = [
                    (string) ($item['notifyCodeConfigurationId'] ?? ''),
                    (string) ($item['notifyCodeConfigurationArn'] ?? ''),
                ];

                if (in_array($requested, $aliases, true)) {
                    $this->line('Notify Code Configuration configurada validada.');

                    return [$item, 'configured'];
                }
            }

            throw new RuntimeException(
                'AWS_NOTIFY_CODE_CONFIGURATION está configurada, pero no existe o no cumple '
                .'NUMERIC/6 dígitos/5 minutos/5 intentos.',
            );
        }

        if ($valid !== []) {
            $preferredName = "HIPOCRATIQ-{$countryCode}-OTP";

            usort(
                $valid,
                static function (array $left, array $right) use ($preferredName): int {
                    $leftPreferred = ($left['notifyCodeConfigurationName'] ?? null) === $preferredName ? 0 : 1;
                    $rightPreferred = ($right['notifyCodeConfigurationName'] ?? null) === $preferredName ? 0 : 1;
                    $preferredComparison = $leftPreferred <=> $rightPreferred;

                    if ($preferredComparison !== 0) {

                        return $preferredComparison;
                    }

                    return strcmp(
                        (string) ($left['notifyCodeConfigurationId'] ?? ''),
                        (string) ($right['notifyCodeConfigurationId'] ?? ''),
                    );
                },
            );

            $this->line('Notify Code Configuration resuelta automáticamente.');

            return [$valid[0], 'existing'];
        }

        $created = $notifyClient->createNotifyCodeConfiguration([
            'notifyCodeConfigurationName' => "HIPOCRATIQ-{$countryCode}-OTP",
            'codeConfigurationParameters' => [
                'codeType' => 'NUMERIC',
                'codeLength' => 6,
                'validityPeriodMinutes' => 5,
                'maxAttempts' => 5,
            ],
            'deletionProtectionEnabled' => false,
            'tags' => [
                ['key' => 'Project', 'value' => 'HIPOCRATIQ'],
                ['key' => 'Environment', 'value' => 'UAT'],
                ['key' => 'Country', 'value' => $countryCode],
            ],
        ]);

        $configuration = $created['notifyCodeConfiguration'] ?? null;

        if (is_array($configuration) === false) {
            throw new RuntimeException('AWS no devolvió la Notify Code Configuration recién creada.');
        }

        $this->line('Notify Code Configuration creada automáticamente para UAT.');

        return [$configuration, 'created'];
    }

    private function activateRuntimeConfiguration(
        string $region,
        string $originationIdentity,
        string $codeConfiguration,
    ): void {
        config([
            'authentication.otp.phone_provider' => 'aws_notify',
            'authentication.otp.aws_notify.region' => $region,
            'authentication.otp.aws_notify.origination_identity' => $originationIdentity,
            'authentication.otp.aws_notify.notify_code_configuration' => $codeConfiguration,
        ]);

        app()->forgetInstance(PhoneOtpProvider::class);
    }

    private function createUatClinic(string $phone): Clinic
    {
        $phoneNumber = PhoneNumber::fromE164($phone);
        $suffix = now()->format('YmdHis').'-'.Str::lower(Str::random(6));

        $clinic = Clinic::query()->create([
            'name' => 'HIPOCRATIQ B-109 UAT',
            'slug' => 'b109-uat-'.$suffix,
            'tax_id' => 'B109-'.$suffix,
            'country_code' => $phoneNumber->countryCode,
            'contact_phone' => $phone,
            'address' => 'Entorno aislado B-109 UAT',
            'status' => 'active',
        ]);

        $this->uatClinicId = (int) $clinic->id;

        return $clinic;
    }

    private function differentCode(string $code): string
    {
        $first = ((int) $code[0] + 1) % 10;

        return (string) $first.substr($code, 1);
    }

    private function assertExpiredChallengeRejected(
        HttpKernel $kernel,
        string $deviceName,
        string $deviceId,
    ): void {
        $user = User::query()->findOrFail($this->uatUserId);
        $id = (string) Str::uuid();
        $sequence = (int) $user->otpChallenges()->max('delivery_sequence') + 1;

        $user->otpChallenges()->create([
            'id' => $id,
            'flow_id' => $id,
            'purpose' => OtpPurpose::Login,
            'client_type' => AuthenticationClient::Mobile,
            'actor_context' => AuthenticationContext::Patient,
            'delivery_channel' => OtpDeliveryChannel::Phone,
            'verification_provider' => 'aws_notify',
            'device_name' => $deviceName,
            'device_id_hash' => hash('sha256', $deviceId),
            'code_hash' => null,
            'attempts' => 0,
            'max_attempts' => 5,
            'resend_count' => 0,
            'delivery_status' => OtpDeliveryStatus::Sent,
            'delivery_sequence' => $sequence,
            'sent_at' => now()->subMinutes(6),
            'expires_at' => now()->subMinute(),
        ]);

        [$status] = $this->jsonRequest($kernel, 'POST', '/api/v1/auth/otp/verify', [
            'purpose' => OtpPurpose::Login->value,
            'client_type' => AuthenticationClient::Mobile->value,
            'challenge_id' => $id,
            'code' => '000000',
            'device_name' => $deviceName,
            'device_id' => $deviceId,
        ]);

        $expired = OtpChallenge::query()->findOrFail($id);
        if ($status !== 422 || $expired->invalidated_at === null) {
            throw new RuntimeException('El challenge expirado no fue rechazado/inutilizado correctamente.');
        }

        $this->line('OK: challenge expirado rechazado sin consultar AWS.');
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function jsonRequest(
        HttpKernel $kernel,
        string $method,
        string $uri,
        array $payload = [],
        array $headers = [],
    ): array {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
        }

        $content = $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, strtoupper($method), [], [], [], $server, $content);

        Auth::forgetGuards();
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        Auth::forgetGuards();

        $raw = (string) $response->getContent();
        $body = $raw === '' ? [] : json_decode($raw, true);

        return [$response->getStatusCode(), is_array($body) ? $body : []];
    }

    private function safeJson(array $body): string
    {
        foreach (['access_token', 'token', 'password', 'code'] as $sensitive) {
            if (array_key_exists($sensitive, $body)) {
                $body[$sensitive] = '[redacted]';
            }
            if (isset($body['data']) && is_array($body['data']) && array_key_exists($sensitive, $body['data'])) {
                $body['data'][$sensitive] = '[redacted]';
            }
        }

        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function cleanupUatData(): void
    {
        try {
            if ($this->uatUserId !== null) {
                $user = User::query()->find($this->uatUserId);
                if ($user !== null) {
                    $user->tokens()->delete();
                    $user->authenticationSessions()->delete();
                    $user->otpChallenges()->delete();
                    $user->patientProfile()?->delete();
                    $user->delete();
                }
            }

            if ($this->uatClinicId !== null) {
                Clinic::query()->whereKey($this->uatClinicId)->delete();
            }

            if ($this->uatUserId !== null || $this->uatClinicId !== null) {
                $this->line('Datos temporales B-109 eliminados.');
            }
        } catch (Throwable $exception) {
            $this->warn('No se pudo completar el cleanup UAT: '.$exception->getMessage());
        }
    }
}
