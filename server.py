#!/usr/bin/env python3
"""Local development server for the D&D Party Dashboard.

Useful when you want to iterate on the HTML/CSS/JS without running PHP and
Apache. Two modes:

1. **Stub mode (default)** — no upstream needed. Serves canned example
   characters and an in-memory dice API so the dashboard boots end-to-end.
   Set DASHBOARD_UPSTREAM to enable proxy mode instead.

2. **Proxy mode** — set the DASHBOARD_UPSTREAM env var to a base URL of an
   existing api.php install (e.g. ``https://my-nas.example.com/party``).
   /api/groups/* and /api/characters/* are proxied there; the dice API
   stays in-memory locally.

Routes served either way:
    GET  /                  → dashboard.html
    GET  /api/config/json   → minimal config (stub-mode only)
    POST /api/roll          {who, expression, label?} → {ok, id}
    GET  /api/rolls?since=N → {rolls: [...]}
    POST /api/rolls/clear   → {ok}

Stdlib only — no external dependencies.
"""
import http.server
import json
import os
import random
import re
import socketserver
import ssl
import sys
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path

PORT = int(os.environ.get("DASHBOARD_PORT", "8765"))
UPSTREAM = os.environ.get("DASHBOARD_UPSTREAM", "").rstrip("/")
ROOT = Path(__file__).resolve().parent
MAX_ROLLS = 500


# ─── SSL context (find a usable CA bundle across distros) ───
def make_ssl_context():
    candidates = [
        ssl.get_default_verify_paths().openssl_cafile,
        "/etc/ssl/cert.pem",                              # macOS system
        "/etc/ssl/certs/ca-certificates.crt",             # Debian/Ubuntu
        "/etc/pki/tls/certs/ca-bundle.crt",               # RHEL/Fedora
        "/opt/homebrew/etc/openssl@3/cert.pem",           # Homebrew (Apple Silicon)
        "/usr/local/etc/openssl@3/cert.pem",              # Homebrew (Intel)
    ]
    for path in candidates:
        if path and Path(path).exists():
            try:
                return ssl.create_default_context(cafile=path)
            except Exception:
                continue
    print(
        "⚠  No CA bundle found — SSL verification DISABLED.",
        file=sys.stderr,
    )
    return ssl._create_unverified_context()


SSL_CTX = make_ssl_context()


# ─── Stub character data (used when DASHBOARD_UPSTREAM is empty) ────────
#
# These records mirror the shape produced by api.php::adapt_dndb() so the
# dashboard's buildCard() finds every field it expects. If you see
# "undefined" anywhere in the UI while running in stub mode, the
# corresponding field is missing here — add it.

STAT_FULL = {
    "strength": "Strength", "dexterity": "Dexterity", "constitution": "Constitution",
    "intelligence": "Intelligence", "wisdom": "Wisdom", "charisma": "Charisma",
}
STAT_SHORT = {
    "strength": "STR", "dexterity": "DEX", "constitution": "CON",
    "intelligence": "INT", "wisdom": "WIS", "charisma": "CHA",
}


def _stat(key: str, total: int) -> dict:
    mod = (total - 10) // 2
    return {
        "name":      STAT_FULL[key],
        "shortName": STAT_SHORT[key],
        "baseValue": total,
        "totalValue": total,
        "modifiers": [],
        "modifier":  mod,
        "diceModifier":       mod,
        "diceModifierAsText": f"({mod:+d})",
    }


# 18 skills as on a D&D Beyond sheet. Each skill maps to its key ability.
_SKILL_ABILITY = {
    "Acrobatics": "dexterity",       "Animal Handling": "wisdom",
    "Arcana": "intelligence",        "Athletics": "strength",
    "Deception": "charisma",         "History": "intelligence",
    "Insight": "wisdom",             "Intimidation": "charisma",
    "Investigation": "intelligence", "Medicine": "wisdom",
    "Nature": "intelligence",        "Perception": "wisdom",
    "Performance": "charisma",       "Persuasion": "charisma",
    "Religion": "intelligence",      "Sleight of Hand": "dexterity",
    "Stealth": "dexterity",          "Survival": "wisdom",
}


