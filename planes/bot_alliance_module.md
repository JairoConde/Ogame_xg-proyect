# Bot Alliance Module — Fase 1 (consolidada)

> Última actualización: 2026-05-11. Fase 1 cerrada, validada in-vivo en Docker con 4 alianzas activas (1 humana + 3 bot-led) y sin patologías accept→kick→dissolve.

## Resumen

Los bots gestionan alianzas vía SQL directo desde `scripts/bot_lib/alliance.php`. Cada loop:

- **Sin alianza ni solicitud pendiente** → intentan **aplicar** a la mejor alianza compatible (ordenadas por afinidad personalidad/archetype + tamaño). Si no hay candidatos y se cumplen requisitos duros (`BOT_ALLIANCE_MIN_POINTS_TO_CREATE`, edad, score) → **crean** alianza nueva con metadatos JSON embebidos en `alliance_request`.
- **Con solicitud pendiente** → esperan hasta `BOT_ALLIANCE_APPLY_PENDING_TTL_SECONDS`; al expirar cancelan, marcan el target en `rejected_by` con TTL y reintentan en otro ciclo.
- **Como líder** (cooldown `BOT_ALLIANCE_OWNER_REVIEW_COOLDOWN_SECONDS`): revisan solicitantes (`decideAcceptApplicant`), valoran kick por requisitos/inactividad (`decideKick`) y disolución (`decideDissolve`). Acciones notifican vía inbox tipo 3 (`MessagesEnumerator::ALLY`).
  - **Disolución por mejor alternativa**: si el owner está solo (`members <= 1`) aún dentro de la gracia (`BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS`), el orquestador calcula `better_alternative_member_count` con el `MAX(member_count)` de las otras alianzas. Si supera `BOT_ALLIANCE_BETTER_ALT_MIN_MEMBERS`, `decideDissolve` devuelve `dissolve / better_alternative_in_grace` y libera la posición. Esto rompe el caso "soy fundador solitario y al lado hay una alianza con 14 miembros que podría aceptarme".
  - **Anti-rebote** tras una disolución propia: se guarda `last_dissolved_at` en `bot_quirks.alliance` y la rama "sin alianza" bloquea `decideCreate` durante `BOT_ALLIANCE_ANTI_REBOUND_SECONDS`. Mientras dura el TTL el bot solo puede aplicar a una alianza existente o quedarse idle.
- **Como miembro no líder**:
  - **Transferencia de liderazgo** (`decideOwnershipTransfer`): si el owner es un bot y lleva inactivo ≥ `BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS`, los miembros bot calculan un score y, de forma determinista, el de mayor score (o `user_id` más bajo en empate) ejecuta el `UPDATE` atómico. *Solo se transfiere si el owner actual es un bot — nunca se le quita a un humano.*
  - **Salida voluntaria**: revisión probabilística para abandonar si el líder lleva inactivo ≥ 7 días o la alianza está muerta.
- **Todos los bots siempre aspiran a alianza** (decisión de producto): el path por defecto es *aplicar*; *crear* es fallback.

## Archivos clave

| Archivo | Rol |
|---------|-----|
| [scripts/bot_lib/alliance_codec.php](../scripts/bot_lib/alliance_codec.php) | `botAllianceParseRequirements`, `botAllianceEncodeRequirements`, `botAllianceHumanReadableRequest` |
| [scripts/bot_lib/alliance_policy.php](../scripts/bot_lib/alliance_policy.php) | Interfaz `BotAlliancePolicy`, reglas puras `BotAlliancePolicyRules` (sin acceso a DB) |
| [scripts/bot_lib/alliance.php](../scripts/bot_lib/alliance.php) | Orquestación `botAllianceTick`, SQL helpers, métricas, mensajería |
| [scripts/bot_lib/safety.php](../scripts/bot_lib/safety.php) | Constantes `BOT_ALLIANCE_*` |
| [scripts/simple_rule_bot.php](../scripts/simple_rule_bot.php) | `require` + tick tras `botMaintainLongTermGoal`; refresca `user_onlinetime` por loop |
| [app/Http/Controllers/Game/AllianceController.php](../app/Http/Controllers/Game/AllianceController.php) | `formatAllianceRequestForDisplay` para no mostrar JSON crudo a humanos |
| Admin: [BotstatsController](../app/Http/Controllers/Adm/BotstatsController.php), [botstats_view](../resources/views/adm/botstats_view.php), langs es/en | Columna «Alliance» + tarjeta «Bot-managed alliances» con miembros y solicitudes pendientes |

