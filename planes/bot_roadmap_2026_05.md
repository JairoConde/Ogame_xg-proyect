# Bot roadmap — Próximos pasos (definido 2026-05-13)

> Basado en revisión del código existente y decisiones de producto tomadas con el usuario.
> Fechas: no estimadas aún. Orden: el que aparece aquí es el orden de implementación.

---

## 1. Cablear sistema de `heat` al comportamiento real del bot (P1)

### 1.1. Estado actual
El sistema de calor dirigido (`scripts/bot_lib/heat.php`) está implementado al 100%:
- Tabla `xgp_bot_pair_heat` con `(observer, target, heat, updated_at)`
- Helpers: `botHeatGet`, `botHeatUpsert`, `botHeatWorsen`, `botHeatImprove`, `botHeatDecayOneStepTowardNeutral`, `botHeatDecayTick`
- Rango: `[0.1, 10.0]`, 1.0 = neutro
- `botHeatDecayTick` se llama desde `simple_rule_bot.php:1799`

**Pero NADIE llama a `botHeatOnInboundHostile` ni `botHeatOnHelpReceived`.** El heat es una tabla que arranca a 1.0 y nunca la modifica ninguna acción real. Además, nadie consulta el heat para tomar decisiones.

### 1.2. Eventos que modifican el heat

| Evento | Quién lo ejecuta | Efecto en observer→target |
|--------|-----------------|---------------------------|
| Recibir ataque (somos víctima) | `attack.php` al detectar flota hostil | `botHeatWorsen` (+1 o +0.1 según rango) |
| Ser espiado (detectamos sonda enemiga en nuestro planeta) | `attack.php` al procesar espionajes recibidos | `botHeatWorsen` (leve, +0.1). **Nota**: solo cuando alguien nos espía a nosotros. Los bots solo espían para atacar, por lo que ser espiado es preludio de ataque. |
| Perder batalla (defensa destruida) | `attack.php` al procesar reporte de derrota | `botHeatWorsen` fuerte (+2) |
| Recibir ayuda (transporte de recursos) | `transport.php` / `ally_logistics.php` | `botHeatImprove` (−1 o −0.1 según rango) |
| Recibir mensaje amistoso / tregua | Cuando se procese el sistema de mensajes LLM | `botHeatImprove` (leve, −0.1) |
| Recibir amenaza / mensaje hostil | Cuando se procese el sistema de mensajes LLM | `botHeatWorsen` (leve, +0.1) |
| Aliarse (entramos en misma alianza) | `alliance_policy.php` / `alliance.php` | `botHeatImprove` directo a 0.5 (amistad base) |
| Atacar a alguien (nosotros atacamos) | `attack.php` al lanzar ataque | El **target** también genera heat hacia nosotros (registro bidireccional automático) |

**Importante**: el heat es **direccional** (observer→target). Si A ataca a B, sube el heat de B→A (B recuerda la agresión), pero no necesariamente sube el de A→B (A puede sentir que actuó con razón). Aunque en la práctica, cuando el bot A ataca, también debería considerar que el target B le tendrá mayor heat, y eso puede afectar decisiones futuras.

### 1.3. Reglas de decay (corrección)
- El decay **resetea el heat a 1.0 directamente** tras una semana sin interacción.
- Da igual que el heat sea 10, 0.1 o 5.0: si ha pasado una semana sin interactuar con ese jugador, el heat vuelve a 1.0 (neutro).
- **Frecuencia**: semanal. `BOT_HEAT_DECAY_INTERVAL_SEC = 604800` (7 días).
- Esto significa que las relaciones son intensas pero volátiles: duran mientras haya interacción, y se borran con una semana de inactividad.
- **Cambio necesario en `heat.php`**: `botHeatDecayOneStepTowardNeutral` debe convertirse en `botHeatDecayResetToNeutral` y en lugar de mover un escalón, asignar `$heat = 1.0` directamente.
- También `botHeatDecayTick` debe simplificarse: en vez de seleccionar solo filas con heat != 1, seleccionar todas las filas vencidas y resetearlas a 1.0.

### 1.4. Cómo el heat influye en el comportamiento

El heat debe afectar **decisiones tácticas** y **tono de comunicación**:

#### a) Heat influye en selección de targets de ataque
En `scripts/bot_lib/attack.php`, en `botAttackScanCandidates` o en el pipeline de selección de target:
- **Heat > 3.0** (enemistad): bonus de puntuación. El bot recuerda viejas enemistades y prioriza atacar a quien le ha hecho daño.
- **Heat < 0.5** (amistad): penalización. El bot evita atacar a "amigos" que le han ayudado.
- **Heat 0.3–0.7** (muy amistoso): bloqueo directo de ataque (no ataca a aliados cercanos).
- **Heat 1.0** (neutro): sin sesgo.
- **El heat también se pasa como contexto en el prompt LLM de `attack_decision_v1`** (ver sección 2), para que el LLM lo tenga en cuenta.

#### b) Heat influye en decisiones de alianza
En `scripts/bot_lib/alliance_policy.php`:
- Al evaluar un solicitante: si el bot (o sus miembros) tienen heat alto hacia él → más probable rechazar.
- Al decidir expulsión de un miembro: si el bot tiene heat alto hacia ese miembro → más probable kick.
- Al decidir a qué alianza postularse: preferir aquellas donde tengamos heat bajo con los miembros.

#### c) Heat influye en diplomacia
En `scripts/bot_lib/diplomacy_actions.php`:
- El bot necesita decidir si propone NAP, mantiene estatus quo, presiona o va a la guerra con otra alianza.
- Para medir la agresividad entre alianzas usamos una **matriz de conflicto inter-alianzas** en lugar de una simple media.

**Modelo de medición de agresividad inter-alianzas**:

Se calcula con dos métricas complementarias:

1. **Heat agregado total** (suma de todo el heat de miembros de A hacia miembros de B):
   \[
   H_{A→B} = \sum_{miembro \in A} \sum_{target \in B} \text{heat}(miembro, target)
   \]
   Esto captura el volumen total de conflicto.

2. **Participación** (% de pares A→B con heat > 1.5):
   \[
   P_{A→B} = \frac{\text{pares con heat} > 1.5}{\text{total pares posibles}}
   \]
   Si solo 1 de 10 miembros tiene problemas, la participación es baja.

3. **Índice de agresividad** (combinación ponderada):
   \[
   I_{A→B} = H_{A→B} \times (0.5 + 0.5 \times P_{A→B})
   \]
   - Un par de miembros peleados aporta heat, pero si la participación es baja el índice no se dispara.
   - Una alianza donde muchos miembros tienen conflictos moderados genera un índice alto.

**Umbrales para decisiones diplomáticas**:
- `I_{A→B} < 0.5`: relaciones amistosas → proponer NAP.
- `I_{A→B} 0.5–2.0`: neutro. Mantener estatus quo.
- `I_{A→B} 2.0–5.0`: tensión. Aumentar presión diplomática (exigencias, quejas).
- `I_{A→B} > 5.0`: hostil. Guerra probable.

**¿Cuándo se recalcula?**
- No en tiempo real. Se calcula bajo demanda cuando el bot considera una acción diplomática.
- Si no hay datos de heat entre dos alianzas (nadie ha interactuado nunca), el índice es 0 (neutro por defecto).
- El cálculo se hace consultando la tabla `bot_pair_heat` con un JOIN a las tablas de usuarios/alianzas.

**Nota sobre la direccionalidad**: el heat es direccional. Para diplomacia consideramos la **dirección más hostil**:
\[
I_{AB} = \max(I_{A→B}, I_{B→A})
\]
Porque si A odia a B pero B es neutral, igual hay conflicto latente.

#### d) Mecánica de guerras, rendición y tributo

Cuando dos alianzas están en guerra (estado declarado), los bots deben:
1. **Medir pérdidas** de cada bando periódicamente.
2. **Decidir rendirse** si se cumplen condiciones (tiempo, desgaste).
3. **Exigir o pagar tributo** como condición de paz.
4. **Gestionar contraofertas** con LLM.

