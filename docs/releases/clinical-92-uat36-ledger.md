# HIPOCRATIQ — #92 Registro técnico de 36 posiciones UAT (NO-GO)

Este registro **NO define ni sustituye los escenarios del cliente**. La matriz oficial de 36 casos está en la [hoja maestra](https://docs.google.com/spreadsheets/d/1SwuLEHgvPyDVfQC3xic-Fg4d1NzUY4vrl0WCEfEBhls/edit).
Este repositorio conserva otra **submatriz web de 12 escenarios** en clinical-92-web-acceptance-matrix.md; **12 no son 36**, ni la ejecución de pruebas Laravel equivale a UAT firmada.

El archivo clinical-92-uat36-ledger.json (esquema v2) vincula cada posición 1..36 con el identificador oficial **UAT-001..UAT-036**, comprobado con la pestaña privada `UAT CLINICA PRODUCCION` de la hoja maestra el 05/11/2026. No reproduce descripciones, responsables, resultados ni evidencia privada. **Los 36 registros siguen NOT_EXECUTED en términos de aceptación UAT real**, incluso cuando la hoja maestra marca cobertura parcial de CI. El validador rechaza posiciones duplicadas, IDs desplazados o el esquema anterior sin identificadores.

## Para ejecutar, fuera de producción y con datos 100 % sintéticos

1. El responsable clínico/QA debe validar en privado la correspondencia de cada posición ordinal con los 36 escenarios reales y sus precondiciones.
2. Congelar SHA completos de Frontend y Backend, entorno de prueba aislado, ejecutor, fecha UTC y resultado por caso; no utilizar cuentas clínicas reales.
3. Si se actualiza el registro, conservar **solo referencias opacas** a evidencias privadas; no incluir PHI, cuentas, contraseñas, OTP, URLs internas ni capturas. El contenido real queda bajo custodia aprobada.
4. Para PASS, FAIL o BLOCKED, el esquema exige dos SHA de 40 caracteres, executed_at_utc, executor_ref y evidence_ref. Los identificadores no prueban que la evidencia exista o sea auténtica.
5. Los defectos P0/P1 requieren incidencia y reejecución. Cualquier resultado que no sea PASS impide la aceptación; **incluso 36 PASS autorreportados NO autorizan** release ni sustituyen la revisión independiente.
6. Requiere acta y firma verificable del responsable clínico, Seguridad y autorización del cambio, sin que ninguna de ellas pueda generarse por CI.

El script scripts/clinical_92_ledger.py verifica **únicamente estructura** de 36 posiciones, referencias de tipo seguro y consistencia de SHA. Emite siempre release_decision=NO_GO y clinical_signoff_verified=false. Un resultado CI SUCCESS significa **formato válido**, nunca aceptación clínica.

**Sin autorización explícita:** no merge a main, no despliegue a AWS, no restauración PostgreSQL ni operación sobre EBS. Las decisiones históricas de #113 (restauración diferida) y #112 (cifrado EBS diferido) se mantienen.
