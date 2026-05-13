# Bot Attack Module — Plan & Estado

Documento de seguimiento del nuevo módulo de ataque para los bots
(`scripts/bot_lib/attack.php` + `combat.php`). Incluye el plan original,
qué quedó implementado, decisiones de diseño cerradas con el usuario y
una checklist concreta para la próxima sesión.

> Última actualización: 2026-05-11 (métricas, tope `intel`, harvest-only fuera de ventana horaria, reactividad ofensiva, transacciones en insert de fleets, observación de loot/losses reales, recheck pre-ataque, presión de carga → big cargos prioritarios, refinamientos: panel, override almacén, role-gating, fleet slot gate)

---

## 1. Resumen ejecutivo

Los bots ahora pueden:

1. **Espiar** planetas enemigos enviando sondas reales (`mission=6`)
   y cosechando un *snapshot* de naves/defensas/recursos directamente de
   la BD cuando la sonda llega (sin parsear HTML del informe).
2. **Simular** la batalla con un combate determinista simplificado
   (`CombatCaps` + bonus de research, máx 6 rondas).
3. **Atacar** (`mission=1`) cuando la profitabilidad supera un
   *threshold* `X` configurable y dependiente de la agresividad del bot.

Punto de entrada: `botAttackRunPurposeful()`, llamado en
`scripts/simple_rule_bot.php` justo después de la colonización.

Estado: **implementado y verificado con 162 tests unitarios verdes**
(`vendor/bin/phpunit tests/`). Cubre el flujo base + métricas, harvest-only
fuera de ventana, reactividad ofensiva, transacciones atómicas en inserts
de fleets, observación de loot/losses reales, recheck pre-ataque (two-phase
commit), presión de carga / big cargos prioritarios y los refinamientos
de panel/override/role-gating/fleet-slot.

---

## 2. Decisiones de diseño cerradas con el usuario

| Pregunta | Respuesta acordada |
| --- | --- |
| ¿Cómo obtiene el bot la info del objetivo? | **Realista**: envía sondas reales (mission=6), espera la llegada y entonces decide. Implementado leyendo el snapshot directamente de la BD (no parseando el informe HTML). |
| ¿Cómo simular la batalla? | **Estimador propio simplificado**: `CombatCaps` (atk/shield/HP) + bonus de research, sin rapid-fire, sin RNG. Determinista. |
| ¿Quién puede ser objetivo? | **Cualquiera salvo sí mismo y misma alianza** (incluye admins). Filtros adicionales en SQL: vacaciones, baneados, planeta tipo 1. |
| ¿Cómo definir "ganancia > pérdidas × X"? | **`(botín_estimado − fuel) ≥ X × valor_naves_perdidas`**. El fuel no cuenta como ganancia. |
| ¿Sistemas vetados? | **Sistema 1 de cada galaxia**, configurable mediante `BOT_ATTACK_RESERVED_SYSTEMS`. |
| Rango de `X` | **3 .. 6 por defecto**, clamp absoluto `[1.5, 10.0]`, configurable global y por perfil. |

---

## 3. Constantes y tunables

Todos viven en [`scripts/bot_lib/safety.php`](../scripts/bot_lib/safety.php)
así que un solo archivo concentra los *knobs* del nuevo pipeline.

| Constante | Default | Significado |
| --- | --- | --- |
| `BOT_ATTACK_MAX_PER_LOOP_PER_USER` | 2 | Ataques reales por loop por bot. |
| `BOT_SPY_MAX_PER_LOOP_PER_USER` | 4 | Misiones de sonda por loop. |
| `BOT_ATTACK_COOLDOWN_BASE_SECONDS` | 1800 | Cooldown por planeta-fuente. Personality lo escala. |
| `BOT_SPY_COOLDOWN_BASE_SECONDS` | 600 | Cooldown por planeta-objetivo (sondas). |
| `BOT_INTEL_TTL_SECONDS` | 1800 | Validez del *snapshot* de intel. |
| `BOT_INTEL_MAX_ENTRIES` | 50 | Tras el filtro TTL, como máximo se guardan N entradas en `intel` (las más recientes por `captured_at`). `0` = sin límite. |
| `BOT_ATTACK_MIN_LOOT_ESTIMATE` | 50_000 | Mínimo botín estimado para considerar ataque. |
| `BOT_ATTACK_FLEET_RESERVE_PCT` | 0.20 | Fracción de cada tipo de nave que NUNCA viaja. |
| `BOT_SPY_PROBES_PER_MISSION` | 4 | Sondas por misión de espionaje. |
| `BOT_ATTACK_RESERVED_SYSTEMS` | `[1]` | Sistemas vetados (en cualquier galaxia). |
| `BOT_ATTACK_RATIO_VERY_AGGRESSIVE` | 3.0 | Threshold X para `aggressiveness >= 8`. |
| `BOT_ATTACK_RATIO_AGGRESSIVE` | 4.0 | Threshold X para `[6, 8)`. |
| `BOT_ATTACK_RATIO_MODERATE` | 5.0 | Threshold X para `[4, 6)`. |
| `BOT_ATTACK_RATIO_CONSERVATIVE` | 6.0 | Threshold X para `< 4`. |
| `BOT_ATTACK_RATIO_MIN` | 1.5 | Clamp inferior absoluto. |
| `BOT_ATTACK_RATIO_MAX` | 10.0 | Clamp superior absoluto. |

### Override por perfil (`scripts/bot_accounts.json`)

`extractProfileConfig` ahora acepta:
- `attack_ratio` — número fijo, ignora la agresividad. Útil para
  bots con threshold único.
- `attack_ratios` — mapa parcial:
  ```json
  {
    "very_aggressive": 2.5,
    "aggressive": 3.5,
    "moderate": 5.0,
    "conservative": 7.0
  }
  ```
  Las claves omitidas caen al default global.

Ambos overrides se *clampean* a `[BOT_ATTACK_RATIO_MIN, BOT_ATTACK_RATIO_MAX]`.

---

## 4. Archivos creados / modificados

| Ruta | Estado |
| --- | --- |
| [`scripts/bot_lib/safety.php`](../scripts/bot_lib/safety.php) | Modificado: añadidas todas las constantes de ataque + helper `botAttackReservedSystems()`. |
| [`scripts/bot_lib/travel.php`](../scripts/bot_lib/travel.php) | Modificado: nuevo `botPickRaidMix()` (cargos óptimos + escolta + reserva). |
| [`scripts/bot_lib/combat.php`](../scripts/bot_lib/combat.php) | **Nuevo**: simulador determinista. |
| [`scripts/bot_lib/attack.php`](../scripts/bot_lib/attack.php) | **Nuevo**: módulo orquestador (intel + scan + simulación + INSERT). |
| [`scripts/simple_rule_bot.php`](../scripts/simple_rule_bot.php) | Modificado: `require_once` + invocación tras colonización + `extractProfileConfig` allowlist (`attack_ratio`, `attack_ratios`). |
| [`tests/scripts/bootstrap.php`](../tests/scripts/bootstrap.php) | Modificado: carga `combat.php` y `attack.php`. |
| [`tests/scripts/BotLibTest.php`](../tests/scripts/BotLibTest.php) | Modificado: 11 tests nuevos para combate y attack. |

---

## 5. Algoritmo del simulador (`combat.php`)

Por ronda (máximo `BOT_COMBAT_MAX_ROUNDS=6`):

1. Calcular `total_attack`, `total_shield` y `total_hull` de cada lado:
   - `attack_per_unit = base_attack × (1 + 0.10 × weapons_level)`
   - `shield_per_unit = base_shield × (1 + 0.10 × shielding_level)`
   - `hull_per_unit   = (metal+crystal+deuterium)/10 × (1 + 0.10 × armour_level)`
2. `damage_to_X = max(0, total_attack_otroLado − total_shield_X)`.
3. Daño se reparte entre unidades de cada lado **proporcional a su
   share de hull** y se convierte en bajas usando hull por unidad.
4. Ambos lados sufren bajas simultáneamente (igual que el motor
   real, donde un bando que muere todavía dispara en su última ronda).
5. Si un lado tiene `total_hull <= 0` antes de las 6 rondas, gana el
   otro. Si ambos viven al final → empate.

**Sin RNG, sin rapid-fire**, sólo determinismo. Suficiente para una
decisión "compensa atacar?".

Salida:
```php
[
  'winner' => 'attacker' | 'defender' | 'draw',
  'rounds_used' => int,
  'attacker_losses_by_id' => [ship_id => count],
  'defender_losses_by_id' => [unit_id => count],
  'attacker_losses_value' => int (m+c+d sum),
  'defender_losses_value' => int,
  'debris' => ['metal' => int, 'crystal' => int],
]
```

---

## 6. Pipeline de `botAttackRunPurposeful()`

```
┌──────────────────────────────────────────────────────────────┐
│ Loop del bot (per usuario, tras colonización)                │
└──────────────────────────────────────────────────────────────┘
                             │
                             ▼
       ┌────────────────────────────────────────┐
       │ 1. botAttackHarvestIntel               │
       │   - sondas con expected_arrival <= now │
       │   - leer snapshot DB → bot_quirks.intel│
       └────────────────────────────────────────┘
                             │
                             ▼
       ┌────────────────────────────────────────┐
       │ 2. botAttackScanCandidates (SQL)       │
       │   - planet_type=1                      │
       │   - user != self, user_ally != self    │
       │   - vacaciones=0, banned=0             │
       │   - planet_system NOT IN reservedList  │
       └────────────────────────────────────────┘
                             │
                             ▼
       ┌────────────────────────────────────────┐
       │ 3. Por cada target:                    │
       │   - ¿intel fresco?                     │
       │     · NO  → enviar sonda (mission=6)   │
       │     · SÍ  → botPickSourceForAttack +   │
       │             botPickRaidMix +           │
       │             botCombatSimulate +        │
       │             ratio (loot-fuel)/losses   │
       │             ≥ threshold?               │
       │             → INSERT attack mission=1  │
       └────────────────────────────────────────┘
                             │
                             ▼
       ┌────────────────────────────────────────┐
       │ 4. botAttackSaveCooldowns              │
       │   - persiste attack/spy cooldowns,     │
       │     pending_spies, intel en bot_quirks │
       └────────────────────────────────────────┘
```

### Persistencia (clave en `bot_state.bot_quirks` JSON)

```json
{
  "attack_cooldowns": { "<source_planet_id>": <ts> },
  "spy_cooldowns":    { "<target_planet_id>": <ts> },
  "pending_spies": {
    "<fleet_id>": {
      "target_planet_id": int,
      "expected_arrival": <ts>,
      "sent_at":          <ts>
    }
  },
  "intel": {
    "<target_planet_id>": {
      "captured_at": <ts>,
      "sent_at":     <ts>,
      "snapshot": {
        "planet_id": int,
        "galaxy": int, "system": int, "planet": int,
        "resources": { "metal": int, "crystal": int, "deuterium": int },
        "ships":    { "<id>": int, ... },
        "defenses": { "<id>": int, ... }
      }
    }
  }
}
```

