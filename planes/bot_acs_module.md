# Bot ACS module — Fase 1

> Actualización: 2026-05-11. ACS bot↔bot **vía `fleet_group` real del motor**, gateado por diplomacia alianza↔alianza.

## Decisiones de producto cerradas

- ACS solo se permite **a los bots** contra cuentas cuyo `user_ally_id` pertenezca a una alianza que esté **en guerra** con la del bot (fila `xgp_alliance_diplomacy` con `status='war'`).
- La diplomacia (declarar guerra, NAP, etc.) se implementa **después**. La tabla ya existe para que el gate del ACS funcione hoy: en in-vivo se insertan guerras manualmente con SQL.
- Cada bot abre **como máximo 1** grupo ACS abierto a la vez (`BOT_ACS_MAX_OPEN_GROUPS_PER_BOT`).
- ACS solo ataque (misión 1 líder + misión 2 miembros con mismo `fleet_group`). No se contempla ACS de defensa/hold.

## Archivos

| Archivo | Rol |
|---|---|
| [scripts/migrate_create_alliance_diplomacy.php](../scripts/migrate_create_alliance_diplomacy.php) | Migración de la tabla `xgp_alliance_diplomacy`. |
| [scripts/bot_lib/diplomacy.php](../scripts/bot_lib/diplomacy.php) | Helpers de lectura `botDiplomacyIsAtWar`, `botDiplomacyListEnemyAlliances`, `botDiplomacyListEnemyUserIds`. `botDiplomacyUpsertWar` para tests/admin. |
| [scripts/bot_lib/acs_attack.php](../scripts/bot_lib/acs_attack.php) | Orquestador ACS: `botAcsRunPurposeful` ejecuta primero `botAcsRunJoiner` (uno se une si puede) y después `botAcsRunLeader` (uno abre si nadie le ha cubierto la flota). Inserciones atómicas via `botFleetInsertTransactional`. |
| [scripts/bot_lib/safety.php](../scripts/bot_lib/safety.php) | Constantes `BOT_ACS_*`. |
| [scripts/simple_rule_bot.php](../scripts/simple_rule_bot.php) | Llama a `botAcsRunPurposeful` justo después de `botAttackRunPurposeful`. |
| [app/Http/Controllers/Adm/BotstatsController.php](../app/Http/Controllers/Adm/BotstatsController.php) | Métricas ACS en `METRIC_COLUMNS` + i18n es/en. |

## Tabla `xgp_alliance_diplomacy`