**Variables configurables** (para poder ajustar durante desarrollo/pruebas):
| Variable | Default (producción) | Default (pruebas) | Descripción |
|----------|---------------------|-------------------|-------------|
| `BOT_WAR_MIN_DURATION_HOURS` | 4 | 1 | Horas mínimas antes de poder rendirse |
| `BOT_WAR_MAX_DURATION_HOURS` | 48 | 4 | Si se supera, empate automático |
| `BOT_WAR_SURRENDER_D_THRESHOLD` | 0.70 | 0.60 | Desgaste necesario para rendirse |
| `BOT_WAR_ATTRITION_EMPATE_D` | 0.40–0.60 | 0.35–0.65 | Rango de empate en desgaste |
| `BOT_WAR_TRIBUTE_BASE_RATIO` | 0.30 | 0.30 | Fracción de pérdidas enemigas como tributo base |
| `BOT_WAR_TRIBUTE_MIN_RATIO` | 0.10 | 0.05 | Fracción mínima de nuestros recursos |
| `BOT_WAR_TRIBUTE_MAX_RATIO` | 0.50 | 0.30 | Fracción máxima de nuestros recursos |
| `BOT_WAR_NAP_DURATION_HOURS` | 336 (14d) | 24 (1d) | Duración de la NAP tras firmar paz |
| `BOT_WAR_HEAT_REDUCTION_ON_PEACE` | 0.50 | 0.80 | Reducción de heat al firmar paz (% se resta) |
| `BOT_WAR_AUTO_EMPATE_HOURS` | 48 | 4 | Si la guerra dura más de X horas sin rendición, empate forzoso |

> En el código, `getenv('BOT_WAR_MIN_DURATION_HOURS') ?? 4`, con override por env var para cambiar sin tocar código.

**¿Qué se considera pérdida?**
Se usa una **métrica de desgaste** por alianza:
- Flota destruida (valor en recursos).
- Defensas destruidas.
- Recursos saqueados por el enemigo.
- Puntos de flota perdidos.

Almacenado como campo `war_metrics` JSON en la tabla de guerras:
```json
{"alianzaA": {"fleet_losses": 100000, "defense_losses": 50000, "loot_stolen": 20000}, "alianzaB": {...}}
```

**Índice de desgaste**:
\[
D_{propio} = \frac{\text{pérdidas totales propias}}{\text{pérdidas totales propias} + \text{pérdidas totales enemigas}}
\]
- `D > BOT_WAR_ATTRITION_EMPATE_D[1]`: estamos perdiendo.
- `D` en rango de empate: tablas.
- `D < BOT_WAR_ATTRITION_EMPATE_D[0]`: estamos ganando.

**Cuándo rendirse** (se evalúa periódicamente en el loop de diplomacia):
1. Si `duración_guerra > BOT_WAR_MIN_DURATION_HOURS` y `D > BOT_WAR_SURRENDER_D_THRESHOLD` → rendirse.
2. Si `duración_guerra > BOT_WAR_MAX_DURATION_HOURS` → rendirse automáticamente (incluso si D es bajo, para no estar en guerra eterna).
3. Si la alianza enemiga tiene `I_{enemigo→nosotros} > BOT_WAR_HEAT_SURRENDER_THRESHOLD` (ej. 8.0) y `D > 0.5` → rendirse (el enemigo es muy hostil y no va a parar).

**Empate forzoso por tiempo**:
- Si `duración_guerra > BOT_WAR_AUTO_EMPATE_HOURS` y ningún bando se ha rendido, se declara **empate automático**.
- No hay tributo. Se firma NAP por `BOT_WAR_NAP_DURATION_HOURS`.
- El heat se reduce en `BOT_WAR_HEAT_REDUCTION_ON_PEACE` en ambos sentidos.
- Esto evita guerras eternas sin ganador.

**Proceso de rendición**:
1. El bot líder decide rendirse (o el empate automático activa el mismo flujo pero sin tributo).
2. Calcula el **tributo** a ofrecer:
   - Base: `pérdidas_enemigas × BOT_WAR_TRIBUTE_BASE_RATIO`.
   - Mínimo: `nuestros_recursos_totales × BOT_WAR_TRIBUTE_MIN_RATIO`.
   - Máximo: `nuestros_recursos_totales × BOT_WAR_TRIBUTE_MAX_RATIO`.
   - **Ajuste por heat**: cuanto más bajo sea nuestro heat hacia el enemigo (menos hostilidad), más alto será el tributo dentro del rango (somos más generosos porque "nos caen bien"). Cuanto más alto el heat, más tacaño (ofrecemos el mínimo).
3. Envía un MP a la otra alianza ofreciendo paz con el tributo.
4. **El receptor SIEMPRE acepta o contraoferta** — no hay rechazo sin respuesta.

**Reglas de aceptación/contraoferta** (en el bando que recibe la oferta):
- **Aceptar directamente** si:
  - El tributo cubre el mínimo que consideramos aceptable.
  - Ese mínimo depende del **heat**: cuanto más bajo sea nuestro heat hacia el oferente (mejor relación), menos exigimos. Cuanto más alto el heat, más pedimos.
  - Fórmula: `tributo_mínimo_aceptable = pérdidas_nuestras × (0.3 + 0.5 × factor_heat)`, donde `factor_heat` va de 0 (heat mínimo 0.1) a 1 (heat máximo 10.0).
    - Si heat = 0.1 (muy amistoso): `0.3 + 0.5 × 0 = 0.3` → aceptamos con solo el 30% de nuestras pérdidas.
    - Si heat = 5.0 (neutro/algo hostil): `0.3 + 0.5 × 0.5 = 0.55` → exigimos el 55%.
    - Si heat = 10.0 (muy hostil): `0.3 + 0.5 × 1.0 = 0.8` → exigimos el 80%.
  - Si el tributo ofrecido ≥ `tributo_mínimo_aceptable` → aceptar.
- **Contraoferta** si:
  - No se cumple lo anterior. El bot responde con un MP indicando:
    - "Rechazamos porque el tributo es insuficiente. Aceptaríamos por X Metal, Y Cristal, Z Deuterio."
    - La contraoferta se calcula como: `pérdidas_nuestras × (1.0 + 0.2 × factor_heat)`, donde `factor_heat` es 0.5–1.5 según lo hostil que seamos.
  - El bot que recibe la contraoferta puede:
    - Aceptarla (si está dentro de su máximo disponible).
    - Recontraofertar (si no llega pero puede acercarse).
    - Si no hay acuerdo tras 3 rondas de contraofertas → empate automático (NAP sin tributo, o con el tributo mínimo).

**Uso de LLM**:
- La evaluación de la oferta de paz y la generación de contraofertas usan **LLM** (nuevo skill `war_diplomacy_v1`).
- El prompt recibe: heat entre los bots implicados, desgaste de cada bando, tributo ofrecido, personalidad del bot, historial de la guerra.
- El LLM decide: aceptar, contraofertar (con qué cifra), o declarar rendición incondicional (solo si heat > 8.0 y estamos ganando claramente).
- Siempre con fallback a reglas si LLM no responde o hay timeout.
- Se reutiliza la cola de jobs existente (`llm_jobs.php`).

**Ejecución de la paz**:
- Se entrega el tributo vía transporte (una sola flota).
- Se firma NAP por `BOT_WAR_NAP_DURATION_HOURS` horas.
- El heat entre ambas alianzas se reduce en `BOT_WAR_HEAT_REDUCTION_ON_PEACE` (porcentaje, ej. 0.80 = se reduce un 80% hacia ambos lados).