def _skills_for(stats: dict, prof_mod: int, proficient_in: set, expertise_in: set) -> dict:
    out = {}
    for sname, abil_key in _SKILL_ABILITY.items():
        ability_mod = stats[abil_key]["diceModifier"]
        if sname in expertise_in:
            total = ability_mod + 2 * prof_mod
            lvl = "EXPERTISE"
        elif sname in proficient_in:
            total = ability_mod + prof_mod
            lvl = "PROFICIENT"
        else:
            total = ability_mod
            lvl = "NONE"
        out[sname.lower().replace(" ", "_")] = {
            "name": sname,
            "type": "ability",
            "proficiencyLevel": lvl,
            "modifiers": [],
            "diceModifier": total,
            "diceModifierAsText": f"({total:+d})",
        }
    return out


def _stub_character(cid, name, race, klass, subclass, level, prof_mod,
                    stat_vals, cur_hp, max_hp, ac, walk_speed,
                    proficient_in=(), expertise_in=(),
                    conditions=(), magic_items=(), limited_actions=(),
                    inspired=False, currencies=None):
    stats = {k: _stat(k, v) for k, v in stat_vals.items()}
    return {
        "id":                  cid,
        "characterName":       name,
        "avatarUrl":           None,
        "inspired":            inspired,
        "raceName":            race,
        "level":               level,
        "proficiencyModifier": prof_mod,
        "currentHp":           cur_hp,
        "maxHp":               max_hp,
        "tempHp":              0,
        "hpModifier":          [],
        "armorClass":          ac,
        "armorClassModifier":  [],
        "stats":               stats,
        "skills":              _skills_for(stats, prof_mod, set(proficient_in), set(expertise_in)),
        "classes":             [{"name": klass, "subclass": subclass, "level": level}],
        "conditions":          list(conditions),
        "languages":           ["Common"],
        "inventory":           None,
        "magicItems":          list(magic_items),
        "partyItems":          [],
        "proficientTools":     [],
        "limitedActions":      list(limited_actions),
        "walkSpeed":           walk_speed,
        "platinumPieces":      (currencies or {}).get("pp", 0),
        "goldPieces":          (currencies or {}).get("gp", 25),
        "electrumPieces":      (currencies or {}).get("ep", 0),
        "silverPieces":        (currencies or {}).get("sp", 8),
        "copperPieces":        (currencies or {}).get("cp", 14),
    }


