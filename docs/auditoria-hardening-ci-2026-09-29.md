# Auditoría de hardening y desbloqueo CI — HIPOCRATIQ

Fecha: 29/09/2026  
Rama de trabajo: `audit/fase3-fase6-hardening-20260929`  
Base: `develop`

## 1. Hallazgo raíz del bloqueo

Los últimos jobs de Backend, Frontend y Mobile fallan antes de ejecutar el primer step:
- `runner_name` vacío;
- `steps=[]`;
- duración aproximada de 3–5 segundos;
- reintentos reproducen el mismo patrón.

Se verificó además que los YAML eran idénticos entre ejecuciones verdes y las primeras ejecuciones rojas. Por tanto, el fallo no se origina en un cambio de sintaxis del workflow.

GitHub Actions en repositorios privados consume cuota de minutos del propietario. Cuando la cuota se agota y no existe un método de pago/budget disponible, GitHub bloquea nuevas ejecuciones. El patrón observado es compatible con este escenario y no existe evidencia de una incidencia pública general de GitHub Actions en el periodo auditado.

## 2. Correcciones aplicadas en rama aislada

### Backend
- Validación estricta de `BACKUP_SCHEDULE`.
- Un cron inválido ya no se registra silenciosamente.
- La IA epidemiológica del médico ahora calcula la cohorte sobre pacientes consentidos que realmente tuvieron actividad clínica durante la ventana analizada.
- Se auditan las llamadas externas de IA epidemiológica del médico, tanto exitosas como fallidas.
- Se añadió prueba negativa para bloquear creación/inicio de teleconsulta cuando el consentimiento de telemedicina no está vigente.

### Frontend Web
- Se eliminó una entrada duplicada de `develop`.
- Se añadió `workflow_dispatch`.
- Se añadió `concurrency` con cancelación de ejecuciones obsoletas.
- El E2E completo queda reservado para PR, `main` o ejecución manual; `develop` conserva CI rápido.

### Mobile
- Se añadió `workflow_dispatch`.
- Se añadió `concurrency` con cancelación de ejecuciones obsoletas.
- Android continúa validándose en `develop`.
- iOS/macOS queda reservado para PR, `main` o ejecución manual, evitando consumir runner macOS en cada commit.

## 3. Justificación DevSecOps

La reducción de consumo no elimina gates. Se separa:
- feedback rápido en `develop`;
- validación exhaustiva en Pull Request / release candidate;
- build iOS bajo gate de release.

Esta estrategia reduce gasto de minutos y disminuye la probabilidad de bloquear todo el pipeline por consumo innecesario.

## 4. Estado de ramas

- `develop` no fue modificado durante esta auditoría.
- `main` no fue modificado.
- Todos los cambios están aislados en `audit/fase3-fase6-hardening-20260929`.

## 5. Acción externa necesaria para restablecer GitHub-hosted runners

Verificar en GitHub:
1. Settings → Billing and licensing / Billing.
2. Revisar Actions usage/minutes.
3. Si la cuota está agotada, configurar un budget/método de pago o esperar al reinicio del ciclo.
4. Alternativa: registrar runner self-hosted controlado por VAFTEC para CI Linux.

Hasta restablecer capacidad de runner no es posible obtener evidencia verde nueva desde GitHub Actions, aunque el código del pipeline haya sido endurecido.

## 6. Gate antes de integrar a develop

Antes del merge de esta rama a `develop`:
- revisar diff;
- restablecer capacidad de Actions;
- ejecutar CI Backend;
- ejecutar CI Web;
- ejecutar Android CI;
- ejecutar iOS CI como gate de release;
- confirmar que no existen P0 nuevos.

No se autoriza merge a `main` en esta etapa.