**Implementación**:
- Nueva tabla o columna para almacenar `war_metrics` (pérdidas por bando).
- `botWarCalculateAttrition()`: calcula D para cada bando.
- `botWarConsiderSurrender()`: decide si rendirse según las 3 condiciones.
- `botWarCheckAutoEmpate()`: si se superó el tiempo máximo sin rendición, forzar empate.
- `botWarCalculateTribute()`: calcula oferta según desgaste y heat.
- `botWarGenerateSurrenderOffer()`: genera MP con oferta.
- `botWarHandleSurrenderOffer()`: evalúa oferta entrante y decide aceptar/contraofertar (con LLM + fallback reglas).
- `botWarExecutePeace()`: entrega tributo, establece NAP, reduce heat.
- `botWarLlmEvaluate()`: encola job LLM con skill `war_diplomacy_v1` y procesa respuesta.
- **Histórico y ranking de guerras**:
  - Tabla `xgp_bot_war_history` con: `id, alianza_a, alianza_b, fecha_inicio, fecha_fin, resultado (rendición_a/rendición_b/empate), tributo_pagado, pérdidas_totales_a, pérdidas_totales_b, pérdidas_totales_combined`.
  - Cada guerra, al finalizar, se guarda en esta tabla con las métricas finales.
  - Las guerras activas (no terminadas) también tienen registro pero marcadas como "en curso" con métricas parciales.
  - **Ranking**: en el propio juego, una página pública tipo "Hall of Fame" con las guerras más destructivas ordenadas por pérdidas totales.

**Integración con el loop del bot**:
- En el loop de diplomacia, cada bot miembro de una alianza en guerra evalúa periódicamente:
  1. `botWarCheckAutoEmpate()` — si procede, empate forzoso.
  2. `botWarCalculateAttrition()` — actualizar métricas.
  3. `botWarConsiderSurrender()` — decidir si ofrecer rendición.
  4. Revisar si hay ofertas de rendición entrantes (MPs detectados) y procesarlas con `botWarHandleSurrenderOffer()`.

#### e) Líder de alianza (quién toma decisiones)

En las alianzas de bots, hace falta un sistema para determinar **quién decide** las acciones diplomáticas (guerra, paz, NAP, expulsiones).

- **Criterios**: el bot con mayor puntuación + antigüedad en la alianza es el líder.
- **Responsabilidades del líder**:
  - Decidir guerras y paces.
  - Aceptar/rechazar solicitudes de membresía.
  - Enviar/recibir ofertas diplomáticas.
- **Suplencia**: si el líder está inactivo (> 2 horas sin acción), el siguiente en rango asume temporalmente.
- **Implementación simple**: el primer bot de la alianza que ejecuta la acción diplomática actúa como líder para esa acción. Para decisiones importantes (guerra), se requiere consenso de al menos 2 bots o el líder.

#### f) Objetivos de facción / agenda rotatoria

Cada bot (o alianza) podría tener un objetivo rotatorio que cambie semanalmente para que el comportamiento no sea monótono:

| Objetivo | Efecto |
|----------|--------|
| **Expandir territorio** | Priorizar colonización de nuevos planetas |
| **Acumular riqueza** | Priorizar minería, almacenes, transporte de recursos |
| **Guerra total** | Priorizar ataque contra una alianza o jugador concreto |
| **Fortificación** | Priorizar defensas, escudos, murallas |
| **Crecimiento equilibrado** | Perfil mixto sin preferencias marcadas |

- Se almacena en `bot_quirks.objective` y se rota automáticamente cada `BOT_OBJECTIVE_ROTATION_DAYS` días (default 7).
- El objetivo modifica los pesos en `decisions.php` (sección 3) de forma adicional a la personalidad.
- Si el bot está en una alianza, el objetivo lo dicta el líder de la alianza para todos los miembros.

#### g) Heat influye en el tono de mensajes (personalidad LLM — P6)
El heat determinará el tono de los mensajes generados por LLM:
- **Heat > 7.0**: mensajes hostiles, amenazas, exigencias.
- **Heat 3.0–7.0**: mensajes tensos, quejas, advertencias.
- **Heat 0.5–3.0**: mensajes neutros, formales.
- **Heat < 0.5**: mensajes amistosos, agradecimientos, ofrecimientos de ayuda.
- Esto se implementará en P6 pero debe diseñarse ahora para que el heat ya esté cobrando valores reales.

### 1.5. Registro bidireccional automático
Cuando el bot A ataca al jugador B, debería registrarse automáticamente que **B recibe un ataque de A**. Es decir:
1. El bot A ejecuta `botHeatOnInboundHostile` con `observer=userIdDeB, target=userIdDeA`. Pero esto es raro porque el bot A no debería modificar el heat de otro jugador...

**Solución**: no es necesario. El heat de B→A se actualizará cuando B (ya sea otro bot o un humano) procese la llegada de la flota hostil. Para bots, esto ocurre cuando el bot B ejecuta su loop y detecta el ataque entrante mediante `botDetectRecentAttack`. Para humanos, no podemos controlarlo.

**Pero si queremos que el bot atacante "sepa" que el target le tendrá mayor heat**, eso es información especulativa que puede incluirse en el prompt LLM o en la evaluación de riesgo.

### 1.6. Tests
- Añadir tests unitarios para los helpers de decisión influenciados por heat.
- Tests existentes de heat (`testBotHeat*` en BotLibTest.php) ya cubren el core de `heat.php`.
- Tests de integración: simular ataque recibido y verificar que el heat sube, simular ayuda recibida y verificar que baja.

### 1.1. Estado actual
El sistema de calor dirigido (`scripts/bot_lib/heat.php`) está implementado al 100%:
- Tabla `xgp_bot_pair_heat` con `(observer, target, heat, updated_at)`
- Helpers: `botHeatGet`, `botHeatUpsert`, `botHeatWorsen`, `botHeatImprove`, `botHeatDecayOneStepTowardNeutral`, `botHeatDecayTick`
- Rango: `[0.1, 10.0]`, 1.0 = neutro
- `botHeatDecayTick` se llama desde `simple_rule_bot.php:1799`

**Pero NADIE llama a `botHeatOnInboundHostile` ni `botHeatOnHelpReceived`.** El heat es una tabla que arranca a 1.0 y nunca la modifica ninguna acción real. Además, nadie consulta el heat para tomar decisiones.

### 1.2. Eventos que modifican el heat

| Evento | Quién lo ejecuta | Efecto en observer→target |
|--------|-----------------|---------------------------|
| Recibir ataque (somos víctima) | `attack.php` al detectar flota hostil | `botHeatWorsen` (+1 o +0.1 según rango) |
| Ser espiado | `spy.php` al detectar sonda enemiga | `botHeatWorsen` (leve, +0.1) |
| Perder batalla (defensa destruida) | `attack.php` al procesar reporte de derrota | `botHeatWorsen` fuerte (+2) |
| Recibir ayuda (transporte de recursos) | `transport.php` / `ally_logistics.php` | `botHeatImprove` (−1 o −0.1 según rango) |
| Recibir mensaje amistoso / tregua | Cuando se procese el sistema de mensajes LLM | `botHeatImprove` (leve, −0.1) |
| Aliarse (entramos en misma alianza) | `alliance_policy.php` / `alliance.php` | `botHeatImprove` directo a 0.5 (amistad base) |
| Atacar a alguien (nosotros atacamos) | `attack.php` al lanzar ataque | El **target** también genera heat hacia nosotros (registro bidireccional automático) |

**Importante**: el heat es **direccional** (observer→target). Si A ataca a B, sube el heat de B→A (B recuerda la agresión), pero no necesariamente sube el de A→B (A puede sentir que actuó con razón). Aunque en la práctica, cuando el bot A ataca, también debería considerar que el target B le tendrá mayor heat, y eso puede afectar decisiones futuras.

### 1.3. Reglas de decay (corrección)
- El decay **resetea el heat a 1.0 directamente** tras una semana sin interacción.
- Da igual que el heat sea 10, 0.1 o 5.0: si ha pasado una semana sin interactuar con ese jugador, el heat vuelve a 1.0 (neutro).
- **Frecuencia**: semanal. `BOT_HEAT_DECAY_INTERVAL_SEC = 604800` (7 días).
- Esto significa que las relaciones son intensas pero volátiles: duran mientras haya interacción, y se borran con una semana de inactividad.
- **Cambio necesario en `heat.php`**: `botHeatDecayOneStepTowardNeutral` debe convertirse en `botHeatDecayResetToNeutral` y en lugar de mover un escalón, asignar `$heat = 1.0` directamente.
- También `botHeatDecayTick` debe simplificarse: en vez de seleccionar solo filas con heat != 1, seleccionar todas las filas vencidas y resetearlas a 1.0.