## Constantes (en `safety.php`)

| Constante | Valor | Significado |
|---|---:|---|
| `BOT_ALLIANCE_OWNER_REVIEW_COOLDOWN_SECONDS` | 10 | Tiempo mínimo entre revisiones de owner (accept/reject/kick/dissolve). Bajado de 3600 → 10 para feedback rápido. |
| `BOT_ALLIANCE_POST_ACTION_COOLDOWN_SECONDS` | 30 | Cooldown tras una aplicación rechazada/expirada. Solo bloquea NUEVAS aplicaciones; owners y miembros siguen ejecutando su rama. Bajado de 1800 → 30. |
| `BOT_ALLIANCE_MEMBER_REVIEW_CHANCE_X100` | 2 | Probabilidad por cada 100 de que un miembro no-líder valore abandonar en un loop dado. |
| `BOT_ALLIANCE_APPLY_PENDING_TTL_SECONDS` | 86400·3 | TTL de una solicitud pendiente antes de cancelarse. |
| `BOT_ALLIANCE_REJECTED_BY_TTL_SECONDS` | 86400·2 | Cuánto tiempo se evita reaplicar a una alianza que ya rechazó. |
| `BOT_ALLIANCE_DESPERATE_AFTER_SECONDS` | 86400·3 | Tras este tiempo sin alianza, modo «desesperado» (relaja filtros). |
| `BOT_ALLIANCE_DESPERATE_MIN_FAILED_STREAK` | 3 | Solicitudes fallidas consecutivas para activar desesperado. |
| `BOT_ALLIANCE_MIN_POINTS_TO_CREATE` | 3000 | Umbral mínimo de puntos para fundar. |
| `BOT_ALLIANCE_MIN_AGE_HOURS_TO_CREATE` | 48 | Antigüedad mínima de la cuenta para fundar. |
| `BOT_ALLIANCE_MAX_BOT_LED` | 8 | Tope de alianzas bot-led simultáneas en el universo. |
| `BOT_ALLIANCE_FOUND_MIN_SELF_SCORE` | 7.0 | Score mínimo del fundador (en `computeFounderSelfScore`). |
| `BOT_ALLIANCE_NEW_MEMBER_GRACE_SECONDS` | 3600 | Gracia tras unirse: durante 1 h el owner NO puede expulsar al miembro recién aceptado. |
| `BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS` | 3600 | Gracia post-creación: durante 1 h la alianza no se auto-disuelve por estar vacía. |
| `BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS` | 86400·14 | Tras este tiempo de inactividad del owner (medido por `user_onlinetime`), los miembros bot pueden transferirse el liderazgo. Solo aplica si el owner es un bot. |
| `BOT_ALLIANCE_OWNER_TRANSFER_REVIEW_COOLDOWN_SECONDS` | 300 | Cooldown entre revisiones de transferencia por miembro, para no martillear el chequeo cada loop. |
| `BOT_ALLIANCE_BETTER_ALT_MIN_MEMBERS` | 3 | Si un fundador solitario está dentro de la gracia de disolución y existe otra alianza con ≥ este número de miembros, se rompe la gracia y se disuelve para poder aplicar a la alternativa. |
| `BOT_ALLIANCE_ANTI_REBOUND_SECONDS` | 3600 | Tras disolver su propia alianza, el bot no puede fundar otra durante este TTL. Tiene que aplicar a una existente (o quedar idle). Evita el bucle disolver → recrear → disolver. |

## JSON en `alliance_request` (bot_managed)

Cuando un bot funda una alianza, `botAllianceEncodeRequirements` serializa un objeto JSON en el campo `alliance_request`:

```json
{
  "bot_managed": true,
  "version": 1,
  "created_at": 1778500000,
  "founder_personality": "flotero",
  "founder_archetype": "balanced",
  "founder_style": "raider",
  "requirements": {
    "min_total_points": 8000,
    "min_military_points": 1665,
    "min_fleet_points": 666,
    "min_research_points": 0,
    "personality_whitelist": ["flotero", "cazador"],
    "style_whitelist": ["raider", "granja"],
    "max_inactive_seconds": 172800
  },
  "soft_text": "Bot-managed alliance. Apply if you meet the posted requirements."
}
```

