# Cotización preliminar de infraestructura AWS UAT HIPOCRATIQ — 2026-11-07

**Estado: propuesta sin aprobación de gasto ni autorización de aprovisionamiento.** Región prevista `us-east-2` (Ohio); arquitectura UAT en VPC distinta de producción, con cuentas de prueba sintéticas y sin acceso a PHI productiva.

## Supuestos de cálculo y configuraciones

- Linux On-Demand, 1 EC2, 730 horas/mes, sin descuentos, sin Free Tier, sin impuestos.
- EC2 `t3.medium` (2 vCPU, 4 GiB) **USD 0.0416/h**; EC2 `t3.large` (2 vCPU, 8 GiB) **USD 0.0832/h**, tarifas us-east-2 publicadas y verificadas en catálogos actualizados (2026-11-06). Reconfirmar en AWS Pricing Calculator al presentar al cliente.
- EBS `gp3` SSD cifrado: **referencia USD 0.08/GB-mes** (50 o 80 GB), sin IOPS ni throughput adicionales; confirmar tarifa regional AWS antes de autorizar.
- Una IPv4 pública: **USD 0.005/h** según AWS VPC Pricing; para 730 horas, USD 3.65.
- PostgreSQL 17, Redis 8, Backend Laravel y Frontend Next.js en contenedores Docker en **la misma instancia** UAT. No se presupone RDS, NAT Gateway, ALB, Multi-AZ ni disponibilidad alta. En producción estos atajos de costo no son necesariamente adecuados.
- Datos exclusivamente ficticios y mínimos; no usar snapshots ni dumps de BD productiva sin un proyecto de desidentificación y autorización separado.

| Rubro | UAT básico (t3.medium) | UAT recomendado (t3.large) |
| --- | ---: | ---: |
| EC2 (730h) | USD 30.37 | USD 60.74 |
| EBS gp3 (50 / 80 GB) | USD 4.00 | USD 6.40 |
| IPv4 pública (730h) | USD 3.65 | USD 3.65 |
| **Subtotal conocido mensual** | **USD 38.02** | **USD 70.79** |

**No es costo total ni cotización vinculante.** Faltan dimensionar: snapshots EBS, tráfico saliente/transferencia, logs CloudWatch y alertas, DNS/Route53 si corresponde, backups fuera de instancia (S3 + requests), KMS/servicios secretos según configuración, prueba SMS MX +52 (AWS u otro proveedor), espacio adicional, posibles créditos T3 Unlimited, NAT/ALB/VPC endpoints si se exige arquitectura privada estricta, e impuestos. EBS y dirección pública se cobran también cuando el EC2 está parado si siguen provisionados. Para instancias de uso parcial calcular horas reales.

## Alternativas arquitectónicas y aislamiento

### A: Económica — UAT funcional
EC2 t3.medium + 50 GB gp3, VPC separada, SG acceso HTTPS 443 únicamente, TLS y conexión administración sin SSH abierto, PostgreSQL y Redis no expuestos a Internet, IAM mínimo, registros sanitizados, Docker en EC2. **4 GiB puede ser insuficiente** al ejecutar simultáneamente CI/E2E, Laravel, Next.js, PostgreSQL y Redis; validar memoria en prueba de carga y considerar swap solo como respaldo temporal, no sustituto de RAM.

### B: Recomendada — UAT clínica
EC2 t3.large + 80 GB gp3, misma segregación; 8 GiB ofrecen mayor margen de memoria para full-stack y las 36 UAT. No proporciona alta disponibilidad ni aislamiento de base de datos al nivel de un servicio dedicado. Evaluar ubicación de servicios según requisitos de seguridad y arquitectura aprobada.

### C: UAT empresarial/aislamiento estricto
App EC2 privada + RDS PostgreSQL/Redis dedicado o contenedores aislados en subredes privadas; NAT Gateway, ALB y endpoints privados según rutas; backups y observabilidad reforzada. **Sin cifra** hasta definir topología, tráfico, retención y servicios concretos. No confundir A/B con C.

## Condiciones técnicas previas a cualquier AWS create

1. Cliente aprueba por escrito modalidad (A/B/C), presupuesto mensual máximo, duración, pagador y propietario de recursos.
2. DevOps verifica costos finales en [AWS Pricing Calculator](https://calculator.aws/#/), región Ohio y opciones exactas; exporta PDF con fecha, supuestos y exclusiones; añadir estimación de todos los servicios no incluidos.
3. Seguridad aprueba VPC nueva, subredes, SG, cifrado EBS, registros, retención, IAM GitHub OIDC con Environment y reviewers; **no reutilizar** trust ni VPC productivas.
4. Responsable QA confirma dataset 100% ficticio, dominios UAT, OTP sandbox/autorizado, política de acceso y los 36 casos de la matriz.
5. Cliente autoriza explícitamente el **aprovisionamiento** y su costo tras revisar la cotización final; ninguna autorización de presupuesto equivale a merge `main` o despliegue producción.

## Fuentes de precios consultadas

- AWS EC2 On-Demand: https://aws.amazon.com/ec2/pricing/on-demand/
- Catálogo Ohio EC2 (actualizado 2026-11-06, terceros; verificar con calculadora): https://cloudprice.net/aws/ec2?filter=%5Et3%5C.&region=us-east-2
- AWS EBS gp3: https://aws.amazon.com/ebs/volume-types/
- AWS IPv4/VPC: https://aws.amazon.com/vpc/pricing/
- AWS Pricing Calculator: https://calculator.aws/#/
- Documentación para estimar EC2/EBS e IP: https://docs.aws.amazon.com/pricing-calculator/latest/userguide/ec2-estimates.html

**Gate #111:** OPEN hasta aprobación del cliente y verificación real posterior de recursos UAT. Esta nota solamente autoriza la discusión de costos.
