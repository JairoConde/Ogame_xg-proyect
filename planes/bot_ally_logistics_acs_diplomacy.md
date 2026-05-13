# Logística aliada, ACS y diplomacia — decisiones de producto

> Actualización: 2026-05-11. Documenta lo acordado con el usuario y el alcance por fases.

## Alcance cerrado (transporte / mensajes)

### Prioridad: transporte aliado entre bots

- Solo si `user_ally_id` coincide (misma alianza).
- El bot emisor usa sus propios planetas como origen y planetas de **otros usuarios que tengan fila en `xgp_bot_state`** (cuentas bot) como destino.
- Misma tubería que el transporte interno: `botTransportPlanetNeed`, `botTransportPlanetSurplus`, `botFleetInsertTransactional`, misión **3**.
- Constantes en `scripts/bot_lib/safety.php`: `BOT_ALLY_TRANSPORT_MAX_PER_LOOP`, `BOT_ALLY_TRANSPORT_MIN_ALLY_NEED`.
- Código: `scripts/bot_lib/ally_logistics.php`, entrada `botAllyLogisticsRun` desde `scripts/simple_rule_bot.php` (tras `botTransportRunPurposeful`).

### Bot ↔ humano (plantilla fija, sin LLM por ahora)

1. **Bot → humano** (pedir recursos): si el déficit de cola en los planetas propios supera `BOT_ALLY_MSG_TO_HUMAN_MIN_DEFICIT` y ha pasado el cooldown `BOT_ALLY_MSG_TO_HUMAN_COOLDOWN_SECONDS`, se elige un miembro humano de la alianza (usuario **sin** `bot_state`) y se inserta un mensaje tipo alianza (`message_type = 3`) con texto bilingüe ES/EN y una línea máquina:
   - `[ALLY_BOT_NEEDS v=1 metal=… crystal=… deut=… g=… s=… p=… t=…]`
   - Más adelante un LLM podrá sustituir el cuerpo libre; **convención**: conservar esa línea para parsers.

2. **Humano → bot** (pedir al bot): el humano envía un mensaje de alianza al bot que contenga el marcador **`[ALLY_REQ]`** y pares `clave=cantidad`, por ejemplo:
   - `[ALLY_REQ] metal=100000 crystal=50000 deut=0`
   - También se acepta `deuterium=`. Cantidades acotadas por `BOT_ALLY_HUMAN_REQ_PER_RESOURCE_CAP`.
   - El bot entrega al **primer planeta no destruido** del remitente (menor `planet_id`) si tiene surplus y cargueros; el mensaje se marca leído para no reprocesar.

### Estado en `bot_quirks`

```json
"ally_logistics": {
  "last_need_msg_at": 0,
  "last_processed_message_id": 0
}
```

### Métricas (panel `botstats`)

- `ally_bot_transports_sent`
- `ally_human_need_requests_sent`
- `ally_human_request_fulfilled`

---

## ACS (solo ataque) — implementado

- Misión 1 (líder) + misión 2 (miembros) compartiendo `fleet_group`, gateado por `xgp_alliance_diplomacy` (estado `war`).
- Implementado en `scripts/bot_lib/acs_attack.php` y `scripts/bot_lib/diplomacy.php`. Detalles en [`bot_acs_module.md`](bot_acs_module.md).

---

## Diplomacia — siempre alianza ↔ alianza — fase posterior

- Relaciones entre **alianzas** (no jugador suelto sin alianza): p. ej. guerra, NAP, neutral.
- Tabla nueva dedicada (no reutilizar `alliance_request`); ver borrador en `planes/bot_alliance_module.md` (`xgp_alliance_diplomacy`).
- Los bots decidirán según personalidad cuando exista el modelo de datos y los ganchos en misiones (ataque, espionaje, eventualmente transporte según reglas que fijéis).

---

## Referencias de código

| Área | Archivos |
|------|-----------|
| Logística aliada | `scripts/bot_lib/ally_logistics.php`, `scripts/bot_lib/safety.php` (constantes `BOT_ALLY_*`) |
| Transporte base | `scripts/bot_lib/transport.php`, `app/Libraries/Missions/Transport.php` |
| Mensajes aliado | `scripts/bot_lib/alliance.php` → `botAllianceNotifyInbox` |
| Panel métricas | `app/Http/Controllers/Adm/BotstatsController.php`, `resources/lang/*/adm/botstats_lang.php` |