### 1.4. Cómo el heat influye en el comportamiento

El heat debe afectar **decisiones tácticas** y **tono de comunicación**:

#### a) Heat influye en selección de targets de ataque
En `scripts/bot_lib/attack.php`, en `botAttackScanCandidates` o en el pipeline de selección de target:
- **Heat > 3.0** (enemistad): bonus de puntuación. El bot recuerda viejas enemistades y prioriza atacar a quien le ha hecho daño.
- **Heat < 0.5** (amistad): penalización. El bot evita atacar a "amigos" que le han ayudado.
- **Heat 0.3–0.7** (muy amistoso): bloqueo directo de ataque (no ataca a aliados cercanos).
- **Heat 1.0** (neutro): sin sesgo.

#### b) Heat influye en decisiones de alianza
En `scripts/bot_lib/alliance_policy.php`:
- Al evaluar un solicitante: si el bot (o sus miembros) tienen heat alto hacia él → más probable rechazar.
- Al decidir expulsión de un miembro: si el bot tiene heat alto hacia ese miembro → más probable kick.
- Al decidir a qué alianza postularse: preferir aquellas donde tengamos heat bajo con los miembros.

#### c) Heat influye en diplomacia
En `scripts/bot_lib/diplomacy_actions.php`:
- El bot necesita decidir si propone NAP, mantiene estatus quo, presiona o va a la guerra con otra alianza.
- Para medir la agresividad entre alianzas usamos una **matriz de conflicto inter-alianzas** en lugar de una simple media.

**Modelo de medición de agresividad inter-alianzas**:

Se calcula con dos métricas complementarias:

1. **Heat agregado total** (suma de todo el heat de miembros de A hacia miembros de B):
   \[
   H_{A→B} = \sum_{miembro \in A} \sum_{target \in B} \text{heat}(miembro, target)
   \]
   Esto captura el volumen total de conflicto.

2. **Participación** (% de pares A→B con heat > 1.5):
   \[
   P_{A→B} = \frac{\text{pares con heat} > 1.5}{\text{total pares posibles}}
   \]
   Si solo 1 de 10 miembros tiene problemas, la participación es baja.

3. **Índice de agresividad** (combinación ponderada):
   \[
   I_{A→B} = H_{A→B} \times (0.5 + 0.5 \times P_{A→B})
   \]
   - Un par de miembros peleados aporta heat, pero si la participación es baja el índice no se dispara.
   - Una alianza donde muchos miembros tienen conflictos moderados genera un índice alto.

**Umbrales para decisiones diplomáticas**:
- `I_{A→B} < 0.5`: relaciones amistosas → proponer NAP.
- `I_{A→B} 0.5–2.0`: neutro. Mantener estatus quo.
- `I_{A→B} 2.0–5.0`: tensión. Aumentar presión diplomática (exigencias, quejas).
- `I_{A→B} > 5.0`: hostil. Guerra probable.

**¿Cuándo se recalcula?**
- No en tiempo real. Se calcula bajo demanda cuando el bot considera una acción diplomática.
- Si no hay datos de heat entre dos alianzas (nadie ha interactuado nunca), el índice es 0 (neutro por defecto).
- El cálculo se hace consultando la tabla `bot_pair_heat` con un JOIN a las tablas de usuarios/alianzas.

**Nota sobre la direccionalidad**: el heat es direccional. Para diplomacia consideramos la **dirección más hostil**:
\[
I_{AB} = \max(I_{A→B}, I_{B→A})
\]
Porque si A odia a B pero B es neutral, igual hay conflicto latente.

#### d) Mecánica de guerras, rendición y tributo

Cuando dos alianzas están en guerra (estado declarado), los bots deben:
1. **Medir pérdidas** de cada bando periódicamente.
2. **Decidir rendirse** si se cumplen condiciones (tiempo, desgaste).
3. **Exigir o pagar tributo** como condición de paz.
4. **Gestionar contraofertas** con LLM.

**Variables configurables** (para poder ajustar durante desarrollo/pruebas):
| Variable | Default (producción) | Default (pruebas) | Descripción |
|----------|---------------------|-------------------|-------------|
| `BOT_WAR_MIN_DURATION_HOURS` | 4 | 1 | Horas mínimas antes de poder rendirse |
| `BOT_WAR_MAX_DURATION_HOURS` | 48 | 4 | Si se supera, empate automático |
| `BOT_WAR_SURRENDER_D_THRESHOLD` | 0.70 | 0.60 | Desgaste necesario para rendirse |
| `BOT_WAR_ATTRITION_EMPATE_D` | 0.40–0.60 | 0.35–0.65 | Rango de empate en desgaste |
| `BOT_WAR_TRIBUTE_BASE_RATIO` | 0.30 | 0.30 | Fracción de pérdidas enemigas como tributo base |
| `BOT_WAR_TRIBUTE_MIN_RATIO` | 0.10 | 0.05 | Fracción mínima de nuestros recursos |
| `BOT_WAR_TRIBUTE_MAX_RATIO` | 0.50 | 0.30 | Fracción máxima de nuestros recursos |
| `BOT_WAR_NAP_DURATION_HOURS` | 336 (14d) | 24 (1d) | Duración de la NAP tras firmar paz |
| `BOT_WAR_HEAT_REDUCTION_ON_PEACE` | 0.50 | 0.80 | Reducción de heat al firmar paz (% se resta) |
| `BOT_WAR_AUTO_EMPATE_HOURS` | 48 | 4 | Si la guerra dura más de X horas sin rendición, empate forzoso |

> En el código, `getenv('BOT_WAR_MIN_DURATION_HOURS') ?? 4`, con override por env var para cambiar sin tocar código.

**¿Qué se considera pérdida?**
Se usa una **métrica de desgaste** por alianza:
- Flota destruida (valor en recursos).
- Defensas destruidas.
- Recursos saqueados por el enemigo.
- Puntos de flota perdidos.

Almacenado como campo `war_metrics` JSON en la tabla de guerras:
```json
{"alianzaA": {"fleet_losses": 100000, "defense_losses": 50000, "loot_stolen": 20000}, "alianzaB": {...}}
```

**Índice de desgaste**:
\[
D_{propio} = \frac{\text{pérdidas totales propias}}{\text{pérdidas totales propias} + \text{pérdidas totales enemigas}}
\]
- `D > BOT_WAR_ATTRITION_EMPATE_D[1]`: estamos perdiendo.
- `D` en rango de empate: tablas.
- `D < BOT_WAR_ATTRITION_EMPATE_D[0]`: estamos ganando.

**Cuándo rendirse** (se evalúa periódicamente en el loop de diplomacia):
1. Si `duración_guerra > BOT_WAR_MIN_DURATION_HOURS` y `D > BOT_WAR_SURRENDER_D_THRESHOLD` → rendirse.
2. Si `duración_guerra > BOT_WAR_MAX_DURATION_HOURS` → rendirse automáticamente (incluso si D es bajo, para no estar en guerra eterna).
3. Si la alianza enemiga tiene `I_{enemigo→nosotros} > BOT_WAR_HEAT_SURRENDER_THRESHOLD` (ej. 8.0) y `D > 0.5` → rendirse (el enemigo es muy hostil y no va a parar).

**Empate forzoso por tiempo**:
- Si `duración_guerra > BOT_WAR_AUTO_EMPATE_HOURS` y ningún bando se ha rendido, se declara **empate automático**.
- No hay tributo. Se firma NAP por `BOT_WAR_NAP_DURATION_HOURS`.
- El heat se reduce en `BOT_WAR_HEAT_REDUCTION_ON_PEACE` en ambos sentidos.
- Esto evita guerras eternas sin ganador.

