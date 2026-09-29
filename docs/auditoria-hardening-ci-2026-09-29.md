# Auditoría de hardening y desbloqueo CI — HIPOCRATIQ

Fecha: 29/09/2026  
Rama: `audit/fase3-fase6-hardening-20260929`  
Base: `develop`

## 1. Diagnóstico del bloqueo

Los jobs recientes de Backend, Frontend y Mobile terminaban antes de ejecutar el primer step:

- `runner_name` vacío;
- `steps=[]`;
- ejecución de pocos segundos;
- el reintento reproducía el mismo patrón.

Se comparó la última ejecución verde con las primeras rojas y el YAML no había cambiado en el punto de transición. Esto descarta una regresión de sintaxis como causa primaria.

La evidencia es compatible con un bloqueo de capacidad/cuota de GitHub-hosted runners en repositorios privados. La API disponible no expone el mensaje de facturación de la cuenta, por lo que se documenta como causa operacional probable, no como una conclusión basada únicamente en el código.

## 2. Solución adoptada

VAFTEC optó por usar su VPS Linux como runner self-hosted para los gates Linux.

Los jobs Backend, Web y Android ahora usan:

`[self-hosted, linux, x64, hipocratiq-vps]`

El job iOS permanece manual sobre macOS y no bloquea el release Web/Backend/Android de esta etapa.

Se añadió `docs/SELF_HOSTED_RUNNER_VPS.md` con la configuración segura del runner.

## 3. Seguridad del runner VPS

Para evitar ejecutar código no validado dentro del mismo host de producción:

- se retiró el trigger automático por Pull Request de los workflows self-hosted;
- se permite `push` a `develop`/`main` y ejecución manual;
- se añadió `concurrency` para cancelar ejecuciones obsoletas;
- el E2E Web elimina sus procesos en segundo plano al finalizar;
- iOS solo se habilita manualmente;
- se exige usuario Linux dedicado y secretos fuera del repositorio.

## 4. Backend — mantenimiento aplicado

- Validación estricta de `BACKUP_SCHEDULE` antes de registrar el cron.
- Un cron inválido deja evidencia en log y no crea una programación ambigua.
- Telemedicina exige consentimiento vigente al crear y al iniciar una sesión.
- Prueba negativa de revocación de consentimiento de telemedicina.
- IA epidemiológica del médico calcula la cohorte externa únicamente con pacientes:
  - conectados al médico;
  - con consentimiento epidemiológico vigente;
  - con actividad clínica real dentro de la ventana de 90 días.
- Se auditan llamadas externas de IA epidemiológica del médico, tanto exitosas como fallidas.
- Se añadió prueba de regresión para cohorte activa + auditoría.
- CI Backend se enruta al runner VPS.

## 5. Web — mantenimiento aplicado

- Se eliminó la rama `develop` duplicada del YAML.
- `workflow_dispatch` disponible.
- `concurrency` habilitado.
- Web CI usa runner VPS.
- E2E usa runner VPS, pero solo en ejecución manual o `main`.
- Cleanup explícito de procesos fake-backend/Next al terminar E2E.

## 6. Mobile — mantenimiento aplicado

- Android usa runner VPS.
- Se prepara Java 17 + Android SDK + Flutter dentro del workflow.
- Se mantienen gates: format, analyze, tests, APK y AAB de validación.
- iOS queda como ejecución manual opcional; requiere macOS.
- La firma Android de producción sigue separada de los binarios técnicos de validación.

## 7. Estado de release

`main` continúa sin modificaciones.

La rama de auditoría todavía no debe promoverse a `main` hasta obtener evidencia real en el VPS:

1. Backend CI verde.
2. Web CI verde.
3. Web E2E verde.
4. Android CI verde.
5. UAT clínico + consentimiento verdes.
6. Migraciones PostgreSQL válidas.
7. Backup creado y verificado; restore ensayado sobre entorno controlado.
8. Auditoría final sin P0.

## 8. Alcance de iOS

El release Web/Backend/Android no debe quedar bloqueado por iOS si iOS no forma parte del paquete de publicación actual. Un `.ipa` exige macOS, certificado y provisioning profile de Apple; será un gate de publicación iOS independiente.