```sql
CREATE TABLE `xgp_alliance_diplomacy` (
    `diplomacy_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `alliance_a` INT UNSIGNED NOT NULL,
    `alliance_b` INT UNSIGNED NOT NULL,
    `status` ENUM('war','nap','neutral') NOT NULL DEFAULT 'neutral',
    `since` INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`diplomacy_id`),
    UNIQUE KEY `pair` (`alliance_a`, `alliance_b`),
    KEY `alliance_a` (`alliance_a`),
    KEY `alliance_b` (`alliance_b`),
    KEY `status` (`status`)
) ENGINE=InnoDB;
```

- Pares **normalizados** a `(min, max)` (`botDiplomacyNormalisePair`). La guerra es simétrica.
- `expires_at = 0` → sin expiración. Cualquier `expires_at <= NOW()` se ignora.

## Constantes (en `safety.php`)

| Constante | Valor | Significado |
|---|---:|---|
| `BOT_ACS_MAX_OPEN_GROUPS_PER_BOT` | 1 | Cuántos grupos ACS abiertos puede liderar un bot a la vez. |
| `BOT_ACS_LEADER_EXTRA_SECONDS` | 600 | Buffer añadido al `fleet_end_time` del líder sobre su propia duración real, para que miembros más lejanos lleguen a tiempo. |
| `BOT_ACS_JOINER_MIN_SLACK_SECONDS` | 60 | Slack obligatorio: un miembro solo se une si `(end_time - now) >= duration + slack`. Evita carreras con el tick del motor. |
| `BOT_ACS_GROUP_MAX_MEMBERS` | 5 | Tope de miembros en un grupo (alineado con la UI humana). |
| `BOT_ACS_LEADER_MIN_FLEET_VALUE` | 100 000 | Suma `metal+crystal` mínima del mix de combate del líder antes de abrir grupo. Evita grupos cosméticos. |

## Flujo

### Líder (`botAcsRunLeader`)

1. Si `user_ally_id <= 0` → return.
2. Lista enemigos via `botDiplomacyListEnemyAlliances`. Sin guerras → métrica `acs_skipped_no_war` y return.
3. Si ya tiene `BOT_ACS_MAX_OPEN_GROUPS_PER_BOT` filas en `xgp_acs` con `acs_owner=userId` → return.
4. Elige objetivo: recorre el `intel` reciente, descarta planetas sin coords/usuario, exige que `user_ally_id` del dueño esté en el set de enemigos. Sin objetivo → `acs_skipped_no_target`.
5. Para cada planeta propio construye un `shipMix` con `botAcsBuildLeaderShipMix` (combate al `1 - reserve` + al menos un carguero). El de mayor valor `metal+crystal` que supere `BOT_ACS_LEADER_MIN_FLEET_VALUE` gana. Sin fuente → `acs_skipped_no_fleet`.
6. `botAcsInsertGroup` (transacción): INSERT `xgp_acs` + INSERT `xgp_acs_members` (líder) + INSERT `xgp_fleets` (mission 1, `fleet_group=acs_id`) + UPDATE `xgp_ships` y `xgp_planets`.
7. Métrica `acs_groups_created`, log `acs: leader group=... target=g:s:p enemy_user=... end=...`.

### Miembro (`botAcsRunJoiner`)

1. Lista grupos abiertos cuyo `acs_owner` esté en `xgp_bot_state` y misma alianza, leyendo `MIN(fleet_end_time)` de la flota del líder como `leader_end_time`.
2. Para cada grupo: descarta si ya hay 5 miembros, busca el dueño del planeta objetivo, exige `botDiplomacyIsAtWar(myAlly, targetAlly)`. Si el target ya no está en guerra (caso de NAP firmado tras crear el grupo) se omite.
3. Construye su mejor mix con `botAcsBuildLeaderShipMix` por planeta y elige el de mayor `array_sum`.
4. `botAcsInsertJoiner` (transacción): INSERT `INSERT IGNORE xgp_acs_members` + INSERT `xgp_fleets` (mission 2, mismo `fleet_group`, mismo `fleet_end_time`) + UPDATEs.
5. Sincronización temporal: el miembro respeta el `fleet_end_time` del líder. Si no le da el tiempo (`end_time - now < duration + slack`) → `acs_skipped_late`.
6. Métrica `acs_groups_joined`.

### Comportamiento en combate

- Cuando el `fleet_end_time` de la flota líder vence, `Attack.attackMission` ve `fleet_group > 0`, recoge todas las flotas del grupo con `getAllAcsFleetsByGroupId`, las combina en una `PlayerGroup` y resuelve la batalla. Marca todas con `fleet_mess=1` y borra la fila `xgp_acs`. Las flotas miembros se devuelven solas en `canCompleteMission`.

## Métricas

`acs_groups_created`, `acs_groups_joined`, `acs_skipped_no_war`, `acs_skipped_no_target`, `acs_skipped_no_fleet`, `acs_skipped_late`, `acs_insert_failed`.

## Tests (PHPUnit)

- `botDiplomacyNormalisePair` swap + validación de pares inválidos.
- `botAcsBuildLeaderShipMix` null si no hay combate ni carga, fuerza ≥1 carguero, prefiere grandes.
- `botAcsValueOfShipMix` correcta sobre pricelist sintético.

(Los flujos con DB se validan in-vivo en Docker, ver más abajo.)

## SQL útil

```sql
-- Declarar guerra manual entre dos alianzas (hasta que llegue diplomacia)
INSERT INTO xgp_alliance_diplomacy (alliance_a, alliance_b, status, since)
VALUES (LEAST(?, ?), GREATEST(?, ?), 'war', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE status='war', since=VALUES(since);

-- Ver grupos ACS abiertos liderados por bots
SELECT a.*, u.user_name, u.user_ally_id, ll.alliance_tag,
       (SELECT COUNT(*) FROM xgp_acs_members m WHERE m.acs_group_id=a.acs_id) AS members,
       (SELECT MIN(f.fleet_end_time) FROM xgp_fleets f
        WHERE f.fleet_group=a.acs_id AND f.fleet_owner=a.acs_owner) AS leader_end
FROM xgp_acs a
INNER JOIN xgp_users u ON u.user_id=a.acs_owner
INNER JOIN xgp_bot_state bs ON bs.bot_user_id=a.acs_owner
LEFT JOIN xgp_alliance ll ON ll.alliance_id=u.user_ally_id;

-- Ver miembros + flotas del grupo
SELECT m.acs_user_id, u.user_name, f.fleet_id, f.fleet_mission, f.fleet_end_time
FROM xgp_acs_members m
INNER JOIN xgp_users u ON u.user_id=m.acs_user_id
LEFT JOIN xgp_fleets f ON f.fleet_group=m.acs_group_id AND f.fleet_owner=m.acs_user_id
WHERE m.acs_group_id=?;
```

## Fuera de Fase 1

- Decisión de declarar/aceptar guerra desde el bot (Fase Diplomacia).
- ACS defensa/hold.
- Coordinación de timing más allá del `EXTRA_SECONDS` (estimar duración del miembro más lejano antes de crear el grupo).
- Cancelación deliberada de un grupo ACS (ahora solo el motor lo borra al disparar la batalla, o si el líder devuelve su flota).
