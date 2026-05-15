<?php
/**
 * D&D Party Dashboard — PHP backend for Synology Web Station (or any LAMP host).
 *
 * Data source: D&D Beyond directly (character-service.dndbeyond.com).
 *
 * Routes (mapped to /api/* by .htaccess):
 *   GET  /api/config/json                → frontend-safe slice of config.php
 *   GET  /api/groups/json                → group config (one entry per install)
 *   GET  /api/characters/{id}/json       → fetches character from D&D Beyond, adapts to dashboard format
 *   POST /api/roll      {who,expression,label}  → rolls dice & stores result
 *   GET  /api/rolls?since=N              → returns rolls with id > N
 *   POST /api/rolls/clear                → clears the roll log
 *   GET  /api/dm/check                   → returns DM session state
 *   POST /api/dm/setup                   → creates a new pending DM token
 *   POST /api/dm/claim?token=...         → claims a pending token (sets DM cookie)
 *   POST /api/dm/revoke                  → revokes current DM session
 *
 * Dice storage: ./rolls.dat (JSON, protected from direct access by .htaccess).
 * DM tokens:    ./dm-tokens.dat (JSON, protected from direct access by .htaccess).
 *
 * Configuration is loaded from ./config.php — see config.example.php for the template.
 */

declare(strict_types=1);

const DNDB_URL_BASE  = 'https://character-service.dndbeyond.com/character/v5/character/';
const ROLLS_FILE     = __DIR__ . '/rolls.dat';
const DM_TOKENS_FILE = __DIR__ . '/dm-tokens.dat';
const MAX_ROLLS      = 500;

/**
 * Load and validate local configuration.
 *
 * Returns an array with keys: group_name, group_slug, character_ids,
 * ac_overrides, speed_overrides, kofi_username.
 *
 * If config.php is missing or malformed, emits a clear JSON error (HTTP 500)
 * and exits — keeps the failure mode obvious for self-hosters during setup.
 */
function load_config(): array {
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Configuration missing.',
            'hint'  => 'Copy config.example.php to config.php and edit it. See README.md for details.',
        ]);
        exit;
    }
    /** @var mixed $cfg */
    $cfg = require $path;
    if (!is_array($cfg)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'config.php must return an array.']);
        exit;
    }
    // Apply defaults so missing keys never crash later code paths.
    $slug = preg_replace('/[^a-z0-9-]+/i', '', (string)($cfg['group_slug'] ?? 'party'));
    return [
        'group_name'      => (string)($cfg['group_name']    ?? 'Party'),
        'group_slug'      => $slug !== '' ? strtolower($slug) : 'party',
        'character_ids'   => array_values(array_map('intval', (array)($cfg['character_ids']   ?? []))),
        'ac_overrides'    => (array)($cfg['ac_overrides']    ?? []),
        'speed_overrides' => (array)($cfg['speed_overrides'] ?? []),
        'kofi_username'   => trim((string)($cfg['kofi_username'] ?? '')),
    ];
}

$CONFIG = load_config();

// Cookie name is namespaced by the configured group slug, so multiple
// dashboards on the same host don't share DM sessions.
define('DM_COOKIE_NAME', $CONFIG['group_slug'] . '_dm');

// Character IDs per group. One group per install for now; the map structure
// is kept for forward-compatibility with multi-table setups.
define('GROUPS', [
    $CONFIG['group_slug'] => $CONFIG['character_ids'],
]);

// Optional manual overrides (see config.example.php for explanation).
define('AC_OVERRIDES',    $CONFIG['ac_overrides']);
define('SPEED_OVERRIDES', $CONFIG['speed_overrides']);

// D&D 5e Standard-Conditions (ID → Name)
const CONDITION_NAMES = [
    1  => 'Blinded',     2  => 'Charmed',     3  => 'Deafened',
    4  => 'Exhaustion',  5  => 'Frightened',  6  => 'Grappled',
    7  => 'Incapacitated', 8 => 'Invisible',  9  => 'Paralyzed',
    10 => 'Petrified',  11 => 'Poisoned',    12 => 'Prone',
    13 => 'Restrained', 14 => 'Stunned',     15 => 'Unconscious',
];

// ─── Headers (CORS, no-cache) ───
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Routing ───
$path   = isset($_GET['_path']) ? trim((string)$_GET['_path'], '/') : '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($path === 'config/json' && $method === 'GET') {
        // Frontend-safe slice of config.php. Intentionally excludes server-only
        // fields (overrides etc.) — the frontend only needs branding + IDs.
        json_response(200, [
            'group_name'    => $CONFIG['group_name'],
            'group_slug'    => $CONFIG['group_slug'],
            'character_ids' => $CONFIG['character_ids'],
            'kofi_username' => $CONFIG['kofi_username'],
        ]);

    } elseif ($path === 'groups/json' && $method === 'GET') {
        $out = [];
        foreach (GROUPS as $name => $members) {
            $out[] = ['name' => $name, 'members' => $members];
        }
        json_response(200, $out);

    } elseif (preg_match('#^characters/(\d+)/json$#', $path, $m) && $method === 'GET') {
        $id = (int)$m[1];
        $character = fetch_dndb_character($id);
        json_response(200, $character);

    } elseif ($path === 'roll' && $method === 'POST') {
        $body       = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) $body = [];
        $who        = trim((string)($body['who']        ?? ''));
        $expression = trim((string)($body['expression'] ?? ''));
        $label      = trim((string)($body['label']      ?? ''));
        if ($who === '')        throw new InvalidArgumentException("'who' fehlt");
        if ($expression === '') throw new InvalidArgumentException("'expression' fehlt");
        $roll = add_roll($who, $expression, $label);
        json_response(200, ['ok' => true, 'id' => $roll['id']]);

    } elseif ($path === 'rolls' && $method === 'GET') {
        if (!dm_is_authenticated()) {
            json_response(200, ['rolls' => [], 'auth_required' => true]);
        }
        $since = (int)($_GET['since'] ?? 0);
        json_response(200, ['rolls' => get_rolls_since($since)]);

    } elseif ($path === 'rolls/clear' && $method === 'POST') {
        if (!dm_is_authenticated()) {
            json_response(403, ['error' => 'DM-Anmeldung erforderlich']);
        }
        $n = clear_rolls();
        json_response(200, ['ok' => true, 'cleared' => $n]);

    } elseif ($path === 'dm/check' && $method === 'GET') {
        json_response(200, ['is_dm' => dm_is_authenticated()]);

    } elseif ($path === 'dm/setup' && $method === 'POST') {
        $token = dm_create_token();
        $base  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
               . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir   = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $url   = $base . $dir . '/?dm-claim=' . $token;
        json_response(200, ['ok' => true, 'token' => $token, 'url' => $url]);

    } elseif ($path === 'dm/claim' && $method === 'POST') {
        $token = trim((string)($_GET['token'] ?? ''));
        if ($token === '') json_response(400, ['error' => 'token fehlt']);
        if (!dm_claim_token($token)) {
            json_response(400, ['error' => 'Token invalid or already used']);
        }
        json_response(200, ['ok' => true]);

    } elseif ($path === 'dm/revoke' && $method === 'POST') {
        if (!dm_is_authenticated()) json_response(403, ['error' => 'nicht DM']);
        dm_revoke_current();
        json_response(200, ['ok' => true]);

    } else {
        json_response(404, ['error' => 'not found', 'path' => $path, 'method' => $method]);
    }
} catch (Throwable $e) {
    json_response(400, ['error' => $e->getMessage()]);
}


