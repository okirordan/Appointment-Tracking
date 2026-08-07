/**
 * One-off normaliser for resources/css/app.css.
 *
 * The stylesheet grew 24 distinct font sizes (many fractional), 6 font weights
 * — two of which no loaded face can render — and 17 corner radii. This snaps
 * every declaration onto the scale defined at the top of the file and drops the
 * all-caps label treatment the registry screens used for field labels.
 *
 * Run with: node scripts/normalise-css.mjs
 */
import fs from 'node:fs';

const FILE = 'resources/css/app.css';

const FONT_SIZE = {
    8.5: 11,
    9: 11,
    10: 11,
    10.5: 11,
    11: 11,
    11.5: 12,
    12: 12,
    12.5: 13,
    13: 13,
    13.5: 13,
    14: 14,
    15: 16,
    15.5: 16,
    16: 16,
    17: 18,
    18: 18,
    19: 20,
    20: 20,
    22: 22,
    23: 22,
    25: 26,
    26: 26,
    28: 26,
    32: 32,
};

const FONT_WEIGHT = { 650: 600, 750: 700, 800: 700 };

const RADIUS = {
    3: 6,
    4: 6,
    7: 10,
    8: 10,
    9: 10,
    10: 10,
    11: 10,
    12: 14,
    13: 14,
    14: 14,
    15: 14,
    16: 14,
    18: 20,
    20: 20,
    22: 20,
    24: 20,
    99: 999,
    999: 999,
};

let css = fs.readFileSync(FILE, 'utf8');
const counts = { size: 0, weight: 0, radius: 0, uppercase: 0, tracking: 0 };

// The token block itself defines the scale; leave it alone.
const guardStart = css.indexOf('/* Type scale');
const guardEnd = css.indexOf('}', css.indexOf('--radius-pill'));
const head = css.slice(0, guardStart);
const guarded = css.slice(guardStart, guardEnd);
let body = css.slice(guardEnd);

body = body.replace(/font-size:\s*([\d.]+)px/g, (m, v) => {
    const next = FONT_SIZE[Number(v)];
    if (next === undefined || next === Number(v)) return m;
    counts.size += 1;
    return `font-size: ${next}px`;
});

body = body.replace(/font-weight:\s*(\d+)/g, (m, v) => {
    const next = FONT_WEIGHT[Number(v)];
    if (next === undefined) return m;
    counts.weight += 1;
    return `font-weight: ${next}`;
});

body = body.replace(/border-radius:\s*([\d.]+)px/g, (m, v) => {
    const next = RADIUS[Number(v)];
    if (next === undefined || next === Number(v)) return m;
    counts.radius += 1;
    return `border-radius: ${next}px`;
});

// Drop all-caps field labels. Letter-spacing only exists to make uppercase
// readable, so it goes with it — but only inside the same rule block.
const lines = body.split('\n');
const out = [];
let block = [];
let depth = 0;

const flush = () => {
    const hasUppercase = block.some((l) => /text-transform:\s*uppercase/.test(l));
    for (const line of block) {
        if (/text-transform:\s*uppercase/.test(line)) {
            counts.uppercase += 1;
            continue;
        }
        if (hasUppercase && /letter-spacing:\s*0?\.\d+em/.test(line)) {
            counts.tracking += 1;
            continue;
        }
        out.push(line);
    }
    block = [];
};

for (const line of lines) {
    const opens = (line.match(/\{/g) ?? []).length;
    const closes = (line.match(/\}/g) ?? []).length;
    if (depth > 0) block.push(line);
    else out.push(line);
    depth += opens - closes;
    if (depth === 0 && block.length) flush();
}
flush();

fs.writeFileSync(FILE, head + guarded + out.join('\n'), 'utf8');
console.log(counts);