**Proceso de rendición**:
1. El bot líder decide rendirse (o el empate automático activa el mismo flujo pero sin tributo).
2. Calcula el **tributo** a ofrecer:
   - Base: `pérdidas_enemigas × BOT_WAR_TRIBUTE_BASE_RATIO`.
   - Mínimo: `nuestros_recursos_totales × BOT_WAR_TRIBUTE_MIN_RATIO`.
   - Máximo: `nuestros_recursos_totales × BOT_WAR_TRIBUTE_MAX_RATIO`.
   - **Ajuste por heat**: cuanto más bajo sea nuestro heat hacia el enemigo (menos hostilidad), más alto será el tributo dentro del rango (somos más generosos porque "nos caen bien"). Cuanto más alto el heat, más tacaño (ofrecemos el mínimo).
3. Envía un MP a la otra alianza ofreciendo paz con el tributo.
4. **El receptor SIEMPRE acepta o contraoferta** — no hay rechazo sin respuesta.

**Reglas de aceptación/contraoferta** (en el bando que recibe la oferta):
- **Aceptar directamente** si:
  - El tributo cubre el mínimo que consideramos aceptable.
  - Ese mínimo depende del **heat**: cuanto más bajo sea nuestro heat hacia el oferente (mejor relación), menos exigimos. Cuanto más alto el heat, más pedimos.
  - Fórmula: `tributo_mínimo_aceptable = pérdidas_nuestras × (0.3 + 0.5 × factor_heat)`, donde `factor_heat` va de 0 (heat mínimo 0.1) a 1 (heat máximo 10.0).
    - Si heat = 0.1 (muy amistoso): `0.3 + 0.5 × 0 = 0.3` → aceptamos con solo el 30% de nuestras pérdidas.
    - Si heat = 5.0 (neutro/algo hostil): `0.3 + 0.5 × 0.5 = 0.55` → exigimos el 55%.
    - Si heat = 10.0 (muy hostil): `0.3 + 0.5 × 1.0 = 0.8` → exigimos el 80%.
  - Si el tributo ofrecido ≥ `tributo_mínimo_aceptable` → aceptar.
- **Contraoferta** si:
  - No se cumple lo anterior. El bot responde con un MP indicando:
    - "Rechazamos porque el tributo es insuficiente. Aceptaríamos por X Metal, Y Cristal, Z Deuterio."
    - La contraoferta se calcula como: `pérdidas_nuestras × (1.0 + 0.2 × factor_heat)`, donde `factor_heat` es 0.5–1.5 según lo hostil que seamos.
  - El bot que recibe la contraoferta puede:
    - Aceptarla (si está dentro de su máximo disponible).
    - Recontraofertar (si no llega pero puede acercarse).
    - Si no hay acuerdo tras 3 rondas de contraofertas → empate automático (NAP sin tributo, o con el tributo mínimo).

**Uso de LLM**:
- La evaluación de la oferta de paz y la generación de contraofertas usan **LLM** (nuevo skill `war_diplomacy_v1`).
- El prompt recibe: heat entre los bots implicados, desgaste de cada bando, tributo ofrecido, personalidad del bot, historial de la guerra.
- El LLM decide: aceptar, contraofertar (con qué cifra), o declarar rendición incondicional (solo si heat > 8.0 y estamos ganando claramente).
- Siempre con fallback a reglas si LLM no responde o hay timeout.
- Se reutiliza la cola de jobs existente (`llm_jobs.php`).

**Ejecución de la paz**:
- Se entrega el tributo vía transporte (una sola flota).
- Se firma NAP por `BOT_WAR_NAP_DURATION_HOURS` horas.
- El heat entre ambas alianzas se reduce en `BOT_WAR_HEAT_REDUCTION_ON_PEACE` (porcentaje, ej. 0.80 = se reduce un 80% hacia ambos lados).

**Implementación**:
- Nueva tabla o columna para almacenar `war_metrics` (pérdidas por bando).
- `botWarCalculateAttrition()`: calcula D para cada bando.
- `botWarConsiderSurrender()`: decide si rendirse según las 3 condiciones.
- `botWarCheckAutoEmpate()`: si se superó el tiempo máximo sin rendición, forzar empate.
- `botWarCalculateTribute()`: calcula oferta según desgaste y heat.
- `botWarGenerateSurrenderOffer()`: genera MP con oferta.
- `botWarHandleSurrenderOffer()`: evalúa oferta entrante y decide aceptar/contraofertar (con LLM + fallback reglas).
- `botWarExecutePeace()`: entrega tributo, establece NAP, reduce heat.
- `botWarLlmEvaluate()`: encola job LLM con skill `war_diplomacy_v1` y procesa respuesta.
- **Histórico y ranking de guerras**:
  - Tabla `xgp_bot_war_history` con: `id, alianza_a, alianza_b, fecha_inicio, fecha_fin, resultado (rendición_a/rendición_b/empate), tributo_pagado, pérdidas_totales_a, pérdidas_totales_b, pérdidas_totales_combined`.
  - Cada guerra, al finalizar, se guarda en esta tabla con las métricas finales.
  - Las guerras activas (no terminadas) también tienen registro pero marcadas como "en curso" con métricas parciales.
  - **Ranking**: en el propio juego, una página pública tipo "Hall of Fame" con las guerras más destructivas ordenadas por pérdidas totales.

**Integración con el loop del bot**:
- En el loop de diplomacia, cada bot miembro de una alianza en guerra evalúa periódicamente:
  1. `botWarCheckAutoEmpate()` — si procede, empate forzoso.
  2. `botWarCalculateAttrition()` — actualizar métricas.
  3. `botWarConsiderSurrender()` — decidir si ofrecer rendición.
  4. Revisar si hay ofertas de rendición entrantes (MPs detectados) y procesarlas con `botWarHandleSurrenderOffer()`.

#### e) Heat influye en el tono de mensajes (personalidad LLM — P6)

#### e) Heat influye en el tono de mensajes (personalidad LLM — P6)
El heat determinará el tono de los mensajes generados por LLM:
- **Heat > 7.0**: mensajes hostiles, amenazas, exigencias.
- **Heat 3.0–7.0**: mensajes tensos, quejas, advertencias.
- **Heat 0.5–3.0**: mensajes neutros, formales.
- **Heat < 0.5**: mensajes amistosos, agradecimientos, ofrecimientos de ayuda.
- Esto se implementará en P6 pero debe diseñarse ahora para que el heat ya esté cobrando valores reales.

### 1.5. Registro bidireccional automático
Cuando el bot A ataca al jugador B, debería registrarse automáticamente que **B recibe un ataque de A**. Es decir:
1. El bot A ejecuta `botHeatOnInboundHostile` con `observer=userIdDeB, target=userIdDeA`. Pero esto es raro porque el bot A no debería modificar el heat de otro jugador...
   
**Solución**: no es necesario. El heat de B→A se actualizará cuando B (ya sea otro bot o un humano) procese la llegada de la flota hostil. Para bots, esto ocurre cuando el bot B ejecuta su loop y detecta el ataque entrante mediante `botDetectRecentAttack`. Para humanos, no podemos controlarlo.

**Pero si queremos que el bot atacante "sepa" que el target le tendrá mayor heat**, eso es información especulativa que puede incluirse en el prompt LLM o en la evaluación de riesgo.

### 1.6. Tests
- Añadir tests unitarios para los helpers de decisión influenciados por heat.
- Tests existentes de heat (`testBotHeat*` en BotLibTest.php) ya cubren el core de `heat.php`.
- Tests de integración: simular ataque recibido y verificar que el heat sube, simular ayuda recibida y verificar que baja.

---

## 2. Estados de ánimo / "mood" temporal (P1-bis)

### 2.1. Concepto
Además del heat (relación persistente con otros jugadores), cada bot tiene un **estado de ánimo temporal** que afecta sus decisiones a corto plazo. El mood se basa en eventos recientes y decae con el tiempo.