// ═══════════════════════════════════════════════════════════════════
//  D&D Beyond Adapter
// ═══════════════════════════════════════════════════════════════════

/**
 * Holt Charakter von D&D Beyond und konvertiert ins Dashboard-Format.
 * For "private" characters returns a privacy stub that the frontend
 * "🔒 Privater Charakter" Karte gerendert wird (selbe Logik wie zuvor).
 */
function fetch_dndb_character(int $id): array {
    $raw = http_get_json(DNDB_URL_BASE . $id);
    if ($raw === null || ($raw['success'] ?? false) !== true) {
        // Character is "private" on D&D Beyond — return a placeholder.
        return private_stub($id);
    }
    return adapt_dndb($raw['data'] ?? []);
}

function private_stub(int $id): array {
    $emptyStat = function(string $name, string $short) {
        return [
            'baseValue' => 0, 'name' => $name, 'shortName' => $short,
            'modifiers' => [], 'totalValue' => 0,
            'diceModifierAsText' => '(-5)', 'diceModifier' => -5,
        ];
    };
    return [
        'id' => $id,
        'characterName' => "$id is Private",
        'avatarUrl' => null, 'inspired' => false, 'raceName' => null,
        'level' => 0, 'proficiencyModifier' => 0,
        'currentHp' => 0, 'maxHp' => 0, 'hpModifier' => [],
        'armorClass' => 10, 'armorClassModifier' => [],
        'stats' => [
            'strength'     => $emptyStat('Strength','STR'),
            'dexterity'    => $emptyStat('Dexterity','DEX'),
            'constitution' => $emptyStat('Constitution','CON'),
            'intelligence' => $emptyStat('Intelligence','INT'),
            'wisdom'       => $emptyStat('Wisdom','WIS'),
            'charisma'     => $emptyStat('Charisma','CHA'),
        ],
        'skills' => (object)[],
        'classes' => null, 'languages' => [], 'inventory' => null,
        'proficientTools' => [], 'limitedActions' => [],
        'platinumPieces' => 0, 'goldPieces' => 0, 'electrumPieces' => 0,
        'silverPieces' => 0, 'copperPieces' => 0,
    ];
}

/**
 * Convert raw D&D Beyond character JSON into the simplified shape the
 * dashboard frontend expects (stats, classes, HP, AC, conditions, …).
 */
