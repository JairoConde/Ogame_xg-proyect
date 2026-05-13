# Bot diplomacy module — Fase 1

> Estado: en construcción (2026-05-11). Reglas confirmadas con el usuario. Este documento es la fuente de verdad.

## Decisiones de producto cerradas

1. Diplomacia **siempre alianza ↔ alianza**. Único actor: el **owner** de la alianza (v1).
2. Guerras **unilaterales**: el owner declara y la fila queda activa al instante. No hay aceptación.
3. **Atacar fuera de guerra está permitido**, pero cada ataque a un miembro de otra alianza acumula **presión** contra la atacante. Cuando la presión supera un umbral, los líderes bot de la víctima evalúan declarar la guerra automáticamente.
4. La presión se acumula también cuando un **humano** ataca a un bot de otra alianza. Los humanos no actúan automáticamente sobre la presión: deciden manualmente desde la UI.
5. Estando en guerra:
   - Los bots **priorizan** atacar planetas de la alianza enemiga sobre cualquier otro objetivo. Si no hay candidatos viables, caen al pool normal.
   - El umbral de **rentabilidad mínima** para lanzar ataque **baja** un porcentaje (`BOT_DIPLO_WAR_RENTABILITY_DISCOUNT`).
6. **Paz**: una alianza que pierde mucho puede proponer paz. El owner contrario puede aceptar/rechazar. Si la propuesta **incluye recursos** y la cantidad supera un umbral (función del daño recibido durante la guerra), la paz se **firma automáticamente** ("soborno").
7. **Pactos de no agresión** ("NAP" en código, "Pacto" en UI): bilaterales (ambos owners aceptan), tienen `expires_at` obligatorio. Si una alianza **rompe un NAP** (ataca a un miembro del otro lado mientras el NAP esté activo), recibe penalización de 24h sin poder:
   - proponer/aceptar paz,
   - proponer/aceptar otro pacto,
   - declarar guerra.
8. UI:
   - **Pestaña "Diplomacia"** con dos bloques: "Guerras" (declarante a la izquierda, víctima a la derecha, fecha) y "Pactos" (alianzas A y B, expira en).
   - Menú **"Declarar guerra"** en la pestaña de alianza → dropdown con todas las demás alianzas → seleccionar → confirmar. Solo visible para el owner.
9. **Ajuste extra** (no diplomático): bots **no aplican a alianzas con > 10 miembros** (constante `BOT_ALLIANCE_APPLY_MAX_MEMBERS`).

## Datos

### Tabla `xgp_alliance_diplomacy` (ya existe)

Añadir columnas:

```sql
ALTER TABLE xgp_alliance_diplomacy
    ADD COLUMN `declared_by` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `expires_at`,
    ADD COLUMN `damage_a_to_b` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `declared_by`,
    ADD COLUMN `damage_b_to_a` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `damage_a_to_b`,
    ADD COLUMN `last_action_at` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `damage_b_to_a`;
```

- `declared_by`: `alliance_id` que declaró la guerra (0 para `nap` o `neutral`). Indispensable para mostrar "declarante a la izquierda" en la UI.
- `damage_a_to_b` y `damage_b_to_a`: `metal + crystal + deut` perdido o robado durante esta guerra. Se usan para calcular el umbral de soborno y para mostrar daños en la UI. La convención es que `damage_X_to_Y` cuenta lo que `X` infligió a `Y` (los pares se almacenan normalizados, ver `botDiplomacyNormalisePair`).

### Tabla nueva `xgp_alliance_diplomacy_pressure`

Presión acumulada de una alianza atacante sobre una alianza víctima. **Es dirigida** (víctima no es atacante).