Limpieza: cualquier entrada > 24h es descartada al persistir; el intel
se guarda hasta `2 × BOT_INTEL_TTL_SECONDS` para tener señal histórica.

### Detalle crítico — formato de `fleet_array`

El motor del juego (Attack/Spy/Colonize/etc.) lee `fleet_array` con
`FleetsLib::getFleetShipsArray()` ≡ `unserialize()`. **Por tanto el INSERT
del attack module usa `serialize($shipMix)`**, no el formato simple
`"id,count;id,count;"` que `transport.php` escribe (transport puede
permitírselo porque mission=3 nunca lee `fleet_array`).

---

## 7. Score de agresividad y bracket

`botAttackAggressivenessScore(profile, state, sourceRole)` devuelve un
escalar `[0, 10]` clampeado:

| Componente | Delta |
| --- | --- |
| `profile.aggressiveness` | base (1..5) |
| Personality `cazador` | +1.5 |
| Personality `flotero` | +1.0 |
| Personality `defensor` | −1.0 |
| Personality `minero` | −1.5 |
| Archetype `turbo` | +1.0 |
| Archetype `opportunist` | +0.7 |
| Archetype `turtle` | −1.0 |
| Style `raider` | +1.0 |
| Style `bunker` | −1.0 |
| Focus `mil` | +0.8 |
| Focus `eco` | −0.5 |
| Source role `military`/`industrial` | +0.5 |
| Source role `metal`/`crystal`/`support` | −0.3 |

`botAttackAggressivenessBracket(score)`:

| Score | Bracket |
| --- | --- |
| ≥ 8.0 | `very_aggressive` |
| ≥ 6.0 | `aggressive` |
| ≥ 4.0 | `moderate` |
| < 4.0 | `conservative` |

Los empates en `draw` del simulador sólo se aceptan si el bracket es
`very_aggressive`; en cualquier otro caso el ataque se cancela.

---

## 8. Tests añadidos

11 tests nuevos en `tests/scripts/BotLibTest.php`:

1. `testCombatSimulateAttackerOverwhelmsTinyDefender`
2. `testCombatSimulateIsDeterministic`
3. `testCombatLossesValueSumsBuildCost`
4. `testCombatDebrisOnlyFromShipsNotDefenses`
5. `testAttackProfitabilityRatioFollowsBracket`
6. `testAttackProfitabilityRatioRespectsSingleOverride`
7. `testAttackProfitabilityRatioRespectsPerBracketOverride`
8. `testAttackProfitabilityRatioClampsExtremeOverrides`
9. `testAttackAggressivenessBracketBoundaries`
10. `testAttackAggressivenessScoreIsClampedAndPersonalityBiased`
11. `testAttackIntelTtlExpiry`
12. `testAttackEstimateLootCappedByCargoCapacity`
13. `testAttackReservedSystemsDecodesConstantList`
14. `testRaidMixRespectsFleetReservePercentage`

> Total suite: **91 tests, 1233 assertions, OK**.

Comando:
```bash
vendor/bin/phpunit -c tests/phpunit.xml --testsuite bot_unit
```

---

## 9. Estado actual: ✅ COMPLETADO + validado in-vivo

| Tarea | Estado |
| --- | --- |
| Constantes en `safety.php` | ✅ |
| `botPickRaidMix` en `travel.php` | ✅ |
| `combat.php` (simulador determinista) | ✅ |
| `attack.php` (orquestador completo) | ✅ |
| Cableado en `simple_rule_bot.php` | ✅ |
| `extractProfileConfig` allowlist (`attack_ratio`, `attack_ratios`) | ✅ |
| Tests unitarios | ✅ (91/91 verdes) |
| `php -l` en todos los archivos creados/modificados | ✅ |
| `ReadLints` en todos los archivos | ✅ (0 errores) |
| **Validación in-vivo en Docker** | ✅ (2026-05-11) |
| **Observabilidad: línea `attack: idle` agregada + logs específicos de fallos productivos** | ✅ (2026-05-11) |
| **Métricas persistentes en `bot_quirks.metrics`** (sección 9.2) | ✅ (2026-05-11) |
| **Tope de entradas `intel` (`BOT_INTEL_MAX_ENTRIES` + `botAttackTruncateIntelByRecency`)** | ✅ (2026-05-11) |
| **Harvest-only fuera de ventana horaria (`botAttackHarvestOnly` + cableado en `simple_rule_bot.php`)** | ✅ (2026-05-11) |
| **Reactividad ofensiva: bonus decreciente + revenge-first targeting + métrica** | ✅ (2026-05-11) |
| **Inserts de fleets atomizados (`botFleetInsertTransactional` + `SELECT FOR UPDATE`)** | ✅ (2026-05-11) |

---

## 9.1. Validación in-vivo en Docker (2026-05-11)

Ejecutado dentro del contenedor `bot` corriendo
`php scripts/simple_rule_bot.php --config scripts/bot_accounts.json --forever --sleep 10`
con 26 bots y 2 humanos en un universo turbo (`fleet_speed=500000`,
`resource_multiplier=300`).

### ✅ Confirmado funcionando

| Comprobación | Evidencia in-vivo |
| --- | --- |
| Loop estable, 0 fatales | 27+ loops sin abortar |
| `attack: spy` envía sondas reales (mission=6) | bot15 envió a 45 destinos distintos (sys 2-7) |
| `attack: intel` harvest desde BD | 45 snapshots persistidos en `bot_quirks.intel` con la forma `{planet_id, galaxy/system/planet, resources, ships, defenses}` |
| Filtro `BOT_ATTACK_RESERVED_SYSTEMS=[1]` respetado | Ningún spy/attack a `planet_system=1` |
| Cooldowns spy persistidos | `spy_cooldowns` = 45 entradas tras el primer ciclo |
| `pending_spies` se limpia al harvest | Tras volver las sondas pasa a 0 (luego sube cuando vuelve a espiar) |
| `attack: launch` end-to-end | `fleet_id=51 mission=1 fleet_array=a:3:{i:203;i:48;i:205;i:40;i:206;i:80;} src=1:5:8 dst=1:2:1 eta=47s loot=1192374 ratio=1192374.00` |
| **`fleet_array` con `serialize()`** | Formato `a:N:{i:id;i:count;...}` correcto, lo que `FleetsLib::getFleetShipsArray()::unserialize` consume |
| `BOT_ATTACK_FLEET_RESERVE_PCT=0.20` honrado | Con 60 BC / 50 HF / 100 Cr disponibles, el raid llevó 48/40/80 (exactamente 80% de cada tipo) |
| Cooldown attack persistido | `bot_quirks.attack_cooldowns = {"41": <ts>}` tras lanzar |
| Loot capping por cargo capacity | Target 1:2:1 tenía 5.4B en BD; loot se capó a 1.192.374 = ~48 × 25000 (BC capacity), ✓ |
| Regreso del attack | Tras el `eta=47s`, los 48 BC + 40 HF + 80 Cr volvieron a `xgp_ships` para planeta 41 |

### ⚠️ Comportamiento esperado pero confuso al observar

- **Early-game silente**: 25/26 bots no tienen sondas porque aún no
  han investigado `research_espionage_technology`. Antes del fix de
  observabilidad esto era completamente invisible en logs (continue
  silencioso en `botAttackPickSourcePlanetForSpy`).
- **Cooldown post-attack**: tras un ataque, el único planeta del bot
  queda 30 min (`BOT_ATTACK_COOLDOWN_BASE_SECONDS=1800`) en cooldown,
  así que durante ese tiempo los logs muestran `attack: idle ... no_atk_src=N`
  pese a haber intel fresco. Es correcto.

### 🔧 Observabilidad añadida en esta sesión

Para que la próxima validación in-vivo no requiera reproducir el
diagnóstico desde cero, en `scripts/bot_lib/attack.php` se añadió:

1. Contadores agregados por bucket de fallo silencioso
   (`fresh_intel`, `no_spy_src`, `no_atk_src`, `raidmix_fail`,
   `cooldown_spy`).
2. Logs específicos en los 3 fallos productivos (no silencian):
   - `attack: skip {coords} (insert spy failed)`
   - `attack: skip {coords} (no viable raid mix)`
   - `attack: skip {coords} (insert attack failed)`
3. Línea agregada `attack: idle candidates=N fresh_intel=K no_spy_src=A no_atk_src=B raidmix_fail=C cooldown_spy=D`,
   emitida **sólo** cuando el bot está "productivamente atascado"
   (tiene intel fresco o intentó armar raid y no pudo). Bots sin
   sondas en early-game NO emiten este log para evitar ruido.

Tests: 91/91 verdes tras los cambios.

---

## 9.2. Métricas persistentes en `bot_quirks.metrics` (2026-05-11)

Hasta aquí toda la observabilidad vivía en logs efímeros de Docker. Con
`--sleep 10 --forever` el buffer rota y se pierde el histórico, así que
no había forma de comparar comportamiento de un bot entre días o
brackets de agresividad.

### Diseño

Nuevo sub-objeto `metrics` dentro de `bot_quirks`, mutado por el
helper puro `botAttackMergeMetrics($state, $delta)` (testeable en
aislamiento, sin DB) y persistido por la misma `botAttackSaveCooldowns`
que ya escribía las demás sub-claves. Una sola UPDATE por loop.

### Shape

```json
{
  "metrics": {
    "spies_sent": 9,
    "spies_insert_fail": 0,
    "spies_throttled": 0,
    "intel_captured": 9,
    "fresh_intel_events": 261,
    "cooldown_spy_events": 0,

    "attacks_launched": 1,
    "attacks_insert_fail": 0,
    "attacks_throttled": 0,
    "attacks_skipped_low_loot": 0,
    "attacks_skipped_capped_loot": 276,
    "attacks_skipped_sim_lose": 0,
    "attacks_skipped_ratio": 0,
    "attacks_skipped_draw": 0,

    "no_spy_src_events": 0,
    "no_atk_src_events": 261,
    "raidmix_fail": 0,

    "total_loot_launched": 1192374,
    "total_my_losses_value": 1,

    "first_seen_at": 1778486550,
    "last_spy_at": 1778486669,
    "last_attack_at": 1778484448,
    "last_loop_at": 1778486765,
    "loops_processed": 6
  }
}
```

### Reglas de actualización

- **Counter fields** (`spies_sent`, `attacks_*`, `no_*_src_events`,
  `total_loot_launched`, etc.): se *suman* a su valor previo.
- **Timestamp fields** (`last_spy_at`, `last_attack_at`,
  `last_loop_at`): se mantiene el *máximo* visto, nunca regresan
  aunque llegue un delta antiguo.
- **`first_seen_at`**: sticky, se escribe sólo la primera vez.
- **`loops_processed`**: incremento de exactamente 1 por llamada.

### Valor que ya rinde