function adapt_dndb(array $d): array {
    $statMap   = [1=>'strength', 2=>'dexterity', 3=>'constitution', 4=>'intelligence', 5=>'wisdom', 6=>'charisma'];
    $statShort = [1=>'STR', 2=>'DEX', 3=>'CON', 4=>'INT', 5=>'WIS', 6=>'CHA'];
    $statFull  = [1=>'Strength', 2=>'Dexterity', 3=>'Constitution', 4=>'Intelligence', 5=>'Wisdom', 6=>'Charisma'];
    $scoreToId = ['strength-score'=>1,'dexterity-score'=>2,'constitution-score'=>3,
                  'intelligence-score'=>4,'wisdom-score'=>5,'charisma-score'=>6];

    // Map: item-definition-id → {equipped, attuned, requiresAttunement}
    // Wird gebraucht, um Item-Modifier zu filtern: Boni gelten nur, wenn das
    // the matching item is equipped AND (non-magical OR attuned).
    $itemStatus = [];
    foreach ($d['inventory'] ?? [] as $it) {
        $df = $it['definition'] ?? [];
        $defId = $df['id'] ?? null;
        if ($defId === null) continue;
        $itemStatus[$defId] = [
            'equipped'           => (bool)($it['equipped'] ?? false),
            'isAttuned'          => (bool)($it['isAttuned'] ?? false),
            'requiresAttunement' => (bool)($df['canAttune'] ?? false),
        ];
    }

    // Sammle alle Modifier aus allen Quellen — Item-Modifier nur wenn aktiv
    $allMods = [];
    foreach (['race','class','background','feat','condition'] as $cat) {
        foreach (($d['modifiers'][$cat] ?? []) as $m) $allMods[] = $m;
    }
    foreach (($d['modifiers']['item'] ?? []) as $m) {
        $cid = $m['componentId'] ?? null;
        if ($cid === null || !isset($itemStatus[$cid])) continue;
        $st = $itemStatus[$cid];
        $isActive = $st['equipped'] && (!$st['requiresAttunement'] || $st['isAttuned']);
        if ($isActive) $allMods[] = $m;
    }

    // ─── Stats ───
    $raw = $bon = $ovr = [];
    foreach ($d['stats']         ?? [] as $s) $raw[$s['id']] = (int)($s['value'] ?? 0);
    foreach ($d['bonusStats']    ?? [] as $s) $bon[$s['id']] = (int)($s['value'] ?? 0);
    foreach ($d['overrideStats'] ?? [] as $s) $ovr[$s['id']] = $s['value'];

    $statBonus = [1=>0,2=>0,3=>0,4=>0,5=>0,6=>0];
    $statSet   = [];   // sid → highest "set" value (e.g. Amulet of Health sets CON to 19)
    foreach ($allMods as $m) {
        $sub = $m['subType'] ?? '';
        $val = $m['value'] ?? null;
        if (($m['type'] ?? '') === 'bonus' && isset($scoreToId[$sub]) && $val !== null) {
            $statBonus[$scoreToId[$sub]] += (int)$val;
        } elseif (($m['type'] ?? '') === 'set' && isset($scoreToId[$sub]) && $val !== null) {
            $sid = $scoreToId[$sub];
            $iv  = (int)$val;
            if (!isset($statSet[$sid]) || $iv > $statSet[$sid]) $statSet[$sid] = $iv;
        }
    }

    $stats = [];
    foreach ($statMap as $sid => $key) {
        $base = $raw[$sid] ?? 0;
        $b    = $bon[$sid] ?? 0;
        $o    = $ovr[$sid] ?? null;
        $bm   = $statBonus[$sid];
        $sumStat = $base + $b + $bm;
        if ($o !== null) {
            $total = (int)$o;                                      // Override hat Vorrang
        } elseif (isset($statSet[$sid])) {
            $total = max($statSet[$sid], $sumStat);                // "set" only wins if higher than sum
        } else {
            $total = $sumStat;
        }
        $mod = (int)floor(($total - 10) / 2);
        $stats[$key] = [
            'baseValue' => $base, 'name' => $statFull[$sid], 'shortName' => $statShort[$sid],
            'modifiers' => [], 'totalValue' => $total,
            'diceModifierAsText' => sprintf('(%+d)', $mod), 'diceModifier' => $mod,
        ];
    }

    // ─── Classes + Total Level + Proficiency ───
    $classes = [];
    $totalLvl = 0;
    foreach ($d['classes'] ?? [] as $c) {
        $cn  = ($c['definition'] ?? [])['name'] ?? '?';
        $sub = (($c['subclassDefinition'] ?? null) ?? [])['name'] ?? null;
        $lvl = (int)($c['level'] ?? 0);
        $totalLvl += $lvl;
        $classes[] = ['name' => $cn, 'level' => $lvl, 'subclass' => $sub];
    }
    $profMod = $totalLvl >= 1 ? 2 + intdiv($totalLvl - 1, 4) : 0;

    // ─── HP ───
    $baseHp  = (int)($d['baseHitPoints'] ?? 0);
    $bonHp   = (int)($d['bonusHitPoints'] ?? 0);
    $ovrHp   = $d['overrideHitPoints'] ?? null;
    $removed = (int)($d['removedHitPoints'] ?? 0);
    $conMod  = $stats['constitution']['diceModifier'];
    $maxHp   = $ovrHp !== null ? (int)$ovrHp : ($baseHp + $bonHp + $conMod * $totalLvl);
    $currentHp = max(0, $maxHp - $removed);

    // ─── AC ───
    // Reihenfolge:
    //   1) Equipped Body-Armor (Light/Medium/Heavy) → setzt Base
    //   2) Sonst: Unarmored Defense (Monk/Barbarian) wenn vorhanden, sonst 10+DEX
    //   3) + Schild
    //   4) + bonus/unarmored-armor-class (nur wenn unarmored)
    //   5) + bonus/armor-class (immer)
    //   6) AC_OVERRIDES (if set for this character ID)
    $dexMod = $stats['dexterity']['diceModifier'];
    $hasBodyArmor = false;
    $hasShield    = false;
    $ac = 10 + $dexMod;

    foreach ($d['inventory'] ?? [] as $it) {
        if (!($it['equipped'] ?? false)) continue;
        $df  = $it['definition'] ?? [];
        $ati = $df['armorTypeId'] ?? null;
        $av  = $df['armorClass']  ?? null;
        if ($ati === 4) {                              // shield (counted later)
            $hasShield = true;
        } elseif ($ati === 1 && $av !== null) {        // Light
            $ac = (int)$av + $dexMod;
            $hasBodyArmor = true;
        } elseif ($ati === 2 && $av !== null) {        // Medium
            $ac = (int)$av + min($dexMod, 2);
            $hasBodyArmor = true;
        } elseif ($ati === 3 && $av !== null) {        // Heavy
            $ac = (int)$av;
            $hasBodyArmor = true;
        }
    }

    if (!$hasBodyArmor) {
        // Unarmored Defense (Monk: +WisMod, Barbarian: +ConMod, etc.)
        // Erkenntlich an: type=set, subType=unarmored-armor-class, statId=<1..6>
        $bestUnarmored = $ac; // Default: 10 + DexMod
        foreach ($allMods as $m) {
            if (($m['type'] ?? '') === 'set' && ($m['subType'] ?? '') === 'unarmored-armor-class') {
                $statId = $m['statId'] ?? null;
                if ($statId !== null && isset([1=>'strength',2=>'dexterity',3=>'constitution',
                                              4=>'intelligence',5=>'wisdom',6=>'charisma'][$statId])) {
                    $statKey = [1=>'strength',2=>'dexterity',3=>'constitution',
                                4=>'intelligence',5=>'wisdom',6=>'charisma'][$statId];
                    $extra = $stats[$statKey]['diceModifier'];
                    $candidate = 10 + $dexMod + $extra;
                    if ($candidate > $bestUnarmored) $bestUnarmored = $candidate;
                }
            }
        }
        $ac = $bestUnarmored;

        // Bonuses that ONLY apply when unarmored (e.g. Bracers of Defense)
        foreach ($allMods as $m) {
            if (($m['type'] ?? '') === 'bonus'
                && ($m['subType'] ?? '') === 'unarmored-armor-class'
                && ($m['value'] ?? null) !== null) {
                $ac += (int)$m['value'];
            }
        }
    }

    if ($hasShield) $ac += 2;

    // Allgemeine AC-Boni (Ring of Protection etc., gelten immer)
    foreach ($allMods as $m) {
        if (($m['type'] ?? '') === 'bonus' && ($m['subType'] ?? '') === 'armor-class'
            && ($m['value'] ?? null) !== null) {
            $ac += (int)$m['value'];
        }
    }

    // D&D Beyond "Customize"-Werte (Klick auf AC-Feld → Additional Magic / Misc Bonus)
    // typeId 2 = Additional Magic Bonus (z.B. Mage Armor)
    // typeId 3 = Additional Misc Bonus
    foreach ($d['characterValues'] ?? [] as $cv) {
        $tid = (int)($cv['typeId'] ?? 0);
        $val = $cv['value'] ?? null;
        if (($tid === 2 || $tid === 3) && is_numeric($val)) {
            $ac += (int)$val;
        }
    }

    // Manueller Override (Notausgang, falls D&D-Beyond-Customize nicht reicht)
    $charId = (int)($d['id'] ?? 0);
    if (isset(AC_OVERRIDES[$charId])) {
        $ac = (int)AC_OVERRIDES[$charId];
    }

    // ─── Skills ───
    $skillDefs = [
        ['acrobatics',     'Acrobatics',      'dexterity',    'DEXTERITY'],
        ['animalHandling', 'Animal Handling', 'wisdom',       'WISDOM'],
        ['arcana',         'Arcana',          'intelligence', 'INTELLIGENCE'],
        ['athletics',      'Athletics',       'strength',     'STRENGTH'],
        ['deception',      'Deception',       'charisma',     'CHARISMA'],
        ['history',        'History',         'intelligence', 'INTELLIGENCE'],
        ['insight',        'Insight',         'wisdom',       'WISDOM'],
        ['intimidation',   'Intimidation',    'charisma',     'CHARISMA'],
        ['investigation',  'Investigation',   'intelligence', 'INTELLIGENCE'],
        ['medicine',       'Medicine',        'wisdom',       'WISDOM'],
        ['nature',         'Nature',          'intelligence', 'INTELLIGENCE'],
        ['perception',     'Perception',      'wisdom',       'WISDOM'],
        ['performance',    'Performance',     'charisma',     'CHARISMA'],
        ['persuasion',     'Persuasion',      'charisma',     'CHARISMA'],
        ['religion',       'Religion',        'intelligence', 'INTELLIGENCE'],
        ['sleightOfHand',  'Sleight Of Hand', 'dexterity',    'DEXTERITY'],
        ['stealth',        'Stealth',         'dexterity',    'DEXTERITY'],
        ['survival',       'Survival',        'wisdom',       'WISDOM'],
    ];
    // skill-subtype (kebab-case "animal-handling") → key (camelCase "animalHandling")
    $subToKey = [];
    foreach ($skillDefs as $sd) {
        $kebab = strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', $sd[0]));
        $subToKey[$kebab] = $sd[0];
    }
    $levels = [];
    foreach ($skillDefs as $sd) $levels[$sd[0]] = 'NOT_PROFICIENT';
    $halfAll = false;
    foreach ($allMods as $m) {
        $t = $m['type'] ?? '';
        $s = $m['subType'] ?? '';
        if ($t === 'half-proficiency' && $s === 'ability-checks') $halfAll = true;
        if ($t === 'proficiency' && isset($subToKey[$s])) {
            $k = $subToKey[$s];
            if ($levels[$k] !== 'EXPERTISE') $levels[$k] = 'PROFICIENT';
        }
        if ($t === 'expertise' && isset($subToKey[$s])) {
            $levels[$subToKey[$s]] = 'EXPERTISE';
        }
    }
    if ($halfAll) {
        foreach ($levels as $k => $l) {
            if ($l === 'NOT_PROFICIENT') $levels[$k] = 'HALF_PROFICIENT';
        }
    }
    $skills = [];
    foreach ($skillDefs as [$key, $sname, $stat, $stype]) {
        $sm = $stats[$stat]['diceModifier'];
        $lvl = $levels[$key];
        $bonus = match ($lvl) {
            'PROFICIENT'      => $profMod,
            'EXPERTISE'       => 2 * $profMod,
            'HALF_PROFICIENT' => intdiv($profMod, 2),
            default           => 0,
        };
        $tot = $sm + $bonus;
        $skills[$key] = [
            'name' => $sname, 'type' => $stype, 'proficiencyLevel' => $lvl,
            'modifiers' => [], 'diceModifierAsText' => sprintf('(%+d)', $tot),
            'diceModifier' => $tot,
        ];
    }

    // ─── Walking Speed ───
    // Reihenfolge:
    //   1) customSpeeds (manueller Override im Character Sheet, movementId=1)
    //   2) race.weightSpeeds.override.walk (full override via Customize)
    //   3) race.weightSpeeds.normal.walk + alle relevanten Bonus-Modifier
    $walkSpeed = null;
    foreach (($d['customSpeeds'] ?? []) as $cs) {
        if ((int)($cs['movementId'] ?? 0) === 1 && isset($cs['distance'])) {
            $walkSpeed = (int)$cs['distance'];
            break;
        }
    }
    if ($walkSpeed === null) {
        $rs = ($d['race'] ?? [])['weightSpeeds'] ?? [];
        $ovrSpeed = ($rs['override']['walk'] ?? null);
        if ($ovrSpeed !== null) {
            $walkSpeed = (int)$ovrSpeed;
        } else {
            $walkSpeed = (int)(($rs['normal'] ?? [])['walk'] ?? 30);
            foreach ($allMods as $m) {
                $sub = $m['subType'] ?? '';
                $val = $m['value'] ?? null;
                if (($m['type'] ?? '') !== 'bonus' || $val === null) continue;
                if (in_array($sub, ['walking-speed', 'innate-speed-walking', 'speed', 'unarmored-movement'], true)) {
                    $walkSpeed += (int)$val;
                }
            }
        }
    }
    // 4) Manueller Override (Notausgang)
    $charIdForSpeed = (int)($d['id'] ?? 0);
    if (isset(SPEED_OVERRIDES[$charIdForSpeed])) {
        $walkSpeed = (int)SPEED_OVERRIDES[$charIdForSpeed];
    }

    // ─── Limited Actions / Spell Slots / Pact Slots ───
    $resetMap  = [1 => 'Short Rest', 2 => 'Long Rest', 3 => 'Daily', 4 => 'Long Rest', 5 => 'Charges'];
    $actionMap = [1 => 'Action', 2 => 'Bonus Action', 3 => 'Special', 4 => 'Reaction'];
    $statById  = [1=>'strength',2=>'dexterity',3=>'constitution',4=>'intelligence',5=>'wisdom',6=>'charisma'];

    // Limited-Use-Wert aus maxUses, statModifier und/oder ProfBonus berechnen.
    // D&D-Beyond-Schema:
    //   useProficiencyBonus=true + proficiencyBonusOperator=2 → max = profBonus * 2
    //                                                         (z.B. Soulknife Psionic Energy)
    //   useProficiencyBonus=true + Operator=1                 → max = base + profBonus
    //   maxUses=0 + statModifierUsesId                        → max = max(1, statMod)
    //                                                         (z.B. Bardic Inspiration = CHA-Mod)
    //   sonst                                                 → max = maxUses
    $resolveMax = function(array $lu) use ($stats, $profMod, $statById) {
        $base    = (int)($lu['maxUses'] ?? 0);
        $useProf = !empty($lu['useProficiencyBonus']);
        $profOp  = (int)($lu['proficiencyBonusOperator'] ?? 1);
        $statId  = $lu['statModifierUsesId'] ?? null;

        if ($useProf) {
            return $profOp === 2 ? max(1, $profMod * 2) : max(1, $base + $profMod);
        }
        if ($base === 0 && $statId !== null && isset($statById[(int)$statId])) {
            $mod = $stats[$statById[(int)$statId]]['diceModifier'] ?? 0;
            return max(1, $mod);
        }
        return $base;
    };

    $addLimited = function(array $lu, string $name, string $actionLabel, array &$out)
                  use ($resetMap, $resolveMax) {
        $max = $resolveMax($lu);
        if ($max < 1) return;
        $out[] = [
            'name'       => $name,
            'maxAmount'  => $max,
            'usedAmount' => (int)($lu['numberUsed'] ?? 0),
            'resetType'  => $resetMap[(int)($lu['resetType'] ?? 0)] ?? '—',
            'actionType' => $actionLabel,
        ];
    };

    $limited = [];

    // Aktionen aus Klasse / Race / Background / Feat / Item
    foreach (($d['actions'] ?? []) as $cat => $list) {
        foreach (($list ?? []) as $a) {
            $lu = $a['limitedUse'] ?? null;
            if (!$lu) continue;
            $addLimited($lu, (string)($a['name'] ?? '?'),
                       $actionMap[(int)($a['actionType'] ?? 0)] ?? '', $limited);
        }
    }

    // Items mit Charges — nur wenn ATTUNED.
    // (Potions / Items ohne Attunement-Slot werden nicht gelistet — sonst
    //  every potion in the backpack would bloat the overview otherwise.)
    foreach (($d['inventory'] ?? []) as $it) {
        $lu = $it['limitedUse'] ?? null;
        if (!$lu) continue;
        if (!($it['isAttuned'] ?? false)) continue;
        $name = ($it['definition'] ?? [])['name'] ?? '?';
        $addLimited($lu, $name, 'Item', $limited);
    }

    // (Einzel-Spells mit limitedUse — z.B. Magic Initiate's Mage Armor 1/Tag —
    //  are deliberately NOT listed — the slot-pool overview is enough.)

    // Spell Slots aus Multiclass-Tabelle (max wird vom Frontend in DDB gerechnet,
    // ist nicht im JSON — wir berechnen es selbst)
    static $MC_SLOTS = [
        1=>[2,0,0,0,0,0,0,0,0],   2=>[3,0,0,0,0,0,0,0,0],
        3=>[4,2,0,0,0,0,0,0,0],   4=>[4,3,0,0,0,0,0,0,0],
        5=>[4,3,2,0,0,0,0,0,0],   6=>[4,3,3,0,0,0,0,0,0],
        7=>[4,3,3,1,0,0,0,0,0],   8=>[4,3,3,2,0,0,0,0,0],
        9=>[4,3,3,3,1,0,0,0,0],  10=>[4,3,3,3,2,0,0,0,0],
       11=>[4,3,3,3,2,1,0,0,0], 12=>[4,3,3,3,2,1,0,0,0],
       13=>[4,3,3,3,2,1,1,0,0], 14=>[4,3,3,3,2,1,1,0,0],
       15=>[4,3,3,3,2,1,1,1,0], 16=>[4,3,3,3,2,1,1,1,0],
       17=>[4,3,3,3,2,1,1,1,1], 18=>[4,3,3,3,3,1,1,1,1],
       19=>[4,3,3,3,3,2,1,1,1], 20=>[4,3,3,3,3,2,2,1,1],
    ];

    // Effective Caster Level aus Multiclass berechnen.
    // IMPORTANT: a class only counts if it can actually cast spells
    // (e.g. Rogue has divisor=3 for Arcane Trickster, but not for Soulknife/Thief!)
    $effCasterLvl = 0;
    foreach (($d['classes'] ?? []) as $c) {
        $cd  = $c['definition'] ?? [];
        $sub = $c['subclassDefinition'] ?? null;
        $classCasts = (bool)($cd['canCastSpells'] ?? false);
        $subCasts   = $sub ? (bool)($sub['canCastSpells'] ?? false) : false;
        if (!$classCasts && !$subCasts) continue;   // this class doesn't contribute

        $div = ($cd['spellRules'] ?? [])['multiClassSpellSlotDivisor'] ?? null;
        if ($sub && isset($sub['spellRules']['multiClassSpellSlotDivisor'])) {
            $div = $sub['spellRules']['multiClassSpellSlotDivisor'];
        }
        $lvl  = (int)($c['level'] ?? 0);
        $name = $cd['name'] ?? '';
        if ($div !== null && $div > 0) {
            $effCasterLvl += intdiv($lvl, (int)$div);
        } elseif ($name === 'Artificer') {
            $effCasterLvl += (int)ceil($lvl / 2);   // Artificer rundet auf
        }
    }
    $maxByLevel = $MC_SLOTS[$effCasterLvl] ?? null;
    if ($maxByLevel) {
        // Used aus spellSlots[] mappen
        $usedByLevel = [];
        foreach (($d['spellSlots'] ?? []) as $s) {
            $usedByLevel[(int)($s['level'] ?? 0)] = (int)($s['used'] ?? 0);
        }
        for ($lvl = 1; $lvl <= 9; $lvl++) {
            $max = $maxByLevel[$lvl - 1];
            if ($max < 1) continue;
            $limited[] = [
                'name'       => "Spell Slot Lvl $lvl",
                'maxAmount'  => $max,
                'usedAmount' => min($max, $usedByLevel[$lvl] ?? 0),
                'resetType'  => 'Long Rest',
                'actionType' => 'Spell',
            ];
        }
    }

    // Pact Magic (Warlock)
    foreach (($d['pactMagic'] ?? []) as $s) {
        $avail = (int)($s['available'] ?? 0);
        $used  = (int)($s['used'] ?? 0);
        $max = $avail + $used;
        if ($max < 1) continue;
        $limited[] = [
            'name'       => 'Pact Slot Lvl ' . (int)($s['level'] ?? 0),
            'maxAmount'  => $max,
            'usedAmount' => $used,
            'resetType'  => 'Short Rest',
            'actionType' => 'Pact',
        ];
    }

    // ─── Languages & Tool-Proficiencies ───
    $languages = [];
    $toolProfs = [];
    $instruments = ['bagpipes','drum','dulcimer','flute','lute','lyre','horn','pan-flute','shawm','viol','harp','accordion','panpipes','whistle'];
    $gamingSets = ['dice-set','dragonchess-set','playing-card-set','three-dragon-ante-set'];
    $vehicles   = ['land-vehicles','water-vehicles'];
    $isTool = function(string $sub) use ($instruments, $gamingSets, $vehicles) {
        if (str_starts_with($sub, 'choose-')) return false;
        foreach (['-tools','-supplies','-kit','-utensils'] as $suf) {
            if (str_ends_with($sub, $suf)) return true;
        }
        return in_array($sub, $instruments, true)
            || in_array($sub, $gamingSets,  true)
            || in_array($sub, $vehicles,    true);
    };
    foreach ($allMods as $m) {
        $t   = $m['type']    ?? '';
        $sub = $m['subType'] ?? '';
        $fn  = $m['friendlySubtypeName'] ?? '';
        if ($t === 'language' && $sub !== '' && !str_starts_with($sub, 'choose-')) {
            $name = $fn !== '' ? $fn : ucfirst($sub);
            $languages[$name] = true;
        } elseif ($t === 'proficiency' && $isTool($sub)) {
            $name = $fn !== '' ? $fn : $sub;
            $toolProfs[$name] = true;
        }
    }
    // Custom-Proficiencies (vom Spieler frei eingetragen)
    foreach (($d['customProficiencies'] ?? []) as $cp) {
        $name = $cp['name'] ?? '';
        $type = (int)($cp['type'] ?? 0);   // 1=Tool, 2=Language (vermutet)
        if ($name === '') continue;
        if ($type === 2) $languages[$name] = true;
        else $toolProfs[$name] = true;
    }
    $languagesList = array_values(array_keys($languages));
    sort($languagesList);
    $toolList = array_values(array_keys($toolProfs));
    sort($toolList);

    // ─── Inventory: Magic Items + Party-Container ───
    // Custom-Names aus characterValues (typeId=8 = "Custom Item Name")
    $customItemNames = [];
    foreach (($d['characterValues'] ?? []) as $cv) {
        if ((int)($cv['typeId'] ?? 0) === 8 && isset($cv['valueId'])) {
            $customItemNames[(string)$cv['valueId']] = (string)($cv['value'] ?? '');
        }
    }
    // Container map: id → effective name (kebab/lowercased for matching)
    $containerKey = [];
    foreach (($d['inventory'] ?? []) as $it) {
        $df = $it['definition'] ?? [];
        if (!($df['isContainer'] ?? false)) continue;
        $iid = (string)($it['id'] ?? '');
        $name = $customItemNames[$iid] ?? ($df['name'] ?? '');
        // Normalisierung: lowercased, kein Whitespace, kein Sonderzeichen
        $key = strtolower(preg_replace('/[\s_\-]+/', '', trim($name ?? '')));
        $containerKey[$it['id']] = $key;
    }

    $magicItems = [];
    $partyItems = [];
    foreach (($d['inventory'] ?? []) as $it) {
        $df    = $it['definition'] ?? [];
        $ceid  = $it['containerEntityId'] ?? null;
        $cKey  = ($ceid !== null && isset($containerKey[$ceid])) ? $containerKey[$ceid] : '';

        // "nice2have" → komplett ignorieren
        if (str_contains($cKey, 'nice2have') || str_contains($cKey, 'nicetohave')) continue;

        $iid     = (string)($it['id'] ?? '');
        $name    = $customItemNames[$iid] ?? ($df['name'] ?? '?');
        $name    = trim($name);
        $rarity  = (string)($df['rarity'] ?? '') ?: 'Common';
        $isMagic = (bool)($df['magic'] ?? false);
        $canAtt  = (bool)($df['canAttune'] ?? false);

        $rec = [
            'name'     => $name,
            'qty'      => max(1, (int)($it['quantity'] ?? 1)),
            'rarity'   => $rarity,
            'magic'    => $isMagic,
            'attuned'  => (bool)($it['isAttuned'] ?? false),
            'equipped' => (bool)($it['equipped'] ?? false),
        ];

        // Party-Container → in Party-Liste (alle Items, nicht nur magic)
        if (str_contains($cKey, 'party') || str_contains($cKey, 'shared')) {
            $partyItems[] = $rec;
            continue;
        }
        // Otherwise: only magic items go into the personal magic list
        if (!$isMagic && !$canAtt) continue;
        if (!$canAtt && in_array($rarity, ['Common', 'Mundane'], true)) continue;
        $magicItems[] = $rec;
    }

    // ─── Conditions ───
    $conditions = [];
    foreach ($d['conditions'] ?? [] as $cnd) {
        $cid = (int)($cnd['id'] ?? 0);
        if (!isset(CONDITION_NAMES[$cid])) continue;
        $conditions[] = [
            'name'  => CONDITION_NAMES[$cid],
            'level' => (int)($cnd['level'] ?? 1),
        ];
    }

    // ─── Currencies ───
    $cur = $d['currencies'] ?? [];

    return [
        'id'                 => $d['id'] ?? 0,
        'characterName'      => $d['name'] ?? '?',
        'avatarUrl'          => ($d['decorations'] ?? [])['avatarUrl'] ?? null,
        'inspired'           => (bool)($d['inspiration'] ?? false),
        'raceName'           => ($d['race'] ?? [])['fullName'] ?? null,
        'level'              => $totalLvl,
        'proficiencyModifier'=> $profMod,
        'currentHp'          => $currentHp,
        'maxHp'              => $maxHp,
        'hpModifier'         => [],
        'armorClass'         => $ac,
        'armorClassModifier' => [],
        'stats'              => $stats,
        'skills'             => $skills,
        'classes'            => $classes,
        'conditions'         => $conditions,
        'languages'          => $languagesList,
        'inventory'          => null,
        'magicItems'         => $magicItems,
        'partyItems'         => $partyItems,
        'proficientTools'    => $toolList,
        'limitedActions'     => $limited,
        'walkSpeed'          => $walkSpeed,
        'platinumPieces'     => (int)($cur['pp'] ?? 0),
        'goldPieces'         => (int)($cur['gp'] ?? 0),
        'electrumPieces'     => (int)($cur['ep'] ?? 0),
        'silverPieces'       => (int)($cur['sp'] ?? 0),
        'copperPieces'       => (int)($cur['cp'] ?? 0),
    ];
}