STUB_CHARACTERS = {
    1001: _stub_character(
        1001, "Alyx Stormvein", "Half-Elf", "Sorcerer", "Wild Magic", 5, 3,
        stat_vals={"strength": 8, "dexterity": 14, "constitution": 14,
                   "intelligence": 12, "wisdom": 10, "charisma": 18},
        cur_hp=28, max_hp=33, ac=13, walk_speed=30,
        proficient_in=("Arcana", "Persuasion", "Insight"),
        expertise_in=(),
        magic_items=[
            {"name": "Wand of Magic Missiles", "qty": 1, "rarity": "Uncommon",
             "magic": True, "attuned": False, "equipped": True},
        ],
        limited_actions=[
            {"name": "Sorcery Points", "maxAmount": 5, "usedAmount": 2,
             "resetType": "Long Rest", "actionType": "Class Feature"},
            {"name": "Spell Slot Lvl 1", "maxAmount": 4, "usedAmount": 1,
             "resetType": "Long Rest", "actionType": "Spell"},
            {"name": "Spell Slot Lvl 2", "maxAmount": 3, "usedAmount": 0,
             "resetType": "Long Rest", "actionType": "Spell"},
            {"name": "Spell Slot Lvl 3", "maxAmount": 2, "usedAmount": 0,
             "resetType": "Long Rest", "actionType": "Spell"},
        ],
        inspired=True,
        currencies={"gp": 42, "sp": 15, "cp": 30},
    ),
    1002: _stub_character(
        1002, "Bram Ironbeard", "Hill Dwarf", "Fighter", "Battle Master", 5, 3,
        stat_vals={"strength": 17, "dexterity": 12, "constitution": 16,
                   "intelligence": 10, "wisdom": 13, "charisma": 8},
        cur_hp=44, max_hp=49, ac=18, walk_speed=25,
        proficient_in=("Athletics", "Intimidation", "Survival"),
        expertise_in=(),
        conditions=[{"name": "Poisoned", "level": 1}],
        magic_items=[
            {"name": "Plate Armor +1", "qty": 1, "rarity": "Rare",
             "magic": True, "attuned": True, "equipped": True},
            {"name": "Potion of Healing", "qty": 3, "rarity": "Common",
             "magic": True, "attuned": False, "equipped": False},
        ],
        limited_actions=[
            {"name": "Second Wind", "maxAmount": 1, "usedAmount": 1,
             "resetType": "Short Rest", "actionType": "Class Feature"},
            {"name": "Action Surge", "maxAmount": 1, "usedAmount": 0,
             "resetType": "Short Rest", "actionType": "Class Feature"},
            {"name": "Superiority Dice (d8)", "maxAmount": 4, "usedAmount": 2,
             "resetType": "Short Rest", "actionType": "Class Feature"},
        ],
        currencies={"gp": 87, "sp": 5, "cp": 12},
    ),
    1003: _stub_character(
        1003, "Mira Wildleaf", "Wood Elf", "Druid", "Circle of the Moon", 5, 3,
        stat_vals={"strength": 10, "dexterity": 14, "constitution": 14,
                   "intelligence": 13, "wisdom": 18, "charisma": 11},
        cur_hp=36, max_hp=38, ac=15, walk_speed=35,
        proficient_in=("Nature", "Perception", "Animal Handling", "Medicine"),
        expertise_in=(),
        magic_items=[
            {"name": "Druidic Focus (sprig of mistletoe)", "qty": 1,
             "rarity": "Common", "magic": False, "attuned": False, "equipped": True},
        ],
        limited_actions=[
            {"name": "Wild Shape", "maxAmount": 2, "usedAmount": 1,
             "resetType": "Short Rest", "actionType": "Class Feature"},
            {"name": "Spell Slot Lvl 1", "maxAmount": 4, "usedAmount": 2,
             "resetType": "Long Rest", "actionType": "Spell"},
            {"name": "Spell Slot Lvl 2", "maxAmount": 3, "usedAmount": 1,
             "resetType": "Long Rest", "actionType": "Spell"},
            {"name": "Spell Slot Lvl 3", "maxAmount": 2, "usedAmount": 0,
             "resetType": "Long Rest", "actionType": "Spell"},
        ],
        currencies={"gp": 18, "sp": 22, "cp": 6},
    ),
}

STUB_CONFIG = {
    "group_name":    "Stub Party",
    "group_slug":    "stub",
    "character_ids": list(STUB_CHARACTERS.keys()),
    # Maintainer's Ko-fi handle so the footer button shows up in dev mode.
    # If you fork this repo: change this to your own Ko-fi username, or set
    # it to "" to hide the button while testing locally.
    "kofi_username": "smokinaleks",
}


# ─── Dice logic (server-side, blind from the player) ───
_lock = threading.Lock()
_rolls = []
_next_id = [1]