### 2.2. Estados posibles

| Mood | Activado por | Efecto | Duración |
|------|-------------|--------|----------|
| **Neutral** | Por defecto | Comportamiento normal | Indefinido |
| **Enfadado** | Recibir 2+ ataques en 1 hora | Más agresivo: +50% frecuencia de ataque, umbral de rentabilidad más bajo | 2–4 horas |
| **Cauteloso** | Perder una batalla con pérdidas > 20% de la flota | Menos agresivo: -50% frecuencia de ataque, umbral más alto, prioriza construir defensas | 2–4 horas |
| **Vengativo** | Ser atacado 3+ veces por el mismo jugador en 24h | Heat hacia ese jugador sube el doble de rápido. Prioriza atacar a ese jugador | 4–8 horas |
| **Agradecido** | Recibir ayuda/transporte de alguien | Heat hacia ese jugador baja el doble de rápido. Más propenso a devolver el favor | 2–4 horas |
| **Confiado** | Varios ataques exitosos seguidos (3+) | Más agresivo, umbral de rentabilidad bajo, tiende a infravalorar defensas enemigas | 1–2 horas |
| **Paranoico** | Ser espiado 3+ veces en 1 hora | Prioriza construir defensas y escudos. Reduce actividad de ataque. | 1–2 horas |

### 2.3. Implementación
- Almacenado en `bot_quirks.mood`: `{current: "neutral", since: timestamp, triggered_by: userId, decays_at: timestamp}`.
- Se actualiza en el loop del bot al procesar eventos (ataque recibido, batalla perdida/ganada, spy, ayuda).
- El mood modifica los mismos parámetros que la personalidad (sección 3) pero de forma temporal y con mayor peso.
- Al cambiar de mood, el bot puede generar un mensaje de personalidad (P6): "¡Estoy que ardo! Ese ataque no quedará impune."

### 2.4. Decay del mood
- Cada mood tiene una duración fija configurable (`BOT_MOOD_DURATION_MINUTES`, default 120).
- Pasado ese tiempo, el mood vuelve a `neutral`.
- Si ocurre un evento que activaría el mismo mood mientras ya está activo, se renueva la duración (el bot está "cada vez más enfadado").
- Si ocurre un evento que activaría un mood opuesto (ej. recibir ayuda mientras estás enfadado), hay un 30% de probabilidad de que se cancele el mood actual.

---

## 3. LLM para decisiones de ataque

### 2.1. Estado actual
- `scripts/bot_lib/llm_attack_decision.php` implementado: skill `attack_decision_v1`, sistema prompt, helpers `botLlmAttackIsHighStakes`, `botLlmAttackFilterRaidLogVsUser`
- `scripts/bot_lib/llm_jobs.php`: cola MySQL asíncrona. Estados: pedido → procesando → respondido → done / fallido.
- `scripts/llm_worker.php`: worker dedicado que consume jobs y llama a Ollama.
- `docker-compose.yml`: servicio `llm-worker` ejecutando `scripts/llm_worker.php --forever --sleep 3`.

**Pero el módulo de ataque (`botAttackRunPurposeful`) nunca encola jobs LLM.** Nadie llama a las funciones de `llm_attack_decision.php`.

### 2.2. Decisión de producto
**Todos los ataques pasan por LLM.** No solo los de alto riesgo. El flujo será:

1. El pipeline de ataque calcula candidato (como hoy): simulación, ratio, loot estimado.
2. **Antes de armar el plan** (two-phase commit), se encola un job LLM con skill `attack_decision_v1`.
3. El job contiene el contexto completo: stats del bot vs target, heat, alianzas, guerra/NAP, historial de raids previos contra ese target, colas de hangar, slots libres.
4. El worker procesa y deja el job en `respondido`.
5. En el siguiente loop, `botAttackRunPurposeful` recoge los jobs respondidos:
   - Si LLM dice `attack=true` → procede con el plan (arma sonda recheck).
   - Si LLM dice `attack=false` → descarta el target con log `attack: skip {coords} (llm veto)`.

### 2.3. Latencia
- Cada ciclo de loop es ~10s (`--sleep 10`). El worker procesa en ~3s.
- En el peor caso: un ataque tarda **2 loops** (uno para encolar job, otro para recoger respuesta). Aceptable.
- Si el worker está caído o el job falla: **el bot ataca igual** (fallback al comportamiento actual sin LLM). El LLM es un optimizador, no un gatekeeper obligatorio.

### 2.4. Protecciones
- `BOT_LLM_ATTACK_MAX_QUEUED`: máximo número de jobs LLM de ataque encolados simultáneamente (default 3). Si se alcanza, los ataques adicionales usan la decisión normal (sin LLM).
- `BOT_LLM_JOB_TIMEOUT_SEC`: timeout del worker (default 300s). Tras este tiempo el job se considera fallido y el bot ataca sin LLM.

### 2.5. Test
- Test unitario: verificar que `botLlmAttackIsHighStakes` y `botLlmAttackFilterRaidLogVsUser` funcionan.
- Test de integración (in-vivo): forzar un ataque con `BOT_LLM_ATTACK_NET_LOOT_MIN=1` y verificar que se encola un job y el worker lo procesa.

---

## 4. Diferenciación de personalidades (matizada) (P3)

### 3.1. Decisión de producto
**Todos los bots hacen de todo** (construyen minas, tienen naves, atacan, colonizan).
Las personalidades marcan **preferencias**, no exclusiones.

### 3.2. Ajustes concretos

#### a) Pesos de candidatos más diferenciados
En `scripts/bot_lib/decisions.php`:
- **Mineros**: peso extra para minas, almacenes, energía. Peso reducido para defensas (pero no cero).
- **Floteros**: peso extra para hangar, naves de ataque, big cargos. Peso reducido para minas (pero no cero).
- **Defensores**: peso extra para defensas, escudos, murallas. Peso reducido para naves de ataque (pero no cero).
- **Cazadores**: peso extra para naves de combate pesadas, velocidad de ataque.
- **Granjeros**: perfil mixto, equilibrado.

#### b) Frecuencia de ataque
En `scripts/bot_lib/attack.php`:
- **Mineros/defensores**: `BOT_ATTACK_MAX_PER_LOOP_PER_USER` se escala hacia abajo (ej. 0-1 en vez de 2).
- **Floteros/cazadores**: escala hacia arriba (ej. 2-4).

#### c) Umbral de rentabilidad
- **Mineros/defensores**: threshold más alto (no arriesgan naves por poco botín).
- **Floteros/cazadores**: threshold más bajo (ataques rápidos aunque el botín sea pequeño).

### 3.3. Sin cambios en colonización/transporte
Todos siguen colonizando y transportando recursos. La personalidad no bloquea ninguna mecánica base.

---

## 5. Memoria contra jugadores específicos (mejora sobre heat) (P4)

### 4.1. Estado actual
`bot_quirks` ya guarda `attacks_in_flight` con historial de loot real y losses. El heat existe pero no se usa.

### 4.2. Mejora
Combinar heat + historial de raids para:
- **Farmeo inteligente**: identificar targets que sistemáticamente tienen recursos y pocas defensas → priorizarlos.
- **Evitar trampas**: si un target nos ha causado pérdidas 2+ veces seguidas, penalizarlo en la selección (esperar a que baje la guardia).
- **Rivalidades**: si dos bots se atacan mutuamente varias veces, el heat se dispara y se crea una rivalidad persistente que influye en diplomacia (presión de guerra) y en decisión de ataque (prioridad).

### 4.3. Implementación
En `botAttackRunPurposeful`, después de `botAttackHarvestReturns`:
- Si el raid completado tuvo losses > 0 y loot < estimado, registrar heat worsen hacia el target.
- Si el raid fue exitoso (loot ≈ estimado, losses = 0), mantener o mejorar ligeramente.

---

## 6. Logging, observabilidad y spy reporting (P5)