/**
 * Holt JSON von einer URL (cURL bevorzugt, file_get_contents als Fallback).
 * Returns: array on success, null wenn upstream-Fehler / nicht-public.
 */
function http_get_json(string $url): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT      => 'DnDPartyDashboard/1.0 (PHP)',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) return null;
            // 403 = "Unauthorized Access Attempt" = privater Charakter
            if ($code === 403 || $code === 404) return null;
            $j = json_decode($body, true);
            return is_array($j) ? $j : null;
        }
    }
    // Fallback ohne cURL
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: DnDPartyDashboard/1.0 (PHP)\r\nAccept: application/json\r\n",
            'timeout'         => 20,
            'follow_location' => 1,
            'ignore_errors'   => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    // Status aus $http_response_header
    $code = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; break; }
        }
    }
    if ($code === 403 || $code === 404) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}


// ═══════════════════════════════════════════════════════════════════
//  Dice logic
// ═══════════════════════════════════════════════════════════════════

function parse_and_roll(string $expr): array {
    $s = preg_replace('/\s+/', '', $expr) ?? '';
    if ($s === '') throw new InvalidArgumentException('Empty dice expression');
    $s2 = str_replace('-', '+-', $s);
    if ($s2[0] === '+') $s2 = substr($s2, 1);
    $parts = array_filter(explode('+', $s2), static fn($p) => $p !== '');

    $diceGroups = [];
    $flatModifier = 0;
    foreach ($parts as $p) {
        // NdM[(kh|kl)X]  → optional "keep highest/lowest X" for advantage/disadvantage
        if (preg_match('/^(-?)(\d*)d(\d+)(?:(kh|kl)(\d*))?$/', $p, $m)) {
            $neg   = $m[1] === '-';
            $n     = $m[2] !== '' ? (int)$m[2] : 1;
            $sides = (int)$m[3];
            $kt    = $m[4] ?? '';                         // 'kh' | 'kl' | ''
            $kn    = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 1;
            if ($n < 1 || $n > 100) throw new InvalidArgumentException("Dice count must be 1-100: $p");
            if ($sides < 2 || $sides > 1000) throw new InvalidArgumentException("Die sides must be 2-1000: $p");
            if ($kt !== '' && ($kn < 1 || $kn > $n)) {
                throw new InvalidArgumentException("Invalid keep count: $p");
            }
            $sign = $neg ? -1 : 1;
            $values = [];
            for ($i = 0; $i < $n; $i++) $values[] = random_int(1, $sides);

            // which dice indices count?
            $kept = range(0, $n - 1);
            if ($kt === 'kh' || $kt === 'kl') {
                $idx = array_keys($values);
                usort($idx, $kt === 'kh'
                    ? static fn($a, $b) => $values[$b] - $values[$a]
                    : static fn($a, $b) => $values[$a] - $values[$b]);
                $kept = array_slice($idx, 0, $kn);
                sort($kept);   // stable display order
            }
            $sum = 0;
            foreach ($kept as $i) $sum += $values[$i];

            $diceGroups[] = [
                'notation' => "{$n}d{$sides}" . ($kt !== '' ? $kt . $kn : ''),
                'sign'     => $sign,
                'values'   => $values,
                'kept'     => $kept,
                'subtotal' => $sign * $sum,
            ];
        } else {
            if (!preg_match('/^-?\d+$/', $p)) throw new InvalidArgumentException("Invalid token: '$p'");
            $flatModifier += (int)$p;
        }
    }
    if (empty($diceGroups) && $flatModifier === 0) {
        throw new InvalidArgumentException('At least one die or modifier required');
    }
    $total = $flatModifier;
    foreach ($diceGroups as $g) $total += $g['subtotal'];
    return [
        'expression' => $s,
        'dice'       => $diceGroups,
        'modifier'   => $flatModifier,
        'total'      => $total,
    ];
}