- `AllianceController::formatAllianceRequestForDisplay` detecta `bot_managed:true` y muestra `soft_text` (o un fallback localizado) en lugar del JSON crudo, evitando que los humanos vean JSON al solicitar entrar.
- El admin panel muestra una versión legible (requisitos resumidos + miembros + pendientes) en la tarjeta «Bot-managed alliances».

## Estado por bot (`bot_quirks.alliance`)

```json
{
  "since_no_ally_at": 1778500000,
  "applications_failed_streak": 0,
  "pending_alliance_id": 0,
  "pending_since": 0,
  "rejected_by": {"3": 1778586000},
  "post_action_cooldown_until": 0,
  "last_owner_review_at": 1778500200,
  "idle_no_candidate_streak": 0
}
```

## Métricas (`bot_quirks.metrics`)

Las claves visibles por bot en `BotstatsController::METRIC_COLUMNS` (con traducción `bs_metric_*` es/en):

`alliances_created`, `alliances_dissolved`, `alliance_applications_sent`, `alliance_applications_accepted`, `alliance_applications_rejected_received`, `alliance_applicants_accepted`, `alliance_applicants_rejected`, `alliance_members_kicked`, `alliance_left_voluntary`, `alliance_skip_cooldown`, `alliance_skip_no_candidate`, `alliance_desperate_applies`, `alliance_request_expired`, `alliances_ownership_transferred`, `alliances_ownership_received`, `alliances_dissolved_for_better_alt`, `alliance_create_anti_rebound_skips`, `ally_bot_transports_sent`, `ally_human_need_requests_sent`, `ally_human_request_fulfilled`.

Claves auxiliares NO mostradas por bot (alimentan los ratios agregados):

- `alliance_apply_to_join_count` — número de aplicaciones que terminaron en ingreso (numerador de la media).
- `alliance_apply_to_join_seconds_sum` — suma de `now − pending_since` por ingreso (numerador de la media en segundos).

### Resumen de salud (panel agregado)

`BotstatsController` renderiza una tarjeta «Salud del módulo de alianzas» con:

- Total alianzas bot-led, miembros totales en ellas y solicitudes pendientes hacia ellas.
- `apply_success_rate` = `applications_accepted / applications_sent`.
- `reject_ratio` = `applicants_rejected / (applicants_accepted + applicants_rejected)`.
- `kick_ratio` = `alliance_members_kicked / alliance_applicants_accepted`.
- `avg_apply_to_join_seconds` = `alliance_apply_to_join_seconds_sum / alliance_apply_to_join_count` (formato `h m s`).
- Total transferencias de liderazgo (suma única por evento, usa `alliances_ownership_received`).

## Decisiones de producto cerradas

- **Almacenamiento de requisitos** → reutilizar `alliance_request` (TEXT) con JSON + flag `bot_managed:true`. No se crea una tabla nueva todavía.
- **Interacción mixta** → bots pueden aplicar a alianzas humanas; owners bot pueden aceptar humanos (lógica `human_permissive` en `decideAcceptApplicant`).
- **Aspiración constante** → todos los bots intentan aplicar siempre que no estén en alianza ni tengan pending. Crear solo si no hay candidatos *y* se cumplen umbrales duros.
- **Transferencia de liderazgo (bots)** → si el owner es un bot inactivo, los miembros bot pueden promover al nuevo owner (`decideOwnershipTransfer` + SQL atómico). No aplica a owners humanos.
- **Cooldown post-acción no bloquea owners/miembros** → solo bloquea nuevas aplicaciones; los líderes mantienen su capacidad de revisar.
- **Gracias temporales** para evitar el patrón accept→kick→dissolve:
  - Miembros recién aceptados protegidos 1 h contra kick.
  - Alianzas recién creadas protegidas 1 h contra dissolve por estar vacías.
- **Refresco de `user_onlinetime`** en cada iteración del loop, para que los bots no parezcan inactivos a su propia política de expulsión.

## Lecciones aprendidas in-vivo

