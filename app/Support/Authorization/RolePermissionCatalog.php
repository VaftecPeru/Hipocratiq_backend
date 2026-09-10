<?php

namespace App\Support\Authorization;

use App\Enums\AdminAssignableRole;
use App\Enums\RoleScope;

final class RolePermissionCatalog
{
    /**
     * @return array<int, array{key: string, label: string, group: string}>
     */
    public static function permissions(): array
    {
        return [
            ['key' => 'platform.dashboard.view', 'label' => 'Ver panel global', 'group' => 'Plataforma'],
            ['key' => 'platform.clinics.manage', 'label' => 'Administrar clínicas', 'group' => 'Plataforma'],
            ['key' => 'platform.users.manage', 'label' => 'Administrar usuarios', 'group' => 'Plataforma'],
            ['key' => 'platform.access.manage', 'label' => 'Administrar roles y accesos', 'group' => 'Seguridad'],
            ['key' => 'platform.memberships.manage', 'label' => 'Administrar membresías', 'group' => 'Operación'],
            ['key' => 'platform.billing.manage', 'label' => 'Administrar facturación', 'group' => 'Operación'],
            ['key' => 'platform.reports.view', 'label' => 'Consultar reportes globales', 'group' => 'Supervisión'],
            ['key' => 'platform.monitoring.view', 'label' => 'Monitorear la plataforma', 'group' => 'Supervisión'],
            ['key' => 'platform.audit.view', 'label' => 'Consultar auditoría global', 'group' => 'Seguridad'],
            ['key' => 'platform.emergency.manage', 'label' => 'Administrar emergencias', 'group' => 'Supervisión'],
            ['key' => 'platform.support.manage', 'label' => 'Administrar soporte', 'group' => 'Operación'],
            ['key' => 'platform.settings.manage', 'label' => 'Administrar configuración global', 'group' => 'Plataforma'],
            ['key' => 'platform.countries.manage', 'label' => 'Administrar países habilitados', 'group' => 'Plataforma'],
            ['key' => 'clinic.dashboard.view', 'label' => 'Ver panel de la clínica', 'group' => 'Clínica'],
            ['key' => 'clinic.members.manage', 'label' => 'Administrar integrantes de la clínica', 'group' => 'Clínica'],
            ['key' => 'clinic.patients.manage', 'label' => 'Administrar pacientes de la clínica', 'group' => 'Clínica'],
            ['key' => 'clinic.appointments.manage', 'label' => 'Administrar citas de la clínica', 'group' => 'Clínica'],
            ['key' => 'clinic.settings.manage', 'label' => 'Administrar configuración de la clínica', 'group' => 'Clínica'],
            ['key' => 'reception.dashboard.view', 'label' => 'Ver panel de recepción', 'group' => 'Recepción'],
            ['key' => 'reception.patients.manage', 'label' => 'Gestionar pacientes', 'group' => 'Recepción'],
            ['key' => 'reception.agenda.manage', 'label' => 'Gestionar agenda', 'group' => 'Recepción'],
            ['key' => 'doctor.dashboard.view', 'label' => 'Ver panel médico', 'group' => 'Médico'],
            ['key' => 'patient.dashboard.view', 'label' => 'Ver portal del paciente', 'group' => 'Paciente'],
        ];
    }

    /**
     * @return array<int, array{key: string, name: string, scope: string, description: string, permissions: array<int, string>}>
     */
    public static function roles(): array
    {
        return [
            [
                'key' => AdminAssignableRole::SuperAdmin->value,
                'name' => 'Superadministrador',
                'scope' => RoleScope::Global->value,
                'description' => 'Supervisa y administra todos los países, tenants y módulos de la plataforma.',
                'permissions' => self::permissionKeysWithPrefix('platform.'),
            ],
            [
                'key' => AdminAssignableRole::ClinicAdmin->value,
                'name' => 'Administrador de clínica',
                'scope' => RoleScope::Organization->value,
                'description' => 'Administra la operación de una clínica específica sin acceso global.',
                'permissions' => self::permissionKeysWithPrefix('clinic.'),
            ],
            [
                'key' => AdminAssignableRole::Receptionist->value,
                'name' => 'Recepcionista',
                'scope' => RoleScope::Organization->value,
                'description' => 'Gestiona la recepción, pacientes y agenda de la clínica asignada.',
                'permissions' => [
                    ...self::permissionKeysWithPrefix('reception.'),
                    'clinic.patients.manage',
                    'clinic.appointments.manage',
                ],
            ],
            [
                'key' => AdminAssignableRole::Doctor->value,
                'name' => 'Médico',
                'scope' => RoleScope::Organization->value,
                'description' => 'Accede al espacio clínico de la institución donde tiene membresía activa.',
                'permissions' => self::permissionKeysWithPrefix('doctor.'),
            ],
            [
                'key' => AdminAssignableRole::Patient->value,
                'name' => 'Paciente',
                'scope' => RoleScope::Organization->value,
                'description' => 'Accede únicamente a su información y servicios dentro de la clínica asignada.',
                'permissions' => self::permissionKeysWithPrefix('patient.'),
            ],
        ];
    }

    /** @return array<int, string> */
    public static function abilities(): array
    {
        return array_values(array_filter(
            self::permissionKeysWithPrefix('clinic.'),
            static fn (string $permission): bool => str_ends_with($permission, '.manage'),
        ));
    }

    public static function definesAbility(string $ability): bool
    {
        return in_array($ability, self::abilities(), true);
    }

    public static function allows(string $roleKey, string $ability): bool
    {
        if (! self::definesAbility($ability)) {
            return false;
        }

        $role = collect(self::roles())->firstWhere('key', $roleKey);

        return is_array($role) && in_array($ability, $role['permissions'], true);
    }

    /** @return array<int, string> */
    private static function permissionKeysWithPrefix(string $prefix): array
    {
        return array_values(array_map(
            static fn (array $permission): string => $permission['key'],
            array_filter(
                self::permissions(),
                static fn (array $permission): bool => str_starts_with($permission['key'], $prefix),
            ),
        ));
    }
}