def parse_and_roll(expr: str) -> dict:
    """Parse '1d20+3', '2d6-1', '4d6' etc. and roll server-side."""
    s = (expr or "").strip().replace(" ", "")
    if not s:
        raise ValueError("empty dice expression")
    s2 = s.replace("-", "+-")
    if s2.startswith("+"):
        s2 = s2[1:]
    parts = [p for p in s2.split("+") if p]

    dice_groups = []
    flat_modifier = 0
    for p in parts:
        m = re.match(r"^(-?)(\d*)d(\d+)$", p)
        if m:
            neg, n_str, sides_str = m.groups()
            n = int(n_str) if n_str else 1
            sides = int(sides_str)
            if not (1 <= n <= 100):
                raise ValueError(f"dice count must be 1-100: {p}")
            if not (2 <= sides <= 1000):
                raise ValueError(f"die sides must be 2-1000: {p}")
            sign = -1 if neg else 1
            values = [random.randint(1, sides) for _ in range(n)]
            dice_groups.append({
                "notation": f"{n}d{sides}",
                "sign": sign,
                "values": values,
                "subtotal": sign * sum(values),
            })
        else:
            try:
                flat_modifier += int(p)
            except ValueError:
                raise ValueError(f"invalid token: {p!r}")

    if not dice_groups and flat_modifier == 0:
        raise ValueError("at least one die or modifier required")

    total = sum(g["subtotal"] for g in dice_groups) + flat_modifier
    return {
        "expression": s,
        "dice": dice_groups,
        "modifier": flat_modifier,
        "total": total,
    }


def add_roll(who: str, expression: str, label: str) -> dict:
    result = parse_and_roll(expression)
    with _lock:
        rid = _next_id[0]
        _next_id[0] += 1
        roll = {
            "id": rid,
            "ts": int(time.time() * 1000),
            "who": who,
            "label": label or "",
            **result,
        }
        _rolls.append(roll)
        if len(_rolls) > MAX_ROLLS:
            del _rolls[: len(_rolls) - MAX_ROLLS]
        return roll


def get_rolls_since(since_id: int) -> list:
    with _lock:
        return [r for r in _rolls if r["id"] > since_id]


def clear_rolls() -> int:
    with _lock:
        n = len(_rolls)
        _rolls.clear()
        return n