1. **Bug accept→kick→dissolve**: con `max_inactive_seconds=172800` (2 días) y `user_onlinetime` no refrescado (los bots quedaban con un timestamp de hace 4 días tras un reinicio), cualquier miembro recién aceptado caía inmediatamente en «inactive» y era expulsado, dejando la alianza vacía → dissolve. Solucionado actualizando `user_onlinetime` cada loop + gracia de 1 h.
2. **Cooldown post-acción demasiado amplio**: el cooldown original de 30 min bloqueaba TODAS las decisiones de la rama (incluyendo revisión de owner), de modo que los líderes recién promovidos quedaban congelados. Se acotó para que solo afecte a nuevas aplicaciones.
3. **Disolución prematura**: una alianza recién creada con solo el fundador se auto-disolvía en el primer review cycle. Añadida la gracia de creación (1 h).

## Pruebas

`tests/scripts/bootstrap.php` carga `alliance.php`. PHPUnit (18 tests `testBotAlliance*`):

- Parse / encode / human-readable JSON.
- `decideAcceptApplicant` humano (con puntos / sin puntos) y bot.
- `decideCreate` con score bajo.
- `filterCandidatesForApply` respetando `rejected_by`.
- `applicantMeetsNumericRequirements`.
- `decideKick` con gracia para nuevos miembros, kick por inactividad pasada la gracia.
- `decideDissolve` con gracia de alianza joven y disolución de alianza vieja.
- `decideOwnershipTransfer`: no actúa si owner humano, no actúa si owner activo, sin candidato → noop, elige el de mayor score con desempate determinista por `user_id`.
- `computeTransferScore`: rankea miembros por stats.
- `decideDissolve`: rompe la gracia si hay mejor alternativa, la respeta si solo hay alternativas débiles.
- `isWithinAntiRebound`: activo justo después de disolver, expira tras el TTL, falso si `last_dissolved_at` está vacío o a 0.

## SQL útil

```sql
-- Alianzas bot-led
SELECT alliance_id, alliance_tag, alliance_owner, alliance_register_time
FROM xgp_alliance
WHERE alliance_request LIKE '%"bot_managed":true%';

-- Solicitudes pendientes a una alianza
SELECT user_id, user_name, user_ally_register_time
FROM xgp_users
WHERE user_ally_request = <id>;

-- Estado alianza de un bot
SELECT bot_user_id, JSON_EXTRACT(bot_quirks, '$.alliance') AS alliance,
       JSON_EXTRACT(bot_quirks, '$.metrics') AS metrics
FROM xgp_bot_state
WHERE bot_user_id = <id>;

-- Reset de cooldowns viejos tras cambiar constantes (mantenimiento)
UPDATE xgp_bot_state
SET bot_quirks = JSON_SET(bot_quirks, '$.alliance.post_action_cooldown_until', 0)
WHERE JSON_EXTRACT(bot_quirks, '$.alliance.post_action_cooldown_until') > 0;
```

## Logística aliada (bot↔bot, mensajes a humanos)

Implementado en `scripts/bot_lib/ally_logistics.php` (ver [bot_ally_logistics_acs_diplomacy.md](bot_ally_logistics_acs_diplomacy.md)): transporte misión 3 entre bots de la misma alianza, mensajes con plantilla fija bot→humano y cumplimiento de `[ALLY_REQ]` humano→bot.

## Fuera de alcance de Fase 1 (planeado, no implementado)

| Tema | Notas |
|---|---|
| **Fase 2 — LLM/Ollama** | `BotAlliancePolicy` permite sustituir `BotAlliancePolicyRules` por un wrapper que llame a Ollama con fallback a reglas. `botAllianceBuildContext` admite ampliación del `ctx` sin tocar `botAllianceTick`. Se activará cuando Ollama esté disponible. |
| **Diplomacia / NAP / Guerra** | No reutilizar `alliance_request` para esto. Tabla futura `xgp_alliance_diplomacy` (relaciones bilaterales con tipo: ally / nap / war / neutral). |
| **Coordinación ACS entre bots aliados** | El motor ACS (`app/Libraries/Missions/Acs.php`) existe; falta lógica para componer flotas conjuntas. |
| **Rangos personalizados y permisos finos** | Todos los miembros entran como rango 1 (fundador) sobre el `alliance_ranks` JSON por defecto. |
| **Métricas agregadas en panel** (success-rate, tiempo medio apply→join) | ✅ implementadas en la tarjeta «Salud del módulo de alianzas». |
| **Transferencia de liderazgo** | ✅ implementada (solo entre bots): `decideOwnershipTransfer` + `botAllianceTransferOwnership` con `SELECT … FOR UPDATE`. |
