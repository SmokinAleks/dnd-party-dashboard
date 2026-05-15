<?php
/**
 * D&D Party Dashboard — local configuration template.
 *
 * SETUP:
 *   1. Copy this file:           cp config.example.php config.php
 *   2. Edit config.php and fill in your party's data.
 *   3. Never commit config.php — it is in .gitignore.
 *
 * Reload the dashboard after changes. There is no caching layer.
 */

return [

    // ── Party identity ──────────────────────────────────────────────────────

    /**
     * Display name shown in the browser tab and in the dashboard header.
     * Example: 'The Sunday Night Crew', 'Tomb Crawlers', 'Critical Misses'.
     */
    'group_name'    => 'My Party',

    /**
     * Internal slug for cookie and localStorage namespacing.
     * Lowercase letters, digits and dashes only. Stays the same forever —
     * changing it logs every DM out and orphans saved identities.
     */
    'group_slug'    => 'party',


    // ── Characters ──────────────────────────────────────────────────────────

    /**
     * D&D Beyond character IDs to display.
     *
     * To find an ID: open the character sheet on dndbeyond.com — the URL is
     *   https://www.dndbeyond.com/characters/12345678
     * The number at the end is the ID.
     *
     * IMPORTANT: each character must be set to "Public" on D&D Beyond,
     * otherwise the dashboard cannot read it.
     */
    'character_ids' => [
        // 12345678,
        // 23456789,
    ],


    // ── Optional overrides ──────────────────────────────────────────────────

    /**
     * Override Armor Class if D&D Beyond's automatic calculation is wrong
     * (common with Mage Armor, Bracers of Defense, custom items, etc.).
     * Format: characterId => AC
     */
    'ac_overrides'    => [
        // 12345678 => 18,
    ],

    /**
     * Override walking speed if D&D Beyond doesn't expose it in the JSON
     * (e.g. when the bonus is only written in the character description).
     * Format: characterId => speed in feet
     */
    'speed_overrides' => [
        // 12345678 => 35,
    ],


    // ── Support / donations ─────────────────────────────────────────────────

    /**
     * Your Ko-fi username. If set, a small "Support on Ko-fi" button appears
     * in the footer. Leave empty to hide the button entirely.
     * Example: 'yourname' → links to https://ko-fi.com/yourname
     */
    'kofi_username' => '',

];
