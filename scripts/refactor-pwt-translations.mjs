#!/usr/bin/env node
import fs from 'fs';
import path from 'path';

const root = process.cwd();
const srcDir = path.join(root, 'src');

const files = [];
function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(full);
      continue;
    }
    if (!entry.isFile()) {
      continue;
    }
    if (!full.endsWith('.twig') && !full.endsWith('.php')) {
      continue;
    }
    const content = fs.readFileSync(full, 'utf8');
    if (!content.includes("pragmatic-web-toolkit")) {
      continue;
    }
    files.push(full);
  }
}

walk(srcDir);

const keyToValue = new Map();
const usedKeys = new Set();

function toKebab(input) {
  return input
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .replace(/-{2,}/g, '-');
}

function fileNamespace(filePath) {
  const rel = path.relative(srcDir, filePath).replace(/\\/g, '/');
  const noExt = rel.replace(/\.(twig|php)$/i, '');
  const parts = noExt
    .split('/')
    .map((part) => toKebab(part))
    .filter(Boolean);
  return parts.join('.');
}

function sourceSlug(source) {
  const normalized = source
    .replace(/\{\{[^}]+\}\}/g, 'var')
    .replace(/\{[^}]+\}/g, 'param')
    .replace(/%[sdif]/gi, 'param')
    .replace(/&[a-z]+;/gi, 'entity')
    .trim();
  let slug = toKebab(normalized);
  if (!slug) {
    slug = 'text';
  }
  if (slug.length > 64) {
    slug = slug.slice(0, 64).replace(/-+$/g, '');
  }
  return slug;
}

function uniqueKey(baseKey, source) {
  let candidate = baseKey;
  let i = 2;
  while (usedKeys.has(candidate) && keyToValue.get(candidate) !== source) {
    candidate = `${baseKey}-${i}`;
    i += 1;
  }
  usedKeys.add(candidate);
  return candidate;
}

function mapKey(filePath, source) {
  const ns = fileNamespace(filePath);
  const slug = sourceSlug(source);
  const base = `${ns}.${slug}`;
  const key = uniqueKey(base, source);
  if (!keyToValue.has(key)) {
    keyToValue.set(key, source);
  }
  return key;
}

function replaceTwig(content, filePath) {
  let updated = content;
  // single quoted string|t('pragmatic-web-toolkit')
  updated = updated.replace(/'((?:\\'|[^'\n])*)'\s*\|\s*t\(\s*['"]pragmatic-web-toolkit['"]\s*\)/g, (_m, raw) => {
    const source = raw.replace(/\\'/g, "'");
    const key = mapKey(filePath, source);
    return `'${key}'|t('pragmatic-web-toolkit')`;
  });
  // double quoted string|t('pragmatic-web-toolkit')
  updated = updated.replace(/"((?:\\"|[^"\n])*)"\s*\|\s*t\(\s*['"]pragmatic-web-toolkit['"]\s*\)/g, (_m, raw) => {
    const source = raw.replace(/\\"/g, '"');
    const key = mapKey(filePath, source);
    return `'${key}'|t('pragmatic-web-toolkit')`;
  });
  return updated;
}

function replacePhp(content, filePath) {
  let updated = content;
  updated = updated.replace(/Craft::t\(\s*['"]pragmatic-web-toolkit['"]\s*,\s*'((?:\\'|[^'\n])*)'(\s*(?:,\s*[^)]*)?\))/g, (_m, raw, suffix) => {
    const source = raw.replace(/\\'/g, "'");
    const key = mapKey(filePath, source);
    return `Craft::t('pragmatic-web-toolkit', '${key}'${suffix}`;
  });
  updated = updated.replace(/Craft::t\(\s*['"]pragmatic-web-toolkit['"]\s*,\s*"((?:\\"|[^"\n])*)"(\s*(?:,\s*[^)]*)?\))/g, (_m, raw, suffix) => {
    const source = raw.replace(/\\"/g, '"');
    const key = mapKey(filePath, source);
    return `Craft::t('pragmatic-web-toolkit', '${key}'${suffix}`;
  });
  return updated;
}

let changed = 0;
for (const file of files) {
  const original = fs.readFileSync(file, 'utf8');
  let updated = original;
  if (file.endsWith('.twig')) {
    updated = replaceTwig(updated, file);
  } else if (file.endsWith('.php')) {
    updated = replacePhp(updated, file);
  }
  if (updated !== original) {
    fs.writeFileSync(file, updated);
    changed += 1;
  }
}

function writeLocale(locale) {
  const dir = path.join(srcDir, 'translations', locale);
  fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, 'pragmatic-web-toolkit.php');
  const keys = Array.from(keyToValue.keys()).sort((a, b) => a.localeCompare(b));
  const lines = ['<?php', '', 'return ['];
  for (const key of keys) {
    const value = keyToValue.get(key) ?? '';
    const escapedKey = key.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    const escapedValue = value.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    lines.push(`    '${escapedKey}' => '${escapedValue}',`);
  }
  lines.push('];', '');
  fs.writeFileSync(file, lines.join('\n'));
}

writeLocale('en');
writeLocale('es');
writeLocale('ca');

console.log(`Updated files: ${changed}`);
console.log(`Translation keys: ${keyToValue.size}`);