# ─── HTTP handler ───
class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kw):
        super().__init__(*args, directory=str(ROOT), **kw)

    def end_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Cache-Control", "no-store, no-cache, must-revalidate")
        self.send_header("Pragma", "no-cache")
        self.send_header("Expires", "0")
        super().end_headers()

    def do_OPTIONS(self):
        self.send_response(204)
        self.end_headers()

    def do_GET(self):
        path = self.path.split("?", 1)[0]
        if path == "/api/rolls":
            return self._handle_rolls_get()
        if path == "/api/config/json":
            return self._handle_config_get()
        if path == "/api/dm/check":
            return self._handle_dm_check()
        if path.startswith("/api/groups") or path.startswith("/api/characters"):
            return self._proxy_or_stub()
        if self.path in ("", "/"):
            self.path = "/dashboard.html"
        return super().do_GET()

    def do_POST(self):
        path = self.path.split("?", 1)[0]
        if self.path == "/api/roll":
            return self._handle_roll_post()
        if self.path == "/api/rolls/clear":
            return self._handle_clear_post()
        if path == "/api/dm/setup":
            return self._handle_dm_setup()
        if path == "/api/dm/claim":
            return self._json(200, {"ok": True})  # stub: any token claim succeeds
        if path == "/api/dm/revoke":
            return self._json(200, {"ok": True})
        return self._json(404, {"error": "not found"})

    # ── DM stub endpoints ────────────────────────────────────────────
    # In stub mode there is no real authentication. The dev server treats
    # every visitor as DM so the full UI can be exercised locally without
    # juggling claim links. The real api.php enforces token + cookie auth.
    def _handle_dm_check(self):
        return self._json(200, {"is_dm": True})

    def _handle_dm_setup(self):
        # Hand back a working claim URL pointing at this same dev server,
        # so the setup flow is exercisable end-to-end.
        host = self.headers.get("Host") or f"localhost:{PORT}"
        scheme = "http"
        token = "stub-dev-token"
        return self._json(200, {
            "ok": True,
            "token": token,
            "url": f"{scheme}://{host}/?dm-claim={token}",
        })

    # ── helpers ──
    def _read_body(self) -> bytes:
        n = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(n) if n > 0 else b""

    def _json(self, code: int, data):
        body = json.dumps(data).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.end_headers()
        self.wfile.write(body)

    # ── /api/config/json (stub mode only — proxy mode forwards from upstream) ──
    def _handle_config_get(self):
        if not UPSTREAM:
            return self._json(200, STUB_CONFIG)
        # In proxy mode, defer to upstream (lets you test the full server).
        return self._proxy_or_stub()

    # ── /api/rolls ──
    def _handle_rolls_get(self):
        since = 0
        if "?" in self.path:
            qs = self.path.split("?", 1)[1]
            for kv in qs.split("&"):
                if kv.startswith("since="):
                    try:
                        since = int(kv.split("=", 1)[1])
                    except ValueError:
                        pass
        return self._json(200, {"rolls": get_rolls_since(since)})

    # ── /api/roll ──
    def _handle_roll_post(self):
        try:
            data = json.loads(self._read_body() or b"{}")
        except json.JSONDecodeError as e:
            return self._json(400, {"error": f"invalid JSON: {e}"})
        who = (data.get("who") or "").strip()
        expression = (data.get("expression") or "").strip()
        label = (data.get("label") or "").strip()
        if not who:
            return self._json(400, {"error": "'who' missing"})
        if not expression:
            return self._json(400, {"error": "'expression' missing"})
        try:
            roll = add_roll(who, expression, label)
        except ValueError as e:
            return self._json(400, {"error": str(e)})
        # Blind roll: the player who threw only gets confirmation, no value.
        return self._json(200, {"ok": True, "id": roll["id"]})

    # ── /api/rolls/clear ──
    def _handle_clear_post(self):
        n = clear_rolls()
        return self._json(200, {"ok": True, "cleared": n})

    # ── /api/groups + /api/characters → proxy upstream, or serve stub ──
    def _proxy_or_stub(self):
        # Stub mode: synthesize responses entirely from STUB_CHARACTERS.
        if not UPSTREAM:
            path = self.path.split("?", 1)[0]
            if path == "/api/groups/json":
                return self._json(200, [{
                    "name":    STUB_CONFIG["group_slug"],
                    "members": STUB_CONFIG["character_ids"],
                }])
            m = re.match(r"^/api/characters/(\d+)/json$", path)
            if m:
                cid = int(m.group(1))
                if cid in STUB_CHARACTERS:
                    return self._json(200, STUB_CHARACTERS[cid])
                return self._json(200, {"id": cid, "characterName": f"{cid} is Private"})
            return self._json(404, {"error": "not found"})

        # Proxy mode: forward the request as-is.
        url = UPSTREAM + self.path
        try:
            req = urllib.request.Request(
                url,
                headers={
                    "User-Agent": "DnDPartyDashboard-DevServer/1.0",
                    "Accept": "application/json",
                },
            )
            with urllib.request.urlopen(req, timeout=20, context=SSL_CTX) as r:
                body = r.read()
                self.send_response(r.status)
                self.send_header(
                    "Content-Type",
                    r.headers.get("Content-Type", "application/json"),
                )
                self.end_headers()
                self.wfile.write(body)
        except urllib.error.HTTPError as e:
            self._json(e.code, {"error": f"upstream {e.code}"})
        except Exception as e:
            self._json(502, {"error": f"proxy: {e}"})

    def log_message(self, fmt, *args):
        sys.stderr.write(f"[{self.log_date_time_string()}] {fmt % args}\n")


def main():
    socketserver.ThreadingTCPServer.allow_reuse_address = True
    mode = f"proxy → {UPSTREAM}" if UPSTREAM else "stub mode (canned example party)"
    with socketserver.ThreadingTCPServer(("127.0.0.1", PORT), Handler) as httpd:
        print(f"D&D Party Dashboard dev server  →  http://localhost:{PORT}/")
        print(f"Mode: {mode}")
        print("Dice API: POST /api/roll · GET /api/rolls · POST /api/rolls/clear")
        print("Ctrl-C to stop.")
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\nBye!")


if __name__ == "__main__":
    main()