Tras 6 loops in-vivo, las métricas destaparon dos patrones invisibles
en logs:

1. **`bot2` (1 planeta, 5 small_cargo, 205 heavy_fighter, 131 probes)
   tiene `attacks_skipped_capped_loot=276`**. Es decir, 46 intel fresco
   × 6 loops, *todos* descartados porque el cargo capacity del raid mix
   (4 SC × 5000 ≈ 20k tras reserva) cae bajo `minLoot=50_000`. El bot
   tiene flota de combate pero le falta logística. Decisión correcta del
   módulo, problema visible gracias a las métricas.
2. **`bot15` cooldowned**: `attacks_launched=0` pero
   `fresh_intel_events=261, no_atk_src_events=261` (el único source en
   cooldown post-attack). Sin métricas habría parecido un fallo.

### Tests añadidos

Cinco tests nuevos en `tests/scripts/BotLibTest.php`, todos
ejercitando el helper puro `botAttackMergeMetrics()`:

1. `testAttackMetricsInitializesFromMissingState` — primer merge sobre
   `bot_quirks` vacío deja todos los contadores en el valor del delta
   y `first_seen_at` igual a `last_loop_at`.
2. `testAttackMetricsAccumulateOverMultipleLoops` — dos merges
   consecutivos suman counters y aumentan `loops_processed` a 2.
3. `testAttackMetricsTimestampsKeepMaximum` — un delta con ts más
   antiguo no regresa los timestamps guardados.
4. `testAttackMetricsFirstSeenIsSticky` — segundo merge no pisa
   `first_seen_at`.
5. `testAttackMetricsPreserveUnrelatedQuirks` — `attack_cooldowns` /
   `intel` ajenos al merge se conservan intactos.
6. `testAttackTruncateIntelKeepsNewestWhenOverCap` — orden por `captured_at`.
7. `testAttackTruncateIntelNoOpWhenAtOrUnderCap` — sin truncar si cabe.
8. `testAttackTruncateIntelDisabledWhenMaxZero` — `maxEntries=0` no recorta.
9. `testAttackTruncateIntelTieBreakUsesHigherPlanetId` — empate en segundo.

Total suite: **100 tests, 1266 assertions, OK**.

### Cap de tamaño en `intel` (2026-05-11)

Tras el filtro por antigüedad (`2 × BOT_INTEL_TTL_SECONDS`), `botAttackSaveCooldowns`
llama a `botAttackTruncateIntelByRecency($cleanIntel, BOT_INTEL_MAX_ENTRIES)`:
se conservan las entradas con `captured_at` más reciente; en empate de segundo,
gana el `planet_id` mayor (orden determinista). Constante en
[`scripts/bot_lib/safety.php`](../scripts/bot_lib/safety.php). Cuatro tests en
`BotLibTest.php` cubren el helper.

---

## 9.3. Harvest-only fuera de ventana horaria (2026-05-11)

### Estado previo

`scripts/simple_rule_bot.php:1554` ya cortaba el loop entero con
`continue` cuando `!isInActiveWindow($profile)`. La función
`isInActiveWindow()` ya respetaba todos los modos:

- `schedule_mode == 'always_on'` → siempre activo (sin restricción).
- `schedule_mode == 'windows'` → cualquier hora dentro de cualquier
  `schedule_windows[i]` es activa.
- Fallback con `activity_start` / `activity_end`, soportando ventanas
  que cruzan medianoche.