### 6.1. Problema actual
Los bots imprimen a stdout dentro del contenedor Docker. `docker compose logs bot` muestra líneas como:
```
[boto] profile=default style=raider arch=balanced pers=flotero focus=eco | planet 41: ...
```
Es difícil de seguir. No hay una visión agregada de "qué está haciendo cada bot ahora".

### 6.2. Panel de estado en admin

#### a) Estado actual del bot (nuevo endpoint o vista)
En `app/Http/Controllers/Adm/BotstatsController.php`, añadir una tabla/vista que muestre **por bot**:
- Nombre, alianza, puntos.
- Última acción (timestamp + descripción corta).
- Estado: `idle`, `building`, `transporting`, `attacking`, `sleeping`, `spying`.
- Nº de planetas, flotas activas, colas de construcción.
- Heat alto/bajo con otros jugadores (top 3).
- Última vez que atacó / fue atacado.

#### b) Métricas nuevas
Añadir a `bot_quirks.metrics`:
- `heat_high_count`: número de entradas de heat con valor > 3.0.
- `last_action_desc`: string corto con la última acción del bot (ej. "built mine", "attacked 1:2:3").
- `last_action_at`: timestamp de la última acción.
- `loops_without_action`: contador de loops sin ninguna acción útil.

#### c) Alertas de "bot zombie"
En el panel admin, marcar en rojo los bots que:
- Llevan > 1 hora sin acciones (sin construir, sin atacar, sin transportar).
- Tienen colas de construcción/b hangar paradas (algo bloqueado).
- Tienen energía negativa persistente.

### 6.3. Spy reporting

Cuando un bot recibe un espionaje o espía a alguien, ese resultado debe registrarse y ser visible en el panel.

- Añadir a `bot_quirks.metrics` (o tabla separada) el último resultado de spy recibido por el bot:
  ```json
  {"spy_reports": [
    {"from": "user_name", "coords": "1:2:3", "timestamp": 1715000000, "result": "resources_only|fleet_detected|defenses_detected|full_report"},
    {"to": "target_name", "coords": "4:5:6", "timestamp": 1715000100, "result": "resources: 100k m, 50k c, 20k d"}
  ]}
  ```
- En el panel de admin (BotstatsController), mostrar los últimos N spys recibidos y realizados por cada bot.
- Esto ayuda a depurar: "¿por qué el bot atacó a X? Ah, porque su spy report mostraba recursos."

### 6.4. Logs estructurados
Cambiar los logs de texto plano a un formato más parseable:
- Cada línea de acción: `[timestamp] [bot_name] [action_type] [detail]`
- Ejemplo: `[2026-05-13 14:00:00] [bot2] [attack] launched raid on 1:2:3 (loot=500k)`
- Esto permitiría filtrar fácilmente: `docker logs bot | grep "\[attack\]"`

---

## 7. LLM para procesar mensajes entrantes (inbox scanner) (P5-bis)

### 7.1. Problema
Los bots ya envían MPs (ofertas de paz, diplomacia, etc.) pero **no leen ni entienden los MPs que reciben**. Si un jugador humano (u otro bot) les escribe, el mensaje se pierde.

Para que toda la diplomacia funcione (contraofertas de paz, amenazas, negociaciones), los bots deben:
1. Escanear periódicamente los MPs que reciben.
2. Clasificar la intención del mensaje.
3. Decidir qué acción tomar como respuesta.

### 7.2. Solución: LLM inbox scanner con salida JSON

Cada vez que el bot ejecuta su loop, escanea los MPs no leídos y encola un job LLM con un nuevo skill `inbox_scanner_v1`.

**Prompt del skill**:
> "Eres un bot de un juego de estrategia espacial. Has recibido el siguiente mensaje de [remitente]. Tu perfil es [personalidad]. El heat hacia este jugador es [heat]. Tienes [guerra/NAP/neutral] con su alianza.
>
> Mensaje: [texto del MP]
>
> Analiza el mensaje y responde en JSON con esta estructura exacta:
> ```json
> {
>   "intent": "attack_threat|peace_offer|surrender_offer|help_request|insult|friendly|alliance_request|resource_request|neutral|unknown",
>   "requires_response": true|false,
>   "suggested_action": "ignore|respond_friendly|respond_hostile|send_resources|declare_war|accept_peace|reject_peace|propose_truce|escalate_to_ally_leader",
>   "heat_delta": -1.0,
>   "response_text": "Texto del MP de respuesta (solo si requires_response=true)",
>   "response_tone": "friendly|neutral|hostile|sarcastic",
> }
> ```
>
> Donde:
> - `intent`: clasificación del mensaje recibido.
> - `requires_response`: si el bot debe responder (no todos los mensajes requieren respuesta).
> - `suggested_action`: qué debe hacer el bot como consecuencia.
> - `heat_delta`: cuánto subir o bajar el heat hacia este jugador (ej. +1 por amenaza, -0.5 por mensaje amistoso). Rango [-2.0, +2.0].
> - `response_text`: borrador del MP de respuesta (solo si requires_response=true).
> - `response_tone`: tono sugerido para la respuesta."

**Procesamiento de la respuesta**:
1. El worker LLM procesa el job y deja la respuesta JSON en `respondido`.
2. El bot, en el siguiente loop, recoge los jobs `respondido` y ejecuta:
   - **`heat_delta`** → aplicar `botHeatWorsen` o `botHeatImprove` según el valor.
   - **`suggested_action`**:
     - `ignore`: no hacer nada (archivar el mensaje).
     - `respond_friendly` / `respond_hostile`: enviar MP con `response_text`.
     - `send_resources`: enviar transporte con recursos (si aplica).
     - `declare_war`: encolar acción diplomática de guerra.
     - `accept_peace`: activar flujo de paz.
     - `reject_peace`: responder rechazando (usando `response_text`).
     - `propose_truce`: proponer tregua/NAP.
     - `escalate_to_ally_leader`: reenviar el asunto al líder de la alianza.
   - **`response_text`**: se envía como MP si `requires_response=true`.

### 7.3. Control de saturación
- `BOT_LLM_INBOX_MAX_PER_LOOP`: máximo de MPs escaneados por loop (default 3).
- `BOT_LLM_INBOX_COOLDOWN_SEC`: no escanear la misma conversación más de una vez cada X segundos (default 300 = 5 min).
- Los jobs de inbox tienen **prioridad media**: por debajo de ataques (P2) pero por encima de personalidad (P8).

### 7.4. Fallback sin LLM
Si el worker LLM está caído o timeout:
- Los mensajes de bots conocidos (con heat registrado) se procesan con reglas simples:
  - Si contiene "paz", "tregua", "NAP" → sugerir aceptar.
  - Si contiene "ataque", "guerra", "destruir" → sugerir responder hostil.
  - Si contiene "recursos", "ayuda" → evaluar según heat y disponibilidad.
  - Si no se puede clasificar → `intent: "unknown", suggested_action: "ignore"`.

### 7.5. Integración con otros sistemas
- **Diplomacia (sección 1d)**: si un humano responde a una oferta de paz, el inbox scanner la detecta y activa el flujo de contraofertas.
- **Heat (sección 1)**: el `heat_delta` devuelto por LLM modifica el heat automáticamente.
- **Personalidad (sección 8)**: los mensajes entrantes pueden activar gatilladores de personalidad (ej. insulto → respuesta sarcástica).

### 7.6. Implementación
- Nuevo skill LLM: `inbox_scanner_v1`.
- `botInboxScanNewMessages()`: consulta MPs no leídos del bot y encola jobs LLM.
- `botInboxProcessResponses()`: recoge jobs respondidos y ejecuta acciones.
- `botInboxFallbackRules()`: procesamiento sin LLM.
- Integración en el loop principal del bot.

---

## 8. LLM para personalidad: amenazas, disculpas, mensajes emotivos (P6, diferido)

