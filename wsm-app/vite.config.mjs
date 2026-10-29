import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import {
  copyFile,
  mkdir,
  readFile,
  readdir,
  unlink,
  writeFile,
} from 'node:fs/promises';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const appRoot = dirname(fileURLToPath(import.meta.url));
// Runtime app-shell assets are required; Apache's deployment-only file is optional.
const requiredShellAssets = [
  'dexie-4.4.6.min.js',
  'icon-192.png',
  'icon-512.png',
  'manifest.json',
  'sw.js',
];
const optionalShellAssets = ['.htaccess'];
const appShellPaths = [
  './index.html',
  './connect.html',
  './desktop.html',
  './club.html',
  './manifest.json',
  './dexie-4.4.6.min.js',
  './icon-192.png',
  './icon-512.png',
];

async function listFilesRecursively(rootDir, directory = rootDir) {
  const files = [];
  const visit = async (currentDirectory) => {
    for (const entry of await readdir(currentDirectory, { withFileTypes: true })) {
      const filePath = resolve(currentDirectory, entry.name);
      if (entry.isDirectory()) {
        await visit(filePath);
      } else if (entry.isFile()) {
        const relativePath = relative(rootDir, filePath).split(sep).join('/');
        files.push(`./${relativePath}`);
      }
    }
  };

  await visit(directory);
  return files.sort();
}

