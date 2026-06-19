import { readdir, readFile, stat } from 'node:fs/promises';
import { join, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(fileURLToPath(new URL('..', import.meta.url)));
const docsDir = join(root, 'docs');
const builtDir = join(root, '_site');
const rawHtmlPattern = /<\/?(?:div|span|p|a|img|script|style|iframe|table|tr|td|th|h[1-6]|section|article|button)\b/i;

async function walk(dir) {
  const entries = await readdir(dir, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...await walk(full));
    } else {
      files.push(full);
    }
  }

  return files;
}

const markdownFiles = (await walk(docsDir)).filter((file) => extname(file) === '.md');
const markdownFailures = [];

for (const file of markdownFiles) {
  const content = await readFile(file, 'utf8');
  if (rawHtmlPattern.test(content)) {
    markdownFailures.push(file);
  }
  if (content.includes('::: button')) {
    markdownFailures.push(`${file} uses forbidden ::: button`);
  }
}

if (markdownFailures.length > 0) {
  console.error('Raw HTML or forbidden button container found in Markdown:');
  for (const failure of markdownFailures) console.error(`- ${failure}`);
  process.exit(1);
}

try {
  await stat(builtDir);
  const htmlFiles = (await walk(builtDir)).filter((file) => extname(file) === '.html');
  const leakedContainers = [];

  for (const file of htmlFiles) {
    const content = await readFile(file, 'utf8');
    if (content.includes(':::')) {
      leakedContainers.push(file);
    }
  }

  if (leakedContainers.length > 0) {
    console.error('Visible docmd container markers leaked into built HTML:');
    for (const failure of leakedContainers) console.error(`- ${failure}`);
    process.exit(1);
  }
} catch {
  // Build output is optional for the pre-build check.
}

console.log(`Checked ${markdownFiles.length} Markdown files: no raw HTML and no leaked containers.`);