### 8.1. Visión general
Los bots deben poder **iniciar** conversaciones (no solo responder). Ejemplos:
- Un bot atacado envía un MP con amenazas o exigiendo recursos como compensación.
- Un bot que pierde una batalla importante pide paz o se disculpa.
- Un bot aliado agradece ayuda recibida.
- Un bot "flotero" se ríe de un enemigo derrotado.
- Un bot "defensor" ofrece protección a cambio de recursos.

### 8.2. Arquitectura propuesta

#### a) Nuevo skill LLM: `personality_inbox_v1`
Reutilizar la cola de jobs existente. Nuevo sistema prompt que recibe:
- Perfil del bot (personalidad, arquetipo, estilo).
- Últimos eventos relevantes (fue atacado, ganó/perdió batalla, recibió ayuda, etc.).
- Lista de contactos "importantes" (aliados, enemigos recurrentes, víctimas frecuentes).
- Instrucción: "decide si debes enviar un mensaje a alguien. Si sí, genera el MP".

#### b) Gatilladores (`scripts/bot_lib/personality_messaging.php`)
- **Al recibir un ataque**: probabilidad (según personalidad) de enviar amenaza/queja.
- **Al ganar una batalla**: probabilidad de burlarse o exigir tributo.
- **Al perder una batalla**: probabilidad de pedir paz, disculparse, o prometer venganza.
- **Al recibir ayuda**: probabilidad de agradecer (si no se ha hecho ya).
- **Periódicamente (cada N loops)**: probabilidad baja de enviar mensaje aleatorio "acorde a personalidad".

#### c) Control de saturación
- `BOT_LLM_PERSONALITY_MAX_PER_HOUR`: máximo de MPs generados por evento de personalidad por hora (default 1).
- `BOT_LLM_PERSONALITY_COOLDOWN_PER_TARGET`: no enviar más de 1 MP al mismo target cada 24h (para evitar spam).
- Los jobs de personalidad tienen **prioridad baja**: si la cola LLM está llena con jobs de ataque/inbox, los de personalidad esperan.

#### d) Integración con heat
- Si heat hacia un target es muy alto (> 7.0): los mensajes son hostiles.
- Si heat es muy bajo (< 0.3): los mensajes son amistosos.
- Neutro: mensajes formales/neutrales.
- El tono también se ve afectado por el **mood** del bot (sección 2): un bot enfadado envía mensajes más agresivos aunque el heat sea neutro.

### 8.3. Implementación diferida
Este es un **próximo paso**, no inmediato. Primero:
1. Cablear heat (sección 1)
2. Sistema de moods (sección 2)
3. LLM para ataques (sección 3)
4. Observabilidad e inbox scanner (secciones 6 y 7)

Después:
5. Personalidad avanzada (sección 4)
6. Mensajes con personalidad (sección 8)

---

## Resumen de orden de implementación

| Prioridad | Tarea | Dependencias | Impacto |
|-----------|-------|-------------|---------|
| **P1** | Cablear heat al comportamiento + guerra/diplomacia | Ninguna | Alto — los bots empiezan a recordar |
| **P1-bis** | Sistema de moods | P1 (heat) | Medio — comportamiento más impredecible |
| **P2** | LLM todos los ataques | Ninguna (código existe) | Alto — ataques más realistas |
| **P3** | Diferenciación de personalidad | P1 (heat ayuda) | Medio — bots más diversos |
| **P4** | Memoria contra jugadores | P1 (heat) | Medio — farmeo inteligente |
| **P5** | Observabilidad, spy reporting, logs | Ninguna | Medio — saber qué pasa |
| **P5-bis** | LLM inbox scanner | P5 (logs ayudan) | Alto — bots entienden MPs |
| **P6** | Mensajes con personalidad (LLM) | P1, P2, P5-bis | Bajo (diferido) |

## Plan de trabajo por sesión

### Sesión 1: Cablear heat + diplomacia básica
- `heat.php`:
  - Modificar constante `BOT_HEAT_DECAY_INTERVAL_SEC` a 604800 (7 días).
  - Renombrar `botHeatDecayOneStepTowardNeutral` → `botHeatDecayResetToNeutral`: asigna `$heat = 1.0` directamente.
  - Simplificar `botHeatDecayTick`: seleccionar **todas** las filas vencidas y resetearlas a 1.0, sin filtro de heat != 1.
- `attack.php`: enganchar `botHeatOnInboundHostile` cuando detectamos ataque entrante (`botDetectRecentAttack`). También cuando detectamos que alguien nos ha espiado (sonda enemiga llegó a nuestro planeta) — heat worsen leve (+0.1). También cuando ganamos/perdemos batalla (heat worsen +2 si perdemos).
- `transport.php` / `ally_logistics.php`: enganchar `botHeatOnHelpReceived` cuando recibimos transporte.
- `attack.php` / `botAttackScanCandidates`: sesgar targets por heat (>3.0 bonus, <0.5 penalización, <0.3 bloqueo).
- `alliance_policy.php`: sesgar decisiones de alianza por heat + sistema de líder de alianza.
- `diplomacy_actions.php`: implementar modelo de agresividad inter-alianzas con `botAllianceHeatIndex()`.
- `diplomacy_actions.php`: implementar `botWarCalculateAttrition()`, `botWarCheckAutoEmpate()`, `botWarConsiderSurrender()`, `botWarCalculateTribute()`, `botWarGenerateSurrenderOffer()`, `botWarHandleSurrenderOffer()`, `botWarExecutePeace()`, `botWarLlmEvaluate()`.
- `llm_jobs.php` / `llm_attack_decision.php`: añadir nuevo skill `war_diplomacy_v1` para evaluar ofertas de paz y generar contraofertas.
- `llm_worker.php`: asegurar que procesa el nuevo skill.
- `heat.php`: helper `botHeatAllianceReduce(alianzaA, alianzaB, factor)` para reducir heat tras firmar paz.
- Tabla `xgp_bot_war_history` para histórico de guerras.
- Tests unitarios e integración.

### Sesión 2: Sistema de moods
- `scripts/bot_lib/mood.php`: nuevo archivo con `botMoodGet()`, `botMoodSet()`, `botMoodDecay()`, `botMoodCheckEvents()`.
- Integrar moods en decisiones de ataque (modificar umbrales según mood).
- `bot_quirks.mood`: almacenar estado de ánimo.
- Tests.

### Sesión 3: LLM ataques
- `botAttackRunPurposeful`: añadir paso de encolar job LLM antes de armar plan.
- `botAttackRunPurposeful`: recoger jobs respondidos y vetar/confirmar.
- Incluir heat y mood en el prompt de `attack_decision_v1`.
- Protecciones: `BOT_LLM_ATTACK_MAX_QUEUED`, timeout, fallback sin LLM.
- Tests unitarios + validación in-vivo.

### Sesión 4: Personalidad matizada
- `decisions.php`: ajustar pesos por personalidad.
- `attack.php`: escalar frecuencia/umbral por personalidad.
- Añadir objetivos de facción rotatorios (`bot_quirks.objective`).
- Tests.

### Sesión 5: Memoria contra jugadores
- Combinar heat + historial de raids para farmeo inteligente.
- Evitar trampas.
- Rivalidades persistentes.

### Sesión 6: Observabilidad
- BotstatsController: nueva vista de estado por bot (incluyendo mood y objetivo).
- `bot_quirks.metrics`: campos nuevos (Última acción, heat count, mood, last spys, etc.).
- Alertas de bot zombie.
- Spy reporting en panel.
- Logs estructurados.

### Sesión 7: LLM inbox scanner
- Nuevo skill LLM `inbox_scanner_v1` con salida JSON estructurada.
- `botInboxScanNewMessages()`: escanea MPs no leídos y encola jobs.
- `botInboxProcessResponses()`: ejecuta acciones según respuesta LLM.
- `botInboxFallbackRules()`: procesamiento sin LLM.
- Integración en loop principal.

### Sesión 8+: Personalidad LLM (diferido)
- Nuevo skill `personality_inbox_v1`.
- Gatilladores por evento (ataque recibido, batalla ganada/perdida, etc.).
- Control de saturación.
- Integración con heat y mood.