```sql
CREATE TABLE `xgp_alliance_diplomacy_pressure` (
    `pressure_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `victim_alliance_id` INT UNSIGNED NOT NULL,
    `attacker_alliance_id` INT UNSIGNED NOT NULL,
    `pressure` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_attack_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_decay_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`pressure_id`),
    UNIQUE KEY `pair` (`victim_alliance_id`, `attacker_alliance_id`),
    KEY `victim` (`victim_alliance_id`)
) ENGINE=InnoDB;
```

- Unidad: equivalente metal+crystal+deut destruido o robado por la atacante (o un proxy si no se puede calcular: número de naves perdidas × valor medio).
- Decay: lineal con el tiempo. Cuando supera `BOT_DIPLO_PRESSURE_WAR_THRESHOLD`, los owners bot evalúan declarar guerra al atacante.

### Tabla nueva `xgp_alliance_diplomacy_cooldown`

Penalizaciones temporales (24h por romper NAP, etc.).

```sql
CREATE TABLE `xgp_alliance_diplomacy_cooldown` (
    `cooldown_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `alliance_id` INT UNSIGNED NOT NULL,
    `kind` ENUM('peace_block','nap_block','war_block') NOT NULL,
    `until_at` INT UNSIGNED NOT NULL,
    `reason` VARCHAR(64) NULL,
    PRIMARY KEY (`cooldown_id`),
    UNIQUE KEY `pair` (`alliance_id`, `kind`),
    KEY `alliance` (`alliance_id`)
) ENGINE=InnoDB;
```

### Tabla nueva `xgp_alliance_diplomacy_log`

Auditoría de acciones diplomáticas. Útil para la UI y para depurar.

```sql
CREATE TABLE `xgp_alliance_diplomacy_log` (
    `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `from_alliance_id` INT UNSIGNED NOT NULL,
    `to_alliance_id` INT UNSIGNED NOT NULL,
    `actor_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `action` VARCHAR(32) NOT NULL,
    `payload` TEXT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`log_id`),
    KEY `from` (`from_alliance_id`),
    KEY `to` (`to_alliance_id`),
    KEY `created` (`created_at`)
) ENGINE=InnoDB;
```

Acciones registradas: `declare_war`, `propose_peace`, `accept_peace`, `reject_peace`, `pay_peace`, `propose_nap`, `accept_nap`, `reject_nap`, `break_nap`, `auto_declare_war` (cuando lo dispara el acumulador de presión).

## Constantes (en `safety.php`)

| Constante | Valor | Significado |
|---|---:|---|
| `BOT_DIPLO_PRESSURE_PER_ATTACK_MIN` | 1 000 | Suelo de presión por ataque. |
| `BOT_DIPLO_PRESSURE_WAR_THRESHOLD` | 1 000 000 | Suma de presión a partir de la cual los bot owners evalúan declarar guerra. |
| `BOT_DIPLO_PRESSURE_DECAY_PER_HOUR` | 50 000 | Decay lineal. Sin actividad, una presión saturada (~`THRESHOLD`) tarda ~20h en desaparecer. |
| `BOT_DIPLO_AUTO_DECLARE_CHANCE_X100` | 80 | Probabilidad (sobre 100) de que un owner bot declare guerra cuando ya hay presión sobre el umbral. |
| `BOT_DIPLO_PEACE_BRIBE_RATIO_X100` | 60 | Multiplicador. La paz queda firmada automáticamente si los recursos enviados al líder atacante son ≥ `damage_attacker_to_victim * ratio/100`. |
| `BOT_DIPLO_WAR_RENTABILITY_DISCOUNT_X100` | 20 | Descuento sobre el threshold de rentabilidad mínima cuando el target está en guerra (`profitable_ratio_threshold * (1 - 0.20)`). |
| `BOT_DIPLO_BREAK_NAP_COOLDOWN_SECONDS` | 86 400 | 24h. Aplicado a `peace_block`, `nap_block` y `war_block` simultáneamente. |
| `BOT_DIPLO_NAP_DEFAULT_DURATION_SECONDS` | 604 800 | 7 días por defecto. El owner puede pedir más al proponer. |
| `BOT_DIPLO_LEADER_REVIEW_COOLDOWN` | 30 | Segundos entre evaluaciones diplomáticas del mismo owner bot (mismo cooldown que las acciones de alianza). |

## Helpers y archivos

| Archivo | Rol |
|---|---|
| `scripts/migrate_create_alliance_diplomacy_v2.php` | Migración: añade columnas a `xgp_alliance_diplomacy` y crea las 3 tablas nuevas. |
| `scripts/bot_lib/diplomacy.php` | Helpers de lectura/escritura. Extiende lo que ya hay: `botDiplomacyRecordAttack`, `botDiplomacyDecayPressure`, `botDiplomacyDeclareWar`, `botDiplomacyProposePeace`, `botDiplomacyAcceptPeace`, `botDiplomacyPayPeace`, `botDiplomacyProposeNap`, `botDiplomacyAcceptNap`, `botDiplomacyBreakNap`, `botDiplomacyApplyCooldown`, `botDiplomacyCooldownActive`. |
| `scripts/bot_lib/diplomacy_policy.php` | Reglas (al estilo de `alliance_policy.php`): `decideDeclareWar`, `decideProposePeace`, `decideAcceptPeace`, `decideProposeNap`, `decideAcceptNap`. Usa personalidad. |
| `scripts/bot_lib/attack.php` | Sesgo de target en guerra y descuento de rentabilidad. Hook en el harvest de returns para llamar a `botDiplomacyRecordAttack`. |
| `scripts/simple_rule_bot.php` | Llama a `botDiplomacyTick` para el owner bot tras `botAllianceTick`. Llama a `botDiplomacyDecayPressure` con baja frecuencia (1 vez / N loops). |
| `app/Http/Controllers/Game/DiplomacyController.php` | Pestaña "Diplomacia". |
| `app/Http/Controllers/Game/AllianceController.php` | Botón "Declarar guerra" para el owner. |

## Comportamiento por fases (implementación incremental)

### Fase 1 — Datos + helpers de lectura/escritura sin comportamiento (este turno)

- Migración con las nuevas tablas/columnas.
- Helpers: `Record/DecayPressure`, `DeclareWar`, `Cooldown*`. Solo el código, sin sesgar comportamiento de ataques aún.
- Tests unitarios de:
  - Decay lineal.
  - Umbral.
  - Normalización de pares.
- Métricas en `BotstatsController`: `diplo_pressure_recorded`, `diplo_war_declared`, `diplo_peace_proposed`, `diplo_peace_accepted`, `diplo_peace_bribed`, `diplo_nap_proposed`, `diplo_nap_accepted`, `diplo_nap_broken`, `diplo_cooldown_applied`.

### Fase 2 — Comportamiento bot (implementada)

- `botDiplomacyRecordAttack` y `botDiplomacyAddDamage` enganchados en `botAttackHarvestReturns`. Cada ataque completado registra presión `(loot_real + ship_losses_value)` y suma daño tanto en dirección atacante→víctima (loot) como víctima→atacante (losses).
- `botDiplomacyTick` (en `scripts/bot_lib/diplomacy_actions.php`) ejecuta para owners bot:
  - `botDiplomacyProcessIncomingMessages`: lee bandeja, procesa `[DIPLO_PEACE]`, `[DIPLO_NAP]`, `[DIPLO_PEACE_ACK]`, `[DIPLO_NAP_ACK]`.
  - `botDiplomacyEvaluatePressure`: por cada `attacker_alliance_id` con presión ≥ umbral, tirada de probabilidad `BOT_DIPLO_AUTO_DECLARE_CHANCE_X100` para declarar guerra. Respeta `war_block` cooldown.
  - `botDiplomacyEvaluateActiveWars`: si la alianza pierde por mucho (`damage_received ≥ 2 × damage_dealt` y `damage_received ≥ 50k`), envía propuesta de paz al owner contrario con soborno proporcional al daño recibido.
  - Persiste `bot_quirks.diplomacy.last_review_at` para respetar `BOT_DIPLO_LEADER_REVIEW_COOLDOWN`.
- `botAttackReorderEnemiesFirst`: nuevo helper que sesga la lista de candidatos antes del scan, anteponiendo planetas en alianzas enemigas.
- Descuento de rentabilidad en `botAttackHandlePendingPlans`: si target pertenece a alianza enemiga, threshold se multiplica por `(1 - BOT_DIPLO_WAR_RENTABILITY_DISCOUNT_X100/100)`. Métrica `diplo_war_threshold_applied`.

### Fase 3 — Paz y NAP (implementada)

- **Propuesta de paz**: si una alianza pierde por mucho, su owner bot envía `[DIPLO_PEACE from_ally=X peer_ally=Y offered=N]` al owner contrario. `N = damage_received × BOT_DIPLO_PEACE_BRIBE_RATIO_X100/100`.
- **Aceptación**: el receptor parsea el marker, compara contra `damage_we_inflicted × ratio/100`. Si `offered ≥ required`, firma paz (status='neutral') y notifica con `[DIPLO_PEACE_ACK accepted=1]`. Si no, responde con `accepted=0`.
- **NAP**: cualquier marker `[DIPLO_NAP from_ally=X peer_ally=Y duration=D]` recibido fuera de guerra y sin `nap_block` activo se acepta automáticamente vía `botDiplomacyUpsertNap`. ACK enviado al proponente. Por ahora la *propuesta proactiva* de NAP queda como hook futuro; la aceptación reactiva ya funciona end-to-end.
- **Detección de incumplimiento**: en cada `botAttackHarvestReturns` al cerrar un ataque completado, si existe un `nap` activo entre la alianza atacante y la víctima, se llama a `botDiplomacyHandleNapBreach` que ejecuta `botDiplomacyBreakNap` (la fila se elimina) y aplica 24h de cooldown en `peace_block`, `nap_block` y `war_block` a la alianza incumplidora.
- Soborno (en v1 **declarativo**, el receptor confía en el campo `offered=N`; transferencia real con transporte misión 3 queda como mejora futura).
- NAP propuesto/aceptado/incumplido + aplicación de cooldown 24h.

### Fase 4 — UI (implementada)

- `app/Http/Controllers/Game/DiplomacyController.php` (`MODULE_ID = 25`) con tres acciones:
  - Vista por defecto: tabla de **Guerras activas** (declarante a la izquierda, daño infligido/recibido) y tabla de **Pactos activos** (alianzas A/B, desde, expira).
  - `action=declare`: formulario con dropdown de todas las demás alianzas. Bloquea si el owner tiene `war_block` activo (penalización por incumplir NAP).
  - `action=declare_post`: POST que llama a `botDiplomacyDeclareWar` reutilizando `Database::getConnection()` + `Database::getPrefix()`. Comparte el helper con los bots.
- Vistas: `resources/views/game/diplomacy_view.php`, `resources/views/game/diplomacy_declare_view.php`. i18n en `resources/lang/{spanish,english}/game/diplomacy.php`.
- Menú lateral: nueva entrada `diplomacy` en `App\Libraries\Page::gameMenu` (módulo 25 en `xgp_options.modules`).
- En la pestaña de alianza, el owner ve un bloque al final con el botón "Declarar guerra a otra alianza" → redirige a `game.php?page=diplomacy&action=declare`.
- Migración `scripts/migrate_enable_diplomacy_module.php` para crecer `xgp_options.modules` en BDs existentes (de 25 a 26 entradas).

### Fase 5 — Tests + smoke in-vivo (implementada)

- PHPUnit: añadidos `testAttackReorderEnemiesFirstPullsEnemiesToFront`, `testAttackReorderEnemiesFirstHandlesEmptyInputs`, `testDiplomacyParseMarkerExtractsKeyValues`, `testDiplomacyParseMarkerReturnsNullWhenMissing`, `testDiplomacyParseMarkerDoesNotPickWrongMarker`. Total: 191 tests, 1397 assertions, 23 skipped por `mysqli`.
- Smoke in-vivo validado: con presión artificial > umbral, `bot2` (owner de alianza 5) declaró guerra automáticamente a alianza 9 en su tick de owner, dejando `xgp_alliance_diplomacy` con `declared_by=5` y un registro `declare_war` en `xgp_alliance_diplomacy_log`.

## Riesgos identificados

- **Race en presión**: dos bots atacándose al mismo tiempo pueden incrementar `pressure` en paralelo. Resuelto con `INSERT ... ON DUPLICATE KEY UPDATE pressure = pressure + VALUES(pressure)`.
- **NAP roto por ACS**: cuando un grupo ACS ya está volando contra un objetivo cuya alianza acaba de firmar NAP con la atacante, el motor no cancela flotas. Comportamiento esperado en v1: se considera incumplimiento → cooldown aplicado y batalla resuelta normalmente.
- **Owner cambia**: si el owner bot transfiere liderazgo durante una guerra/NAP, las cooldowns y propuestas siguen ligadas a la **alianza**, no al usuario. La transferencia es inocua.
- **Spam de declaraciones**: limitado por `BOT_DIPLO_LEADER_REVIEW_COOLDOWN` + estado actual (no se redeclara si ya hay `war`).

## Fuera de Fase 1

- Rangos personalizados (heredan del backlog de alianza).
- Vasallaje / federación entre alianzas.
- Diplomacia disparada por LLM.