async function rewriteManifestLinks(outputDir) {
  // Vite hashes a linked manifest into assets/, but the checked-in root manifest
  // uses root-relative icon files. Point both HTML entry pages at that canonical
  // manifest so its icon URLs resolve correctly after deployment.
  for (const name of ['index.html', 'desktop.html']) {
    const filePath = resolve(outputDir, name);
    let html = await readFile(filePath, 'utf8');
    let foundManifestLink = false;

    html = html.replace(/<link\b[^>]*>/gi, (tag) => {
      if (!/\brel\s*=\s*(["'])manifest\1/i.test(tag)) return tag;
      foundManifestLink = true;

      const hrefPattern = /\bhref\s*=\s*(["'])[^"']*\1/i;
      if (!hrefPattern.test(tag)) {
        throw new Error(`${name}: manifest link is missing a quoted href.`);
      }
      return tag.replace(hrefPattern, (_match, quote) => `href=${quote}./manifest.json${quote}`);
    });

    if (!foundManifestLink) {
      throw new Error(`${name}: expected a manifest <link> in the built HTML.`);
    }
    await writeFile(filePath, html, 'utf8');
  }
}

async function removeUnusedHashedManifests(outputDir) {
  // Vite emits a second, hashed copy of manifest.json solely because it was
  // linked from HTML. The canonical root copy is now linked; drop the orphaned
  // copy whose ./icon-*.png paths would otherwise resolve inside assets/.
  const assetsDir = resolve(outputDir, 'assets');
  if (!existsSync(assetsDir)) return;

  for (const entry of await readdir(assetsDir, { withFileTypes: true })) {
    if (entry.isFile() && /^manifest-[^/]+\.json$/i.test(entry.name)) {
      await unlink(resolve(assetsDir, entry.name));
    }
  }
}

async function syncServiceWorkerPrecache(outputDir) {
  const swPath = resolve(outputDir, 'sw.js');
  const assetsDir = resolve(outputDir, 'assets');
  const swSource = await readFile(swPath, 'utf8');
  const builtAssets = existsSync(assetsDir)
    ? await listFilesRecursively(outputDir, assetsDir)
    : [];
  const precachePaths = [...new Set([...appShellPaths, ...builtAssets])];

  // Hash file contents as well as URLs. A same-named file with changed contents
  // must produce a new cache name, and the activate handler will retire old ones.
  const hash = createHash('sha256').update(swSource);
  for (const url of precachePaths) {
    const filePath = resolve(outputDir, url.replace(/^\.\//, ''));
    const contents = await readFile(filePath);
    hash.update('\0').update(url).update('\0').update(contents);
  }
  const buildHash = hash.digest('hex').slice(0, 12);
  const cacheVersion = `wsm-offline-shell-${buildHash}`;

  let sw = swSource;
  const listPattern = /var APP_SHELL_PATHS = \[[\s\S]*?\];/;
  if ((sw.match(/var APP_SHELL_PATHS\s*=\s*\[/g) || []).length !== 1 || !listPattern.test(sw)) {
    throw new Error('sw.js: expected exactly one APP_SHELL_PATHS array to sync.');
  }
  sw = sw.replace(listPattern, `var APP_SHELL_PATHS = ${JSON.stringify(precachePaths, null, 4)};`);

  const versionPattern = /var VERSION = '[^']*';/g;
  const cachePattern = /var CACHE_NAME = '[^']*';/g;
  if ((sw.match(versionPattern) || []).length !== 1) {
    throw new Error('sw.js: expected exactly one VERSION declaration.');
  }
  if ((sw.match(cachePattern) || []).length !== 1) {
    throw new Error('sw.js: expected exactly one CACHE_NAME declaration.');
  }
  sw = sw
    .replace(versionPattern, `var VERSION = '${cacheVersion}';`)
    .replace(cachePattern, `var CACHE_NAME = '${cacheVersion}';`);

  await writeFile(swPath, sw, 'utf8');
  return { precachePaths, buildHash, cacheVersion };
}

async function verifyBuiltOutput(outputDir, expected) {
  const indexPath = resolve(outputDir, 'index.html');
  const html = await readFile(indexPath, 'utf8');
  const headClosures = (html.match(/<\/head\s*>/gi) || []).length;
  if (headClosures !== 1) {
    throw new Error(`index.html: expected exactly one </head>, found ${headClosures}.`);
  }

  const scriptOpenings = (html.match(/<script\b/gi) || []).length;
  const scriptClosures = (html.match(/<\/script\s*>/gi) || []).length;
  if (scriptOpenings !== scriptClosures) {
    throw new Error(
      `index.html: unbalanced script tags (${scriptOpenings} opening, ${scriptClosures} closing).`,
    );
  }

  const moduleTag = (html.match(/<script\b[^>]*\btype=["']module["'][^>]*><\/script\s*>/i) || [])[0];
  if (!moduleTag) {
    throw new Error('index.html: Vite module entry was not injected as a complete script tag.');
  }
  const modulePosition = html.indexOf(moduleTag);
  const closingHeadPosition = html.toLowerCase().indexOf('</head');
  if (closingHeadPosition < 0 || modulePosition > closingHeadPosition) {
    throw new Error('index.html: Vite module entry is not inside the document head.');
  }
  const srcMatch = moduleTag.match(/\bsrc\s*=\s*(["'])([^"']+)\1/i);
  if (!srcMatch) throw new Error('index.html: Vite module script is missing src.');
  const moduleUrl = srcMatch[2].split(/[?#]/, 1)[0];
  const moduleAssetPath = moduleUrl.replace(/^\.?\//, '');
  await readFile(resolve(outputDir, moduleAssetPath));
  const modulePrecachePath = `./${moduleAssetPath}`;
  if (!expected.precachePaths.includes(modulePrecachePath)) {
    throw new Error(`sw.js: Vite module entry ${modulePrecachePath} is not in the precache list.`);
  }

  for (const name of ['index.html', 'desktop.html']) {
    const page = await readFile(resolve(outputDir, name), 'utf8');
    const manifestLink = (page.match(/<link\b[^>]*>/gi) || []).find((tag) =>
      /\brel\s*=\s*(["'])manifest\1/i.test(tag),
    );
    if (!manifestLink || !/\bhref\s*=\s*(["'])\.\/manifest\.json\1/i.test(manifestLink)) {
      throw new Error(`${name}: manifest link does not target ./manifest.json.`);
    }
  }

  const connectionPage = await readFile(resolve(outputDir, 'connect.html'), 'utf8');
  for (const marker of ['id="wsm-connect-form"', 'id="wsm-test-internet"', 'id="wsm-test-site"']) {
    if (!connectionPage.includes(marker)) {
      throw new Error(`dist/connect.html: required connection UI marker ${marker} is missing.`);
    }
  }

  const manifest = JSON.parse(await readFile(resolve(outputDir, 'manifest.json'), 'utf8'));
  for (const icon of Array.isArray(manifest.icons) ? manifest.icons : []) {
    if (!icon || typeof icon.src !== 'string' || /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(icon.src)) continue;
    const iconPath = icon.src.split(/[?#]/, 1)[0].replace(/^\.?\//, '');
    await readFile(resolve(outputDir, iconPath));
  }

  const sw = await readFile(resolve(outputDir, 'sw.js'), 'utf8');
  const listMatch = sw.match(/var APP_SHELL_PATHS = (\[[\s\S]*?\]);/);
  if (!listMatch) throw new Error('dist/sw.js: generated APP_SHELL_PATHS array is missing.');
  const actualPaths = JSON.parse(listMatch[1]);
  if (JSON.stringify(actualPaths) !== JSON.stringify(expected.precachePaths)) {
    throw new Error('dist/sw.js: generated precache list does not match the build output.');
  }
  for (const url of actualPaths) {
    await readFile(resolve(outputDir, url.replace(/^\.\//, '')));
  }

  const version = (sw.match(/^var VERSION = '([^']*)';/m) || [])[1];
  const cacheName = (sw.match(/^var CACHE_NAME = '([^']*)';/m) || [])[1];
  if (version !== expected.cacheVersion || cacheName !== expected.cacheVersion) {
    throw new Error('dist/sw.js: cache version does not match the content hash for this build.');
  }

  console.info(
    `[wsm] Verified app shell: ${actualPaths.length} precached files; build ${expected.buildHash}.`,
  );
}

export default defineConfig({
  root: appRoot,
  // Relative URLs keep the generated app shell usable under Tauri's asset protocol.
  base: './',
  // The service worker and app-shell files are copied byte-for-byte after the HTML build.
  publicDir: false,
  plugins: [
    {
      name: 'wsm-build-app-shell-and-sync-service-worker',
      apply: 'build',
      async closeBundle() {
        const outputDir = resolve(appRoot, 'dist');
        await mkdir(outputDir, { recursive: true });

        // Fail the build if a runtime asset is missing; only .htaccess is optional.
        const requiredCopies = requiredShellAssets.map((name) =>
          copyFile(resolve(appRoot, name), resolve(outputDir, name)),
        );
        const optionalCopies = optionalShellAssets.map(async (name) => {
          try {
            await copyFile(resolve(appRoot, name), resolve(outputDir, name));
          } catch (error) {
            if (error?.code !== 'ENOENT') throw error;
          }
        });
        await Promise.all([...requiredCopies, ...optionalCopies]);

        // desktop.html is a self-contained Tauri launcher (apart from the root
        // manifest copied above). Keep it available even if an older/mismerged
        // Vite input map omits the entry; the offline-shell checks below still
        // validate the copied page and include it in the content-hashed precache.
        const builtDesktopPath = resolve(outputDir, 'desktop.html');
        if (!existsSync(builtDesktopPath)) {
          const sourceDesktopPath = resolve(appRoot, 'desktop.html');
          if (!existsSync(sourceDesktopPath)) {
            throw new Error('Vite did not emit dist/desktop.html and wsm-app/desktop.html is missing.');
          }
          await copyFile(sourceDesktopPath, builtDesktopPath);
          console.warn('[wsm] Vite did not emit desktop.html; copied the static Tauri shell.');
        }

        await rewriteManifestLinks(outputDir);
        await removeUnusedHashedManifests(outputDir);
        const expected = await syncServiceWorkerPrecache(outputDir);
        await verifyBuiltOutput(outputDir, expected);
      },
    },
  ],
  build: {
    outDir: resolve(appRoot, 'dist'),
    emptyOutDir: true,
    rollupOptions: {
      input: {
        index: resolve(appRoot, 'index.html'),
        connect: resolve(appRoot, 'connect.html'),
        club: resolve(appRoot, 'club.html'),
        desktop: resolve(appRoot, 'desktop.html'),
      },
    },
  },
});
