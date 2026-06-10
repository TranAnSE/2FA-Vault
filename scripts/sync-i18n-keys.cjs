#!/usr/bin/env node

/**
 * Sync i18n keys from en.json to all non-English locale files.
 * Missing keys are added with English text as placeholder.
 * Keys are sorted alphabetically for consistency.
 *
 * Usage: node scripts/sync-i18n-keys.cjs
 */

const fs = require('fs');
const path = require('path');

const enPath = path.join(__dirname, '../resources/lang/en.json');
const langDir = path.join(__dirname, '../resources/lang');

const enData = JSON.parse(fs.readFileSync(enPath, 'utf8'));
const enKeys = Object.keys(enData);

const localeFiles = fs.readdirSync(langDir)
    .filter(f => f.endsWith('.json') && f !== 'en.json');

let totalAdded = 0;

for (const locale of localeFiles) {
    const localePath = path.join(langDir, locale);
    const localeData = JSON.parse(fs.readFileSync(localePath, 'utf8'));

    let added = 0;
    for (const [key, value] of Object.entries(enData)) {
        if (!(key in localeData)) {
            localeData[key] = value;
            added++;
        }
    }

    // Sort keys alphabetically
    const sorted = Object.keys(localeData).sort().reduce((obj, key) => {
        obj[key] = localeData[key];
        return obj;
    }, {});

    fs.writeFileSync(localePath, JSON.stringify(sorted, null, 4) + '\n');
    console.log(`${locale}: added ${added} keys`);
    totalAdded += added;
}

console.log(`\nTotal: ${totalAdded} keys added across ${localeFiles.length} locales`);
console.log(`en.json has ${enKeys.length} keys`);