function with_locked_state(callable $fn) {
    $fp = @fopen(ROLLS_FILE, 'c+');
    if (!$fp) throw new RuntimeException('Cannot open ' . ROLLS_FILE . ' — is the folder writable by the web server user?');
    try {
        flock($fp, LOCK_EX);
        $content = '';
        while (!feof($fp)) $content .= fread($fp, 8192);
        $state = $content !== '' ? json_decode($content, true) : null;
        if (!is_array($state)) $state = ['next_id' => 1, 'rolls' => []];
        $state = $fn($state);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $state;
}

function add_roll(string $who, string $expression, string $label): array {
    $result = parse_and_roll($expression);
    $newRoll = null;
    with_locked_state(function (array $state) use ($who, $expression, $label, $result, &$newRoll) {
        $rid = (int)($state['next_id'] ?? 1);
        $state['next_id'] = $rid + 1;
        $newRoll = array_merge([
            'id'    => $rid,
            'ts'    => (int)round(microtime(true) * 1000),
            'who'   => $who,
            'label' => $label,
        ], $result);
        $state['rolls'][] = $newRoll;
        if (count($state['rolls']) > MAX_ROLLS) {
            $state['rolls'] = array_slice($state['rolls'], -MAX_ROLLS);
        }
        return $state;
    });
    return $newRoll;
}

function get_rolls_since(int $sinceId): array {
    if (!file_exists(ROLLS_FILE)) return [];
    $content = @file_get_contents(ROLLS_FILE);
    if ($content === false || $content === '') return [];
    $state = json_decode($content, true);
    if (!is_array($state) || !isset($state['rolls'])) return [];
    $out = [];
    foreach ($state['rolls'] as $r) {
        if (isset($r['id']) && (int)$r['id'] > $sinceId) $out[] = $r;
    }
    return $out;
}

function clear_rolls(): int {
    $cleared = 0;
    with_locked_state(function (array $state) use (&$cleared) {
        $cleared = count($state['rolls'] ?? []);
        $state['rolls'] = [];
        return $state;
    });
    return $cleared;
}


// ═══════════════════════════════════════════════════════════════════
//  Helpers
// ═══════════════════════════════════════════════════════════════════

function json_response(int $code, $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}


// ═══════════════════════════════════════════════════════════════════
//  DM-Authentifizierung (Claim-Link / Token-Cookie)
// ═══════════════════════════════════════════════════════════════════

function dm_load_tokens(): array {
    if (!file_exists(DM_TOKENS_FILE)) return ['pending' => [], 'claimed' => []];
    $c = @file_get_contents(DM_TOKENS_FILE);
    $j = $c ? json_decode($c, true) : null;
    if (!is_array($j)) return ['pending' => [], 'claimed' => []];
    return ['pending' => $j['pending'] ?? [], 'claimed' => $j['claimed'] ?? []];
}

function dm_save_tokens(array $state): void {
    @file_put_contents(DM_TOKENS_FILE, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function dm_generate_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function dm_is_authenticated(): bool {
    $cookie = (string)($_COOKIE[DM_COOKIE_NAME] ?? '');
    if ($cookie === '') return false;
    $state = dm_load_tokens();
    foreach ($state['claimed'] as $c) {
        if (hash_equals((string)($c['token'] ?? ''), $cookie)) return true;
    }
    return false;
}

function dm_create_token(): string {
    $token = dm_generate_token();
    $state = dm_load_tokens();
    $state['pending'][] = ['token' => $token, 'created' => time()];
    // sweep pending tokens older than 7 days
    $cutoff = time() - 7 * 24 * 3600;
    $state['pending'] = array_values(array_filter(
        $state['pending'],
        static fn($t) => (int)($t['created'] ?? 0) >= $cutoff
    ));
    dm_save_tokens($state);
    return $token;
}

function dm_claim_token(string $token): bool {
    $state = dm_load_tokens();

    // Already claimed? → just refresh the cookie (DM cleared their cookies
    // or opened the same bookmark on a new device; URL stays reusable).
    foreach ($state['claimed'] as $c) {
        if (hash_equals((string)($c['token'] ?? ''), $token)) {
            dm_set_cookie($token);
            return true;
        }
    }

    // Sonst: aus 'pending' nach 'claimed' verschieben (Erst-Claim)
    $idx = null;
    foreach ($state['pending'] as $i => $t) {
        if (hash_equals((string)($t['token'] ?? ''), $token)) { $idx = $i; break; }
    }
    if ($idx === null) return false;
    array_splice($state['pending'], $idx, 1);
    $state['claimed'][] = [
        'token'   => $token,
        'claimed' => time(),
        'ua'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
    ];
    dm_save_tokens($state);
    dm_set_cookie($token);
    return true;
}

function dm_set_cookie(string $token): void {
    setcookie(DM_COOKIE_NAME, $token, [
        'expires'  => time() + 365 * 24 * 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function dm_revoke_current(): void {
    $cookie = (string)($_COOKIE[DM_COOKIE_NAME] ?? '');
    if ($cookie !== '') {
        $state = dm_load_tokens();
        $state['claimed'] = array_values(array_filter(
            $state['claimed'],
            static fn($c) => !hash_equals((string)($c['token'] ?? ''), $cookie)
        ));
        dm_save_tokens($state);
    }
    setcookie(DM_COOKIE_NAME, '', [
        'expires'  => 1, 'path' => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}