Por tanto la regla "los `always_on` no tienen ventana" ya estaba
implementada. El pendiente original del plan ("los ataques se mezclan
en cualquier loop") era una nota imprecisa de la sesión anterior.

### Problema sutil identificado

Con la gating de loop, una sonda que aterrice durante el sueño del bot
NO se procesaba hasta el siguiente loop activo. Cuando el bot
despertaba, `botAttackHarvestIntel` capturaba el snapshot **en ese
momento**, marcándolo como `captured_at = time()` aunque la sonda
hubiera llegado horas antes. Eso convertía datos viejos en datos
"recién capturados" desde la perspectiva del `BOT_INTEL_TTL_SECONDS`,
con riesgo de decidir ataques sobre intel obsoleto.

### Solución

Nuevo helper en `scripts/bot_lib/attack.php`:

```php
botAttackHarvestOnly(mysqli $db, string $prefix, array &$state): array
```

Hace exactamente lo siguiente:

1. Carga `attack_cooldowns / spy_cooldowns / pending_spies / intel` con
   `botAttackLoadCooldowns()`.
2. Llama a `botAttackHarvestIntel()` para todas las sondas con
   `expected_arrival <= now`.
3. Persiste con `botAttackSaveCooldowns()` un `metricsDelta` que
   incluye `harvest_only_loops += 1`, `intel_captured += N`,
   `last_loop_at = now`.

NO escanea candidatos, NO envía sondas nuevas, NO lanza ataques: un
bot dormido debe permanecer invisible al motor del juego.

Cableado en `scripts/simple_rule_bot.php`:

```php
if (!isInActiveWindow($profile)) {
    $harvestLogs = botAttackHarvestOnly($db, $prefix, $botState);
    if (!empty($harvestLogs)) {
        echo "sleep window | " . implode(' | ', $harvestLogs) . "\n";
    } else {
        echo "sleep window\n";
    }
    continue;
}
```

### Métricas

Nuevo contador `harvest_only_loops` en
`bot_quirks.metrics`. Permite comparar offline:

```
loops_processed = veces que entró al pipeline completo (en ventana)
harvest_only_loops = veces que solo hizo harvest (fuera de ventana)
```

La ratio `harvest_only_loops / (loops_processed + harvest_only_loops)`
estima la fracción del día que el bot está dormido, útil para
auditar realismo de los perfiles.

### Tests añadidos

Tres tests más en `tests/scripts/BotLibTest.php`:

1. `testAttackMetricsHarvestOnlyLoopsCounterIsRecognised` — ejercita
   el contador a través de `botAttackMergeMetrics` sin necesidad de
   mysqli (siempre ejecutable, también en CI sin la extensión).
2. `testAttackHarvestOnlyAccumulatesLoopsAndDoesNotEmitLogsWithoutPendingSpies`
   — el helper completo con un `mysqli` mock y `__synthetic=true`. Se
   *salta* automáticamente cuando la extensión `mysqli` no está cargada
   en el PHP del host (común fuera de contenedor).
3. `testAttackHarvestOnlyPreservesIntelAndCooldownsAcrossCalls` —
   verifica que dos pasadas consecutivas mantienen
   `attack_cooldowns / spy_cooldowns / intel` intactos y suman
   `harvest_only_loops` correctamente.

### Suite

- Host (sin mysqli): **103 tests, 1267 assertions, 2 skipped** (los
  dos tests con mysqli mock).
- Docker (con mysqli): **103 tests, 1275 assertions, 0 skipped**.

---

## 9.4. Reactividad ofensiva (revenge mode) (2026-05-11)

### Estado previo

Existían `bot_state.bot_last_attacked_at` y `bot_state.bot_attack_reactive_until`
(escritos por `botDetectRecentAttack` en `scripts/bot_lib/decisions.php`),
pero **solo se usaban para subir las defensas**: `decisions.php:449-451`
aplicaba un `$reactiveBoost = 1.6` al peso defensivo durante las 6 h
posteriores al último ataque. El módulo de ataque ignoraba por completo
esos timestamps.

### Cambios

#### 1. `botDetectRecentAttack` capta también al atacante

`scripts/bot_lib/decisions.php`:
- SQL ahora hace `SELECT fleet_creation, fleet_owner ... ORDER BY fleet_creation DESC LIMIT 1`
  con cláusula adicional `AND fleet_owner <> {$userId}` (defensivo).
- Persiste, además de los dos timestamps existentes:
  - `bot_quirks.last_attacker_user_id` (entero, ID del atacante).
  - `bot_quirks.last_attacker_seen_at` (UNIX ts del fleet más reciente).
- Short-circuit ajustado: la función ya no retorna si solo cambió el
  atacante (mismo `reactive_until` pero atacante distinto → se actualiza).

#### 2. Bonus reactivo decreciente, filtrado por personalidad

`scripts/bot_lib/attack.php`:
- Nueva constante en `safety.php`:
  ```php
  define('BOT_ATTACK_REACTIVE_BONUS_MAX', 2.0);
  ```
- Helper `botAttackHasOffensiveLeaning($profile, $state)`: devuelve
  `true` solo si la psique del bot encaja con la revancha:
  - `bot_personality ∈ {cazador, flotero}`, o
  - `bot_archetype ∈ {turbo, opportunist}`, o
  - `profile.bot_style == 'raider'`.
  Un `minero/turtle` o `defensor/balanced` queda **excluido** y nunca
  recibe el bonus por mucho que le peguen.
- Helper `botAttackReactiveBonus($state, $now)`: bonus puro,
  matemáticamente determinista, sin DB:
  ```
  bonus = BOT_ATTACK_REACTIVE_BONUS_MAX * (1 - elapsed/window)
  elapsed = now - bot_last_attacked_at
  window  = bot_attack_reactive_until - bot_last_attacked_at  (≈ 6 h)
  ```
  Devuelve `0.0` cuando `now >= bot_attack_reactive_until` o si los
  campos están vacíos / inconsistentes.
- `botAttackAggressivenessScore` gana parámetro `?int $now = null` y, al
  final, suma `botAttackReactiveBonus(...)` solo si
  `botAttackHasOffensiveLeaning(...)` es true. El clamp final
  `[0.0, 10.0]` se mantiene intacto.

Efecto típico: un `flotero/opportunist` con `aggressiveness=3` ve su
score pasar de ~4.2 (`moderate`, ratio ≈ 5.0) a ~6.2 (`aggressive`,
ratio ≈ 4.0) justo después de un ataque, decreciendo linealmente hasta
recuperar el valor base 6 h más tarde.

#### 3. Revenge-first reorder de candidatos

`scripts/bot_lib/attack.php`:
- Helper puro `botAttackReorderRevengeFirst(array $candidates, int $attackerUserId)`:
  partición estable que pone primero todas las filas con
  `planet_user_id == $attackerUserId`, preservando el orden relativo
  del resto. Sin SQL extra.
- `botAttackRunPurposeful` lee `bot_quirks.last_attacker_user_id`
  cuando `time() < bot_attack_reactive_until` y aplica el reorden a la
  lista devuelta por `botAttackScanCandidates`. La SQL original se
  queda sin tocar (sigue siendo `ORDER BY planet_id ASC`), así que el
  reorden no introduce dependencia entre `planet_user_id` y el
  planificador de queries.
- Resultado: la primera flota viable del loop apunta al atacante (si
  tiene planetas que cumplen filtros: no banned, no vacación, no
  reservados); si no, cae a los siguientes targets sin perder
  candidatos.

#### 4. Métrica `reactive_attacks_launched`

- Añadida a `$counterKeys` en `botAttackMergeMetrics`.
- `botAttackRunPurposeful` incrementa `metricsDelta['reactive_attacks_launched']`
  por cada `attack: launch` que ocurra mientras
  `time() < bot_attack_reactive_until`. El target NO tiene que ser el
  atacante mismo: es un contador del "modo revancha", no del éxito del
  targeting.

### Tests añadidos

11 tests nuevos en `tests/scripts/BotLibTest.php`:

- `testReactiveBonusIsZeroOutsideWindow`.
- `testReactiveBonusPeaksRightAfterAttack`.
- `testReactiveBonusDecaysLinearlyToZero`.
- `testReactiveBonusReturnsZeroWhenWindowDataIsMissing`.
- `testAggressivenessScoreAppliesReactiveBonusToOffensiveBots`.
- `testAggressivenessScoreIgnoresReactiveBonusForPassiveBots`.
- `testAggressivenessScoreAppliesReactiveBonusToRaiderStyle`.
- `testRevengeReorderMovesAttackerPlanetsToTheFront`.
- `testRevengeReorderIsNoopWhenAttackerHasNoCandidates`.
- `testRevengeReorderIsNoopForZeroAttackerId`.
- `testReactiveAttacksLaunchedAccumulates`.

### Validación in-vivo (Docker)

Forzada con un fleet hostil sintético insertado en `xgp_fleets`
(`fleet_owner=2`, `fleet_target_owner=17`, `fleet_mission=1`,
`fleet_creation = NOW`) seguido de una llamada directa a
`botDetectRecentAttack`. Resultado para tres bots distintos:

| Bot | Persona / Arch | `offensive_leaning` | `reactive_bonus` | `aggr_score` | Comentario |
| --- | --- | --- | --- | --- | --- |
| bot15 (id=17) | flotero / opportunist | yes | ~2.0 | 6.198 | `aggressive` bracket; reorden movió el planeta del atacante al puesto 1 (`candidates_order=11,10,12`). |
| bot4 (id=6) | minero / **turbo** | yes | ~2.0 | 4.0 | El archetype turbo lo califica como ofensivo aunque la personalidad sea minero. |
| bot12 (id=14) | minero / **turtle** | **no** | 2.0 (calculado) | 0.0 | Filtro excluye al bot pasivo: bonus NO aplicado al score. |

En los tres casos, `bot_quirks.last_attacker_user_id=2` se persistió
correctamente. La fila se recarga vía `loadBotState` con el atacante
intacto, confirmando que `saveBotStateFields` con `bot_quirks` JSON
encode/decode funciona.

### Suite

- Host (sin mysqli): **114 tests, 1287 assertions, 2 skipped**.
- Docker (con mysqli): **114 tests, 1295 assertions, 0 skipped**.

### Cómo apagarlo

`BOT_ATTACK_REACTIVE_BONUS_MAX = 0.0` en `safety.php` desactiva el bonus
sin tocar lógica. El reorden de candidatos se desactiva poniendo
`bot_attack_reactive_until` en 0 (o vaciando `last_attacker_user_id`).

---

## 9.5. Inserts de fleets atomizados (2026-05-11)

### Estado previo

Las cuatro funciones que insertan flotas para los bots
(`botAttackInsertSpy`, `botAttackInsertAttack`,
`botColonizationTryColonize` y la rama de transporte en
`botTransportExecutePlan`) ejecutaban **tres queries independientes**
sin transacción:

1. `INSERT INTO {prefix}fleets`.
2. `UPDATE {prefix}ships` para descontar naves/probes del origen.
3. `UPDATE {prefix}planets` para descontar recursos / deuterio.

Si el proceso PHP moría o la conexión MySQL fallaba entre (1) y (2),
quedaba una **flota fantasma**: la fila en `xgp_fleets` sobrevivía pero
las naves del planeta jamás se descontaban (el bot tenía las naves
duplicadas, las que viajan + las que cree tener). Aunque las tablas
involucradas son **InnoDB** (verificado), no se aprovechaba el soporte
de transacciones.

### Helper compartido

Nuevo helper en `scripts/bot_lib/safety.php` (al final del archivo):

```php
botFleetInsertTransactional(
    mysqli $db,
    string $prefix,
    int $sourcePlanetId,
    callable $work  // function(mysqli):?int
): ?int
```

Pipeline:

1. Validación defensiva: `$sourcePlanetId > 0`, si no, retorna null sin
   tocar el closure ni la BD.
2. `$db->begin_transaction()`. Si lanza o devuelve false, **fallback**:
   ejecuta el closure sin transacción (peor caso, igual al comportamiento
   antiguo). Esto evita que el bot loop muera si MySQL está en un estado
   raro.
3. `SELECT 1 FROM xgp_planets WHERE planet_id = X LIMIT 1 FOR UPDATE` y
   lo mismo para `xgp_ships WHERE ship_planet_id = X`. Bloquea las filas
   del planeta origen y sus naves: cualquier otro worker (otro proceso
   del bot, o el motor del juego procesando una flota que vuelve al
   mismo planeta) se serializa hasta el commit/rollback.
4. Invoca el closure (`$work($db)`).
5. Si el closure devuelve un `int > 0`: `commit` y retorna ese id.
6. Si el closure devuelve `null` / `<=0`: `rollback` y retorna null.
7. Si el closure lanza una excepción: `rollback`, se traga la excepción
   y retorna null.

Los dobles try/catch evitan que un fallo en el `rollback` (conexión
muerta, p.ej.) propague una excepción al bot loop.

### Conversión de los 4 callers

Cada caller refactorizado:

- Mueve sus 3 queries dentro de un `function (mysqli $db) use (...) {}`
  pasado como `$work`.
- El closure devuelve `(int) $db->insert_id` tras el INSERT y `null` si
  alguna de las queries posteriores falla.
- Los 4 callers ahora respetan la regla "ningún side-effect parcial":
  si un `UPDATE ships` o `UPDATE planets` retorna falso, la closure
  devuelve null y el helper hace rollback del INSERT.
- `botColonizationTryColonize` y `botTransportExecutePlan` mantienen su
  firma `?string` (devuelven la línea de log o null); el `$fleetId`
  devuelto por el helper se usa solo como flag de éxito.

### Tests añadidos

6 tests unitarios para el helper (con `mysqli` mock,
`markTestSkipped()` cuando la extensión no está cargada):

1. `testFleetInsertTransactionalCommitsOnHappyPath` — closure
   devuelve `42`, helper hace `commit`, retorna 42.
2. `testFleetInsertTransactionalRollsBackWhenClosureReturnsNull`
   — null del closure → `rollback`, helper retorna null.
3. `testFleetInsertTransactionalRollsBackWhenClosureReturnsZero`
   — 0 del closure (caso borde) → `rollback`, helper retorna null.
4. `testFleetInsertTransactionalRollsBackWhenClosureThrows`
   — `RuntimeException` del closure → `rollback`, helper retorna null,
   no propaga.
5. `testFleetInsertTransactionalFallsBackWhenBeginRefuses`
   — `begin_transaction` retorna false → ejecuta closure sin tx,
   nunca llama `commit` ni `rollback`, devuelve lo del closure.
6. `testFleetInsertTransactionalRejectsNonPositiveSourcePlanetId`
   — `$sourcePlanetId = 0` → closure NO se invoca, retorna null
   (defensivo: un FOR UPDATE sobre planet_id=0 no tendría sentido).

### Validación in-vivo (Docker)

Script ad-hoc (`scripts/_dev_tx_check.php`, ya eliminado) que ejerció
tres rutas reales sobre `bot2` (planet_id=28) contra Legolass:

| Parte | Closure devuelve | Resultado esperado | Resultado real |
| --- | --- | --- | --- |
| Happy path (`botAttackInsertSpy`) | fleet_id > 0 | INSERT + UPDATEs persistidos | fleet_id=297, probes 71→67 (−4), fleets +1 |
| Closure devuelve null tras INSERT | null | INSERT rollback, fila no persiste | fleets Δ=0 |
| Closure lanza excepción tras INSERT | throws | INSERT rollback, fila no persiste | fleets Δ=0 |

Tras los tres tests las naves y deuterio del planeta quedaron en su
valor original (verificado con `SELECT`). La excepción del PART 3 fue
absorbida silenciosamente por el helper, el script continuó.

### Suite

- Host (sin mysqli): **120 tests, 1297 assertions, 8 skipped** (los 6
  tests del helper + 2 previos de harvest-only).
- Docker (con mysqli): **120 tests, 1309 assertions, 0 skipped**.

### Notas

- Coste por insert: 2 queries extra (`SELECT FOR UPDATE` × 2) y un
  round-trip de `begin/commit`. Despreciable para la frecuencia con la
  que se lanzan flotas.
- `botAttackHarvestOnly` y `botAttackSaveCooldowns` NO se envolvieron:
  ya consisten en una sola query (`saveBotStateFields`) que es atómica
  per se.
- El helper no añade un `SELECT` para releer el row con sus valores
  actualizados — los callers siguen usando el cache en memoria de
  `$source`. Eso significa que el FOR UPDATE protege contra que otra
  transacción modifique el row, pero el caller puede aún sobre-restar
  (el `GREATEST(0, col - n)` ya lo cubre). Si en el futuro se quiere
  rigor absoluto, se puede leer el row dentro del closure tras el
  bloqueo; ahora mismo no parece necesario.

---

## 9.6. Observación del ciclo completo: loot y losses reales (2026-05-11)

### Motivación

El bot estimaba el loot ANTES de lanzar el ataque (`botAttackEstimateLoot`)
pero nunca medía lo que realmente acababa entrando al planeta. Si el
estimador era demasiado optimista (capacidad de carga limitada, defensas
inesperadas, llegada simultánea de otro atacante humano que vacía el
target primero) no nos enterábamos: las métricas hablaban del loot
*lanzado*, no del *acreditado*.

Además, el flujo "el motor real procesa la fleet de attack y devuelve
recursos+naves" estaba validado solo por inspección de código. Faltaba
una prueba end-to-end empírica.

### Flujo del motor (resumen)

`app/Libraries/Missions/Attack.php::attackMission()` corre en dos fases:

1. **Llegada** (`fleet_mess=0` y `fleet_start_time <= time()`): la batalla
   se simula, `updateAttackers()` llena `fleet_resource_metal/crystal/
   deuterium` con el loot y reescribe `fleet_array` con las naves
   supervivientes (`FleetsLib::setFleetShipsArray()` = `serialize()`).
   Luego `returnFleet()` voltea coords y pone `fleet_mess=1`.
2. **Vuelta** (`fleet_mess=1` y `fleet_end_time <= time()`):
   `parent::restoreFleet()` lee `fleet_array` con `unserialize()`, suma
   cada ship_id al planeta origen, suma `fleet_resource_*` a
   `planet_metal/crystal/deuterium` y luego `removeFleet()` borra la
   fila.

El bot escribe `fleet_array = serialize([shipId => count, ...])` y el
motor lo lee con `unserialize()`. **Formato compatible**, confirmado por
inspección y por test in-vivo (ver más abajo).

### Implementación

**`bot_quirks.attacks_in_flight[fleet_id]`** mantiene por cada flota
lanzada:

```json
{
  "launched_at":         1778491239,
  "expected_arrival":    1778494839,
  "expected_return":     1778498439,
  "ship_mix":            {"204": 20, "203": 20},
  "loot_estimated":      1500000,
  "ship_value_launched": 320000,
  "status":              "outbound|returning",
  "target_planet_id":    13,
  "loot_real":           501000,            // cuando pasa a returning
  "ship_mix_returning":  {"204": 20, "203": 20}
}
```

**`botAttackHarvestReturns($db, $prefix, &$state, $pricelist, ?$now)`**
(en `scripts/bot_lib/attack.php`) es el nuevo helper. Se llama al inicio
de cada loop (`botAttackRunPurposeful`) y también desde
`botAttackHarvestOnly` durante las ventanas de sueño. Para cada
`fleet_id` en `attacks_in_flight`:

- **Row presente + `fleet_mess=1` + status=outbound** → transición a
  `returning`: captura `loot_real` (suma de `fleet_resource_*`) y
  `ship_mix_returning` (`unserialize(fleet_array)`).
- **Row ausente + status=returning** → ciclo cerrado por el motor.
  Acumula:
  - `metrics.attacks_completed += 1`
  - `metrics.total_loot_returned += loot_real`
  - `metrics.real_ships_lost_value += max(0, ship_value_launched - value(ship_mix_returning))`
  - Drop de la entrada.
- **Row ausente + status=outbound** → polleamos tarde (carrera perdida).
  `metrics.attacks_completed_no_data += 1`. Drop.
- **Row presente + status=returning** → todavía volando. Lo dejamos.
- **TTL paranoide**: si una entrada lleva más de `expected_return + 1h`
  sin completar (por ejemplo el row fue borrado a mano fuera del flujo
  del motor, o quedó zombi por bugs antiguos), se descarta como
  `no_data` para evitar acumulación indefinida.

### Registro en `botAttackRunPurposeful`

Después de `botAttackInsertAttack` exitoso, el bot ahora también guarda
la entrada en `attacks_in_flight`. `ship_value_launched` se calcula con
`botCombatLossesValue($shipMix, $pricelist)` para que `losses` reales
sean comparables al delta `launched - returned` en la misma unidad
(coste de construcción total).

### Tests unitarios (6)

En `tests/scripts/BotLibTest.php`:

- `testHarvestReturnsEmptyInFlightIsNoop`: sin entradas, no se hace
  ninguna query.
- `testHarvestReturnsTransitionsOutboundToReturning`: row con
  `fleet_mess=1` ⇒ status pasa a `returning`, `loot_real` y
  `ship_mix_returning` capturados; las métricas de cierre NO se mueven
  aún.
- `testHarvestReturnsRowGoneCompletesReturningWithLosses`: status era
  `returning` y la fila desaparece ⇒ acumula `attacks_completed=1`,
  `total_loot_returned`, `real_ships_lost_value` (delta de valor),
  entrada borrada.
- `testHarvestReturnsRowGoneOutboundCountsNoData`: status era
  `outbound` y la fila desaparece ⇒ acumula `attacks_completed_no_data`,
  no loot.
- `testHarvestReturnsTTLDropsStaleStillPresentRow`: `expected_return + 1h`
  ya pasó ⇒ drop con `attack: drop ... (stale_in_flight)`.
- `testHarvestReturnsNeverNegativeLossesIfShipsExceedLaunched`:
  `real_ships_lost_value` se clamp a `max(0, ...)`, nunca negativo.

Suite total: **126 tests, 1334 assertions, todas verdes** en docker.

### Validación in-vivo (Docker)

Script ad-hoc (`scripts/_dev_returns_check.php`, dejado como evidencia)
ejecuta el ciclo completo bot15 → granjero[planeta 13] (`1:2:11`) con
`MissionControlLib::arrivingFleets()` y `returningFleets()` forzados
modificando timestamps. Salida real:

```
[returns_check] === STEP 0: snapshot start ===
[returns_check] bot15 planet41 BEFORE | metal=71605970 crystal=54039772
                deuterium=21491777 light_fighter=50 big_cargo=40
[returns_check] === STEP 1: build attack fleet ===
[returns_check] attack mix: ships={"204":20,"203":20} target=1:2:11
[returns_check] inserted fleet_id=301
[returns_check] attacks_in_flight registered: [300,301]
[returns_check] === STEP 2: force fleet_start_time to past, run arrivingFleets() ===
[returns_check] after arrival: fleet_mess=1 loot M=167000 C=167000 D=167000
                survivors={"204":20,"203":20}
[returns_check] === STEP 3: harvest returns (outbound -> returning) ===
[returns_check] attack: returning fleet=301 loot=501000
[returns_check] === STEP 4: force fleet_end_time to past, run returningFleets() ===
[returns_check] fleet row gone (engine credited loot+ships back)
[returns_check] bot15 planet41 AFTER  | metal=71785874 crystal=54213271
                deuterium=21660796 light_fighter=50 big_cargo=40
[returns_check] DELTA resources: metal=179904 crystal=173499 deuterium=169018
[returns_check] === STEP 5: harvest returns (returning -> completed) ===
[returns_check] attack: completed fleet=301 loot=501000 losses=0
[returns_check] metrics after completion:
[returns_check]   attacks_completed         = 1
[returns_check]   attacks_completed_no_data = 0
[returns_check]   total_loot_returned       = 501000
[returns_check]   real_ships_lost_value     = 0
```

Observaciones:

- El motor procesa correctamente el `serialize()` que escribe el bot
  (naves vuelven intactas al planeta origen).
- El delta de recursos del planeta del bot (522421 totales) coincide
  con `loot_real` (501000) más unos ~21k de producción contínua entre
  los dos snapshots: acreditación correcta.
- `botAttackHarvestReturns` observa con éxito ambas transiciones y
  acumula las 3 métricas nuevas. `real_ships_lost_value=0` porque
  granjero no tiene defensas; en un target real con flota/defensa, el
  delta se reflejaría con el coste de construcción total de las naves
  perdidas.

### Notas

- El target del test fue `granjero` (user_id=2), planeta no-homeworld
  con miles de millones en recursos y 0 naves / 0 defensas en sus
  planetas secundarios: riesgo de destruir progreso humano = nulo.
- El cleanup quedó manual (decisión del usuario): el script y los
  efectos en bot15 se dejan como evidencia visible. El motor borrará
  la fleet 300 residual cuando otro request del juego dispare
  `returningFleets()`.
- `botAttackHarvestOnly` también llama al harvest de returns para no
  perder mediciones si un fleet vuelve durante la ventana de sueño.
  Recibe `pricelist` por parámetro; si no se pasa (uso desde tests
  unitarios), se skip silenciosamente.

---

## 9.7. Recheck pre-ataque (two-phase commit) (2026-05-11)

### Motivación

`BOT_INTEL_TTL_SECONDS = 30 min`. Entre que el bot decidía atacar y la
flota llegaba (típicamente 1-5 min más), el target podía haber:

- Construido defensas que invalidan la simulación.
- Lanzado un fleetsave que vacía los recursos.
- Cambiado completamente la composición de su flota.

El bot atacaba con datos hasta 30 minutos antiguos y se llevaba palos
silenciosos. Sin observabilidad, no sabíamos cuántas raids fallaban por
intel obsoleto.

### Diseño: two-phase commit con sonda real

Cuando una decisión de ataque pasa **todos** los filtros del branch
"fresh intel" (sim, ratio, loot, fuel), el bot ahora NO lanza la flota
de inmediato. Lo que hace es:

1. **Arma un plan**: envía una sonda fresca al target (gasta probes
   reales, viaja como cualquier otra sonda) y registra la decisión
   completa en `bot_quirks.pending_attacks[target_planet_id]`.
2. **Reserva las naves**: el `ship_mix` del plan queda restado del
   `shipsByPlanet` local durante el loop, de modo que el mismo bot no
   pueda comprometer esas naves a otro plan/ataque hasta que el primer
   plan se resuelva.
3. **Espera a la sonda**: en los loops siguientes, mientras
   `recheck_arrival > now()`, el plan queda visible pero inactivo.
4. **Resuelve cuando llega**: el helper `botAttackHandlePendingPlans`,
   llamado al inicio de cada loop (paso 2.5 de
   `botAttackRunPurposeful`), encuentra los planes con sonda ya llegada
   y por cada uno:
   - Lee `botAttackReadTargetSnapshot()` fresco (single JOIN query).
   - Refresca `bot_quirks.intel[target_pid]` con `captured_at = now()`
     (incluso si el plan acaba abortando — el dato nuevo es valioso).
   - Recomputa `botAttackEstimateLoot`, `botCombatSimulate`, fuel y
     `ratio`.
   - **CONFIRMA y lanza** vía `botAttackInsertAttack` si pasa los tres
     gates (sim no perdedor, loot ≥ min, ratio ≥ threshold del plan).
     Las naves ya estaban "consumidas" en memoria, el INSERT
     transaccional las consume físicamente.
   - **ABORTA** en otro caso, devolviendo las naves al
     `shipsByPlanet` para que la misma vuelta del loop pueda usarlas
     en otro plan/ataque.

### Plan layout (`bot_quirks.pending_attacks[target_pid]`)

```json
{
  "armed_at":         1778492200,
  "recheck_fleet_id": 358,
  "recheck_arrival":  1778492230,
  "source_planet_id": 41,
  "ship_mix":         {"204": 20, "203": 10},
  "ratio_threshold":  5.0,
  "min_loot":         50000,
  "estimated_loot":   5000000,
  "estimated_losses": 1000,
  "target_planet_id": 13
}
```

`ratio_threshold` se congela al armar para que oscilaciones de la
agresividad del bot (revenge mode, cambios de personalidad) durante el
viaje de la sonda no provoquen comportamiento errático. La decisión
toma efecto bajo las condiciones del momento del armado.

### Razones de abort (todas son métricas separadas)

| Métrica | Cuándo |
| --- | --- |
| `attacks_aborted_no_target` | El planeta target ya no existe (vacaciones / borrado). |
| `attacks_aborted_no_source` | El planeta source del plan ya no está en `planets[]` (raro: cambio de configuración). |
| `attacks_aborted_low_loot` | El nuevo snapshot tiene `loot < min_loot`. El target fleetsaveó. |
| `attacks_aborted_sim_lose` | La nueva simulación da `defender` o `draw`. Salieron defensas. |
| `attacks_aborted_low_ratio` | `(netLoot − fuel) / my_losses < ratio_threshold`. Ya no rentable. |
| `attacks_aborted_stale_plan` | Más de `BOT_ATTACK_PLAN_TTL_SECONDS` (6h) desde el armado, sin haber llegado la sonda. Paranoid TTL. |

Además se mantienen los contadores `attacks_armed` (planes creados) y
`attacks_recheck_confirmed` (planes que pasaron el recheck y se
lanzaron). La relación entre ambos es la métrica principal de
"viability del intel viejo":

```
confirm_rate = attacks_recheck_confirmed / attacks_armed
```

Un confirm_rate < 0.5 indicaría que el bot pasa más tiempo armando que
atacando — síntoma de intel demasiado viejo en general.

### Constante de configuración

`BOT_ATTACK_PLAN_TTL_SECONDS = 6 * 3600` (definida en
`scripts/bot_lib/safety.php`). Cualquier plan más viejo que eso se
descarta como `stale_plan` y libera sus naves reservadas. Solo cubre
casos patológicos (sonda perdida, bot offline largo tiempo, target
borrado entre armado y handler).

### Naves reservadas

Cuando el plan está armado:

- `shipsByPlanet[source_pid][ship_id] -= ship_mix[ship_id]` al inicio
  de cada loop (en `botAttackRunPurposeful`) para que el mismo loop no
  reutilice las naves para otro plan/ataque.
- Si el plan se ABORTA, el handler las devuelve a `shipsByPlanet` en
  ese mismo loop. Si se CONFIRMA, el INSERT atómico
  (`botFleetInsertTransactional`) las consume físicamente en la BD; en
  memoria ya estaban restadas por la reserva, así que no se
  doble-cuentan.
- `attack_cooldowns[source_pid] = now` se aplica al armar para
  bloquear nuevos planes del mismo planeta durante un rato; al
  confirmar también, para que un mismo source no encadene 2 attacks
  consecutivos en el mismo loop.

### Skip de targets ya en plan

Al inicio del loop se snapshot-ea `targetsLockedThisLoop` con los
`target_planet_id` que tenían un plan armado. Aunque el handler resuelva
el plan (confirme o aborte) en el paso 2.5, el target queda excluido
del scan de candidates posterior: no queremos re-spy ni re-arm el
mismo planeta en el mismo loop.

### Tests unitarios (7)

En `tests/scripts/BotLibTest.php`:

- `testHandlePlansEmptyIsNoop`: sin planes, no se hacen queries.
- `testHandlePlansWaitsBeforeRecheckArrival`: `recheck_arrival > now`,
  plan permanece.
- `testHandlePlansAbortsOnStalePlan`: `armed_at + TTL < now`, abort +
  ships liberadas.
- `testHandlePlansAbortsOnNoSourcePlanet`: planeta source no está en
  `planets[]`.
- `testHandlePlansAbortsOnLowLoot`: snapshot devuelve recursos
  insignificantes, intel se refresca igual.
- `testHandlePlansAbortsOnSimDefenderWins`: 200 plasma turrets contra
  50 small cargos → sim defender wins.
- `testHandlePlansRespectsMaxAttacksThrottle`: si `attacksDoneSoFar >=
  maxAttacks`, plan queda esperando otro loop.

Suite total: **133 tests, 1363 assertions, todas verdes** en docker.

### Validación in-vivo (Docker)

Script ad-hoc `scripts/_dev_recheck_check.php` ejecuta tres escenarios
secuenciales en granjero[planeta 13] (`1:2:11`):

```
=== SCENARIO A: happy path -> arm -> recheck -> CONFIRM ===
plan armed, recheck_fleet=358, recheck_arrival_eta=1s
arrivingFleets() processed recheck spy
attack: confirmed 1:2:11 eta=47s loot=250721 losses=1 ratio=250721.00
attacks_confirmed=1
metrics delta: {"attacks_recheck_confirmed":1, "attacks_launched":1, ...}

=== SCENARIO B: target with new defenses -> recheck -> ABORT (sim_lose) ===
ships available after reservation: {"203":20,"204":0,...}  ← reservadas
attack: aborted 1:2:11 (sim_lose my_losses=360000)
ships after handler (should be back to full): {"203":40,"204":30,...}  ← liberadas
intel[target] captured_at=1778492273  ← intel refrescado

=== SCENARIO C: target emptied (low_loot) -> recheck -> ABORT ===
attack: aborted 1:2:11 (low_loot=15 < min=50000)

=== FINAL METRICS ===
attacks_recheck_confirmed = 1
attacks_aborted_sim_lose  = 1
attacks_aborted_low_loot  = 1
```

Las tres rutas (CONFIRM, ABORT-sim, ABORT-loot) funcionan
end-to-end. Las naves reservadas se liberan correctamente tras abort.
El intel se refresca incluso en abort. Métricas persistidas en
`bot_quirks.metrics`.

### Notas y trade-offs

- **Coste por ataque**: el bot ahora gasta sondas adicionales (una
  recheck por cada decisión). Si el intel inicial no genera muchas
  decisiones positivas, el overhead es cero. Si todas las decisiones
  positivas se traducen en attacks, se duplica el coste de probes pero
  se elimina el riesgo de raid perdido por intel obsoleto.
- **Delay introducido**: entre armar y lanzar pasan típicamente 30s-5m
  (lo que tarda la sonda). En ese tiempo el target puede fleetsavear, y
  el abort hará su trabajo. Si fleetsavea DESPUÉS del recheck pero
  antes de que llegue la flota de attack, el bot perderá la oportunidad
  (sin cambios respecto al diseño anterior).
- **No aplicable en sleep window**: durante harvest-only el bot no arma
  planes. Si tenía planes armados al entrar en sleep, el TTL paranoid
  los limpia.
- **Reactividad ofensiva (sección 9.4)** sigue funcionando: el bonus
  de aggressiveness afecta al `ratio_threshold` que se congela al
  armar; si el bot deja de estar "reactive" durante el viaje de la
  sonda, el plan original mantiene el threshold permisivo. Esto es
  intencional: "te comprometiste a este ataque bajo este criterio".

---

## 9.8. Presión de carga → big cargos prioritarios (2026-05-11)

### Problema observado

En la prueba in-vivo posterior al ciclo de clones del granjero, el bot
`bot2` (user_id=4, flotero) acumulaba **7420** skips por
`attacks_skipped_capped_loot` mientras espiaba decenas de planetas con
recursos en el orden de los miles de millones. Por la composición de su
flota (5 small cargos + 205 light fighters) su capacidad real de raid
era 20 000, por debajo de `BOT_ATTACK_MIN_LOOT_ESTIMATE = 50 000`.
Resultado: el bot quiere atacar y descarta blanco tras blanco porque
"no le entra en el maletero", pero su módulo de build nunca priorizaba
big cargos (id 203) — sólo small cargos (id 202), drills y combat.

### Decisión

Cuando el módulo de ataque salta una candidatura por `cap loot < min`,
*publica una señal* en `bot_quirks.cargo_pressure`. El módulo de build
la consume para añadir **big cargos** como candidato de alta prioridad
durante el TTL. La señal expira sola si dejan de aparecer skips por
capacidad: no requerimos limpieza manual.

### Implementación

**Constantes nuevas (`scripts/bot_lib/safety.php`)**:

- `BOT_CARGO_PRESSURE_TTL_SECONDS = 6 * 3600` (6 h).
- `BOT_CARGO_PRESSURE_MIN_SKIPS_TO_PRIORITIZE = 1` (un solo skip ya
  activa la prioridad).
- `BOT_CARGO_PRESSURE_BIG_CARGO_TARGET = 20` (objetivo blando: 20 big
  cargos cuando la presión está activa; suficiente para cubrir 500k
  de loot).

**Helpers nuevos (`scripts/bot_lib/safety.php`)**:

- `botCargoPressureMark(array &$state, int $now)`: incrementa
  `bot_quirks.cargo_pressure.skips`, refresca `expires_at`. Si el TTL
  anterior ya había expirado, reinicia `skips` a 1.
- `botCargoPressureActive(array $state, int $now): bool`: devuelve
  true si hay una entrada válida no expirada con `skips ≥` el umbral.

**Shape en `bot_quirks.cargo_pressure`**:

```json
{
  "skips": 7,
  "last_signal_at": 1778500000,
  "expires_at": 1778521600
}
```

**Emisión (`scripts/bot_lib/attack.php`)**:

Justo después de incrementar la métrica `attacks_skipped_capped_loot`
en `botAttackRunPurposeful` (rama "cap loot < min"), se llama
`botCargoPressureMark($state, $now)`. La persistencia se aprovecha de
`botAttackSaveCooldowns`, que lee `$state['bot_quirks']` íntegro y lo
vuelve a escribir; los demás campos del JSON se conservan.

**Consumo (`scripts/bot_lib/decisions.php` / `botShipCandidates`)**:

Justo después del candidato de small cargo (id 202), se evalúa la
presión:

```php
$cargoPressure = botCargoPressureActive($state, $now);
if (isset($planet['ship_big_cargo_ship'])
    && $cargoPressure
    && $bigCargoCur < BOT_CARGO_PRESSURE_BIG_CARGO_TARGET
    && $bigCargoCur < BOT_CAP_UNIT_PER_TYPE) {
    // weight = 3.0 * archetype_eco_mul (×1.4 si rol support/main,
    //          ×1.2 si focus = mil)
    $candidates[] = [...id 203...];
}
```

Pesos resultantes (validados in-vivo con `bot2`, planeta vacío de big
cargo, sin overrides):

```
ship_big_cargo_ship   weight=4.20  (más alto de la lista)
ship_small_cargo_ship weight=1.44
ship_light_fighter    weight=0.96
ship_heavy_fighter    weight=0.64
ship_cruiser          weight=0.51
ship_battleship       weight=0.32
ship_espionage_probe  weight=0.26
```

### Por qué este diseño

- **Selectivo**: sin presión, big cargo **no aparece** en la lista de
  candidatos. Mineros y defensores no construyen big cargos porque no
  generan la señal. Sólo bots con `flotero/cazador` (los únicos que
  pasan `botAttackHasOffensiveLeaning`) llegan a entrar en
  `botAttackRunPurposeful` y por tanto los únicos que disparan la
  señal.
- **Auto-desactivable**: la señal se renueva en cada skip y expira a
  las 6 h sin nuevos skips. Si el bot consigue suficiente capacidad
  para empezar a atacar (loot real ≥ min), la rama "cap loot < min"
  deja de dispararse y la presión decae sola.
- **Compatible con el ciclo two-phase commit (sección 9.7)**: el skip
  por cap_loot se emite *antes* de armar un plan, así que la presión
  se publica también para targets que aún no llegan a esa fase.
- **No interfiere con focus/personality**: se respetan los
  multiplicadores existentes (`archetypeMul['eco']`, `role`, `focus`),
  sólo se inyecta una entrada nueva con peso base 3.0 que, combinada
  con el ×1.4 de `role==main/support`, llega a 4.2 — el doble del
  candidato más alto sin presión.

### Tests añadidos

`tests/scripts/BotLibTest.php`:

- `testBotCargoPressureMarkSetsState` — marca + lectura inicial.
- `testBotCargoPressureMarkAccumulatesWithinWindow` — 2º skip dentro
  del TTL incrementa `skips` y refresca `expires_at`.
- `testBotCargoPressureMarkResetsAfterExpiry` — si el TTL anterior ya
  expiró, `skips` arranca de 1 (no acumula con la ventana muerta).
- `testBotCargoPressureActiveRespectsTtl` — `active=true` justo
  después del mark, `active=false` después del TTL.
- `testBotShipCandidatesIncludesBigCargoUnderPressure` — con presión
  activa, el array de candidatos contiene id=203.
- `testBotShipCandidatesOmitsBigCargoWithoutPressure` — sin presión,
  el array de candidatos NO contiene id=203.

Suite completa: **154 tests, 1399 assertions, OK**.

### Validación in-vivo

Script ad-hoc (ya borrado por higiene) con `bot2`:

1. Lee bot_state real.
2. Inyecta `botCargoPressureMark` en memoria (sin persistir).
3. Ejecuta `botDecideAction` 200 veces y cuenta picks por columna.

Resultado:
- Con storage al 90 % (caso real, threshold 0.90): 200/200 → storage
  override (el bot no llegaba al módulo de ships en el momento de la
  prueba; este caso es justo lo que arregla 9.8.1).
- Forzando storage bajo: **11/200 big_cargo + 7/200 small_cargo**.
- Pesos crudos: big cargo 4.20, small cargo 1.44, combat ≤ 0.96.

### Limitaciones conocidas (parcialmente resueltas en 9.8.1)

- **La presión sólo se publica desde `botAttackRunPurposeful`**.
  Bots sin flota mínima que no llegan al módulo de ataque (porque
  todavía no tienen sondas o cargos para iniciar el ciclo) tampoco
  generan la señal. Es coherente: si no hay ciclo de ataque, no hay
  problema de carga que resolver.
- **Sólo se construye en el HW por defecto** → arreglado parcialmente
  en 9.8.1: ahora se filtra por rol del planeta. Pure-mining roles
  (`metal`, `crystal`) sólo aceptan big cargos si el planeta ya
  tiene flota (cargos o naves de combate).
- **Requiere personalidad ofensiva**. Sin cambios: sólo los bots que
  llegan a `botAttackRunPurposeful` con candidatos válidos pueden
  emitir la señal.

---

## 9.8.1. Refinamientos a la presión de carga (2026-05-11)

Bloque de mejoras añadido tras revisar el módulo en producción.

### (1) Visibilidad: panel `botstats`

- Nueva columna **"Presión carga"** entre `Entradas intel` y las
  métricas. Muestra badge `activa ×N / expira HH:MM` (warning) si
  hay `bot_quirks.cargo_pressure` válida (skips > 0 y no expirada),
  badge `expirada ×N` (gris) si ya pasó la ventana, y `—` si nunca
  hubo señal.
- Dos métricas nuevas en el bloque de columnas existente:
  - `cargo_pressure_signals` ("Skips por carga"): contador vitalicio
    que se incrementa en cada llamada a `botCargoPressureMark`. NO
    se resetea con la ventana; sirve para detectar bots crónicamente
    sin carga.
  - `big_cargos_queued_under_pressure` ("Big cargos (presión)"):
    suma de big cargos efectivamente encolados al hangar **mientras
    la presión estaba activa**. Diagnóstico directo: si
    `cargo_pressure_signals` crece pero esta no, algo bloquea la
    construcción (energía, almacén, cola llena, etc.).

Archivos tocados:
- `app/Http/Controllers/Adm/BotstatsController.php` (columna + metric
  list).
- `resources/views/adm/botstats_view.php` (nueva `<th>`).
- `resources/lang/{spanish,english}/adm/botstats_lang.php`
  (`bs_col_cargo_pressure`, `bs_cp_active/expired/expires` y los dos
  `bs_metric_*` nuevos).

### (2) Suavizar override de almacén bajo presión

Problema: con `storage_panic_threshold = 0.9` (default) cualquier
planeta lleno al 91 % bloqueaba la cadena de candidatos y el bot
nunca llegaba a encolar big cargos a pesar de tener la presión activa.

Solución: en `botStorageOverride` se eleva el threshold local a
`max($panic, 0.98)` cuando `botCargoPressureActive($state, time())`.
Esto evita el bloqueo en el rango típico [0.90, 0.98] pero mantiene
la red de seguridad en near-overflow (≥ 0.98) para no perder
producción durante horas si la cola tarda en drenar.

Tests:
- `testStorageOverrideSoftenedUnderCargoPressure` — 0.95 fill con
  presión NO dispara override.
- `testStorageOverrideStillFiresOnNearOverflowEvenUnderPressure` —
  0.99 fill sí dispara, aunque haya presión.

### (3) Role-gating para big cargos

Problema: la versión anterior añadía big cargo como candidato en
cualquier planeta que tuviera la columna y la presión activa. Un bot
con 8 planetas y rol `metal` en 4 de ellos los llenaba de big cargos
inútiles (no son launch pads).

Solución (en `botShipCandidates`): big cargo entra al pool si y sólo
si:
1. El rol es `main`, `military`, `industrial` o `support`. O
2. El rol es `metal`/`crystal` pero el planeta ya tiene **alguna**
   nave de carga o combate (`ship_small_cargo + ship_big_cargo +
   ship_light_fighter + ship_heavy_fighter + ship_cruiser +
   ship_battleship > 0`). En la práctica esto deja pasar planetas
   mineros que ya están siendo usados como hub auxiliar.

Pesos ajustados:
- Rol `main` o `military`: × 1.6 (era × 1.4 sólo `support/main`).
- Rol `support`: × 1.4.
- Rol `industrial`: × 1.2.
- Demás roles permitidos: × 1.0 base.

Tests:
- `testBotShipCandidatesGatesBigCargoOnPureMinerWithoutFleet` —
  planet rol `metal` sin naves NO emite candidato.
- `testBotShipCandidatesAllowsBigCargoOnMinerWhenAlreadyArmed` —
  planet rol `metal` con 10 small cargos SÍ emite candidato.

### (4) Fleet slot gating (no atacar si no hay slot)

Problema: el bot puede tener intel + raid mix + ratio favorable pero
si su `computer_technology = N` limita a `N+1` flotas activas y ya
está en el tope (transports, colonization, attacks in flight), el
INSERT del motor rechazaría la nueva flota. Antes el bot intentaba
de todos modos y desperdiciaba la transacción.

Helper nuevo `botAttackFleetSlotsInfo($db, $prefix, $userId, $research)`
en `attack.php`: ejecuta `SELECT COUNT(*) FROM xgp_fleets WHERE
fleet_owner = ?` y compara contra `1 + research_computer_technology`
(misma fórmula que `FleetsLib::getMaxFleets` para humanos, con
admiral = 0 para bots).

Wire-up en `botAttackRunPurposeful`:
1. Lee `$freeSlots` al entrar.
2. Después del handler de planes pendientes, descuenta el número de
   confirmaciones (cada una insertó un attack fleet).
3. Antes de **enviar sonda regular**: si `$freeSlots <= 0`, salta
   con métrica `spies_skipped_no_slot`.
4. Antes de **armar un plan** (que dispara una sonda recheck): mismo
   gate, con métrica `attacks_skipped_no_slot`.
5. En cada insert exitoso (sonda o sonda recheck): `$freeSlots--`.
6. Log "idle no_fleet_slots" + métrica `fleet_slot_full_events` si
   se entra con 0 slots libres al inicio del loop.

Tests:
- `testBotAttackFleetSlotsInfoComputesFree` — `used=3, computer=5 →
  free=3, max=6`.
- `testBotAttackFleetSlotsInfoClampsFreeAtZero` — used > max →
  free=0.

### Métricas nuevas (resumen)

| Métrica | Cuándo se incrementa |
|---|---|
| `cargo_pressure_signals` | en cada `botCargoPressureMark` (vitalicio) |
| `big_cargos_queued_under_pressure` | tras `applyPlanetAction` cuando se encola big cargo con presión activa |
| `fleet_slot_full_events` | una vez por loop si el bot entra sin slots |
| `spies_skipped_no_slot` | sonda regular abortada por slot cero |
| `attacks_skipped_no_slot` | armar plan abortado por slot cero |

### Tests añadidos a `tests/scripts/BotLibTest.php`

8 tests nuevos: `testCargoPressureMarkIncrementsLifetimeMetric`,
`testCargoPressureRecordQueuedAccumulates`,
`testBotShipCandidatesGatesBigCargoOnPureMinerWithoutFleet`,
`testBotShipCandidatesAllowsBigCargoOnMinerWhenAlreadyArmed`,
`testStorageOverrideSoftenedUnderCargoPressure`,
`testStorageOverrideStillFiresOnNearOverflowEvenUnderPressure`,
`testBotAttackFleetSlotsInfoComputesFree`,
`testBotAttackFleetSlotsInfoClampsFreeAtZero`.

Suite completa: **162 tests / 1410 assertions, OK**.

### Higiene (cleanup)

Borrados los scripts one-off de evidencia que quedaron de sesiones
anteriores:
- `scripts/_dev_returns_check.php` (validación de loot real, 9.6).
- `scripts/_dev_cargo_pressure_check.php` (validación inicial, 9.8).

---

## 10. Pendientes / Próxima sesión

> Cosas que NO se hicieron en esta sesión y que probablemente se quieran
> abordar después:

- [x] ~~**Validación in-vivo en Docker**~~ → hecho 2026-05-11 (ver 9.1).
- [x] ~~**Limpieza periódica de `bot_quirks`**. Si la entrada `intel`
      crece (un bot con muchos vecinos), considerar truncar a las N
      más recientes en `botAttackSaveCooldowns`.~~ → hecho 2026-05-11
      (`BOT_INTEL_MAX_ENTRIES` + `botAttackTruncateIntelByRecency`, ver 9.2).
- [x] ~~**Considerar reactividad ofensiva**. Hoy
      `bot_last_attacked_at`/`bot_attack_reactive_until` no influyen en
      `botAttackAggressivenessScore`. Podría sumar un +1.5 temporal
      cuando el bot fue atacado recientemente (revancha).~~ → hecho
      2026-05-11 (sección 9.4). Bonus decreciente +2.0 → 0, filtrado por
      personalidad ofensiva, reorden revenge-first y métrica
      `reactive_attacks_launched`.
- [x] ~~**Race condition al insertar fleets**. Como `transport.php`,
      el INSERT no está envuelto en transacción. Si más adelante se ven
      caídas raras de probes/ships sin fleet correspondiente, considerar
      `START TRANSACTION ... COMMIT`.~~ → hecho 2026-05-11 (sección 9.5).
      Helper `botFleetInsertTransactional` con `SELECT FOR UPDATE` aplicado
      a los 4 insertos (`attack`, `colonization`, `transport`).
- [x] ~~**Ataques nocturnos / ventanas horarias**~~ → hecho 2026-05-11
      (sección 9.4). En realidad la gating ya existía a nivel de loop en
      `simple_rule_bot.php:1554` desde antes; lo que se añadió ahora es el
      *modo harvest-only* fuera de ventana para no envenenar los snapshots
      de `intel` con datos viejos presentados como "recién capturados".
- [x] ~~**Distribución del botín al volver**. Hoy se inserta el INSERT y
      el motor real ya gestiona el regreso. Verificar manualmente que
      el motor procesa la fleet de attack correctamente con el formato
      `serialize()` que pone el bot.~~ → hecho 2026-05-11 (sección 9.6).
      Validado end-to-end en docker: `MissionControlLib::arrivingFleets()`
      lee el `fleet_array` con `unserialize()` (formato idéntico al que
      escribe el bot), corre la batalla, llena `fleet_resource_*` con el
      loot y vuelve `fleet_mess=1`. `returningFleets()` llama
      `restoreFleet()` que suma naves+recursos al planeta origen y borra
      la fila. Además, el bot ahora observa el ciclo completo y registra
      métricas REALES (`attacks_completed`, `total_loot_returned`,
      `real_ships_lost_value`) vía nuevo helper `botAttackHarvestReturns`.
- [x] ~~**Intel staleness vs ataque**. Hoy se considera intel hasta
      `BOT_INTEL_TTL_SECONDS`. Si un humano construye defensas justo
      después de la sonda, el bot puede estimar mal.~~ → hecho 2026-05-11
      (sección 9.7). Recheck pre-ataque con sonda real (modo asíncrono).
      Cuando una decisión de ataque pasa todos los filtros, en vez de
      lanzar directamente se ARMA un plan en `bot_quirks.pending_attacks`
      y se envía una sonda fresca. Cuando llega, el handler reevalúa
      sim+ratio+loot con el snapshot nuevo y CONFIRMA o ABORTA.
- [x] ~~**Métricas / logs**. Añadir contadores en
      `bot_state.bot_quirks.metrics`~~ → hecho 2026-05-11 (ver 9.2).
- [x] ~~**Presión de carga → big cargos prioritarios**. Cuando un bot
      ofensivo tiene blancos rentables pero su raid mix está capado
      por capacidad, debe priorizar `ship_big_cargo_ship` (id 203) en
      los siguientes loops.~~ → hecho 2026-05-11 (sección 9.8). Señal
      `bot_quirks.cargo_pressure` con TTL de 6 h emitida desde
      `botAttackRunPurposeful` cuando se salta por `cap loot < min`;
      consumida en `botShipCandidates` con peso 4.20 (vs 1.44 del small
      cargo).
- [x] ~~**Refinamientos a la presión de carga**: visibilidad en panel
      (`cargo_pressure` + métricas explícitas), suavizar override de
      almacén bajo presión, gate por rol del planeta, no atacar sin
      slot de flota libre, limpieza de scripts one-off.~~ → hecho
      2026-05-11 (sección 9.8.1).

---

## 11. Cómo retomar

1. Confirmar tests siguen verdes:
   ```bash
   cd /home/jairoconde/xg-proyect
   vendor/bin/phpunit -c tests/phpunit.xml --testsuite bot_unit
   ```
2. El servicio `bot` ya corre en `docker compose` con
   `--forever --sleep 10`. Si tocas `scripts/bot_lib/*.php` recuerda
   reiniciarlo para que recargue el código:
   ```bash
   docker compose restart bot
   ```
3. Observar logs en vivo (filtrar por las acciones del módulo):
   ```bash
   docker compose logs --tail 500 bot | grep -E "attack:|---- Bot loop"
   ```
   Tipos de líneas que se ven hoy:
   - `attack: spy 1:2:1 probes=4 eta=1s`
   - `attack: intel 1:2:1 ships=0 defs=0 res=5406223493`
   - `attack: launch 1:2:1 eta=47s loot=1192374 losses=1 ratio=1192374.00`
   - `attack: skip 4:23:7 (ratio=2.10 < 5.00, loot=... losses=...)`
   - `attack: skip 4:23:7 (no viable raid mix)`
   - `attack: idle candidates=45 fresh_intel=9 no_spy_src=36 no_atk_src=9 raidmix_fail=0 cooldown_spy=0`
4. Inspeccionar el JSON `bot_quirks` de un bot:
   ```sql
   SELECT bot_user_id,
          JSON_LENGTH(JSON_EXTRACT(bot_quirks, '$.pending_spies'))    AS pend_spies,
          JSON_LENGTH(JSON_EXTRACT(bot_quirks, '$.intel'))            AS intel_n,
          JSON_LENGTH(JSON_EXTRACT(bot_quirks, '$.spy_cooldowns'))    AS spy_cd,
          JSON_LENGTH(JSON_EXTRACT(bot_quirks, '$.attack_cooldowns')) AS atk_cd
   FROM xgp_bot_state WHERE bot_user_id = <id>;
   ```
   Para ver las métricas acumuladas (ver sección 9.2 para el shape):
   ```sql
   SELECT bot_user_id,
          JSON_PRETTY(JSON_EXTRACT(bot_quirks, '$.metrics')) AS metrics
   FROM xgp_bot_state WHERE bot_user_id = <id>;
   ```
   Comparativa rápida entre todos los bots (los más activos arriba):
   ```sql
   SELECT bot_user_id,
          JSON_EXTRACT(bot_quirks, '$.metrics.loops_processed')         AS loops,
          JSON_EXTRACT(bot_quirks, '$.metrics.spies_sent')              AS spies,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_launched')        AS atk,
          JSON_EXTRACT(bot_quirks, '$.metrics.reactive_attacks_launched') AS rev_atk,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_skipped_capped_loot') AS skip_cap,
          JSON_EXTRACT(bot_quirks, '$.metrics.no_atk_src_events')       AS no_atk_src,
          JSON_EXTRACT(bot_quirks, '$.metrics.total_loot_launched')     AS loot
   FROM xgp_bot_state
   ORDER BY atk DESC, spies DESC;
   ```
   Estado del two-phase commit / recheck pre-ataque (sección 9.7):
   ```sql
   SELECT bot_user_id,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_armed')              AS armed,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_recheck_confirmed')  AS confirmed,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_aborted_sim_lose')   AS abort_sim,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_aborted_low_ratio')  AS abort_ratio,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_aborted_low_loot')   AS abort_loot,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_aborted_no_target')  AS abort_no_tgt,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_aborted_stale_plan') AS abort_stale,
          JSON_LENGTH(JSON_EXTRACT(bot_quirks, '$.pending_attacks'))       AS armed_now
   FROM xgp_bot_state
   ORDER BY armed DESC;
   ```
   `confirmed / armed` < 0.5 sugiere que el intel se está quedando
   obsoleto antes de armar (subir la frecuencia de espionaje o bajar
   `BOT_INTEL_TTL_SECONDS`).

   Estado de la presión de carga (sección 9.8):
   ```sql
   SELECT bot_user_id,
          JSON_EXTRACT(bot_quirks, '$.cargo_pressure.skips')         AS cp_skips,
          FROM_UNIXTIME(JSON_EXTRACT(bot_quirks, '$.cargo_pressure.last_signal_at')) AS cp_last,
          FROM_UNIXTIME(JSON_EXTRACT(bot_quirks, '$.cargo_pressure.expires_at'))     AS cp_exp,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_skipped_capped_loot') AS cap_skip_total
   FROM xgp_bot_state
   WHERE JSON_EXTRACT(bot_quirks, '$.cargo_pressure') IS NOT NULL
   ORDER BY cp_skips DESC;
   ```
   Si `cp_skips > 0` y `cp_exp > NOW()` el bot está construyendo big
   cargos prioritariamente. Cuando consigue capacidad suficiente para
   atacar, `cap_skip_total` deja de crecer y `cp_exp` expira sola.

   Observación REAL del ciclo de ataques completados (sección 9.6):
   ```sql
   SELECT bot_user_id,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_launched')         AS launched,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_completed')        AS completed,
          JSON_EXTRACT(bot_quirks, '$.metrics.attacks_completed_no_data') AS completed_no_data,
          JSON_EXTRACT(bot_quirks, '$.metrics.total_loot_launched')      AS loot_estim,
          JSON_EXTRACT(bot_quirks, '$.metrics.total_loot_returned')      AS loot_real,
          JSON_EXTRACT(bot_quirks, '$.metrics.real_ships_lost_value')    AS losses_real,
          JSON_LENGTH(JSON_EXTRACT(bot_quirks, '$.attacks_in_flight'))   AS in_flight
   FROM xgp_bot_state
   ORDER BY completed DESC;
   ```
   Si `loot_real << loot_estim`, el estimador es demasiado optimista
   (probablemente por capacidad de carga insuficiente).
   Si `losses_real >> total_my_losses_value`, el bot está perdiendo
   más naves en la realidad que las que predice el simulador.

   Estado del modo revancha por bot (quién le pegó y cuándo expira el
   bonus):
   ```sql
   SELECT bot_user_id,
          bot_last_attacked_at,
          FROM_UNIXTIME(bot_last_attacked_at)                            AS attacked_at,
          bot_attack_reactive_until,
          FROM_UNIXTIME(bot_attack_reactive_until)                       AS until_at,
          JSON_EXTRACT(bot_quirks, '$.last_attacker_user_id')            AS attacker,
          JSON_EXTRACT(bot_quirks, '$.metrics.reactive_attacks_launched') AS rev_atk
   FROM xgp_bot_state
   WHERE bot_attack_reactive_until > UNIX_TIMESTAMP()
   ORDER BY bot_attack_reactive_until DESC;
   ```
5. Si quieres forzar el escenario "hay flota lista para raid" en un
   universo nuevo (early-game, los bots todavía no construyen cargos):
   ```sql
   -- Identifica un bot con sondas y dale flota mínima:
   UPDATE xgp_ships
     SET ship_big_cargo_ship = 60, ship_cruiser = 100, ship_heavy_fighter = 50
     WHERE ship_planet_id = <planet_id_del_bot>;
   ```
   Reinicia el `bot` y en 2-3 loops deberías ver `attack: launch`.
6. Si todo parece sano, atacar uno de los pendientes de la sección 10.

---

## 12. Referencias rápidas

- Punto de entrada principal:
  [`botAttackRunPurposeful()`](../scripts/bot_lib/attack.php)
- Simulador:
  [`botCombatSimulate()`](../scripts/bot_lib/combat.php)
- Cargo + escolta:
  [`botPickRaidMix()`](../scripts/bot_lib/travel.php)
- Configuración por perfil:
  [`scripts/bot_accounts.json`](../scripts/bot_accounts.json)
- Constantes globales:
  [`scripts/bot_lib/safety.php`](../scripts/bot_lib/safety.php)
- Cableado en el orquestador:
  [`scripts/simple_rule_bot.php`](../scripts/simple_rule_bot.php) (busca
  `botAttackRunPurposeful` para encontrar la llamada).
