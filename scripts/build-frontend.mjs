#!/usr/bin/env node
/** Build and validate the Vite app shell embedded by Tauri. */
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const appDir = join(root, 'wsm-app');
const distDir = join(appDir, 'dist');
const nodeModulesDir = join(appDir, 'node_modules');
const lockPath = join(appDir, 'package-lock.json');
const dependencyStampPath = join(nodeModulesDir, '.wsm-package-lock.sha256');

function ensureDesktopOutput() {
  const builtDesktopPath = join(distDir, 'desktop.html');
  if (existsSync(builtDesktopPath)) return;

  const sourceDesktopPath = join(appDir, 'desktop.html');
  if (!existsSync(sourceDesktopPath)) {
    throw new Error('Vite omitted dist/desktop.html and the source wsm-app/desktop.html is missing.');
  }

  mkdirSync(distDir, { recursive: true });
  copyFileSync(sourceDesktopPath, builtDesktopPath);
  console.warn('[frontend] Vite did not emit desktop.html; copied the static Tauri shell.');
}

function verifyDist() {
  const requiredFiles = [
    'index.html',
    'desktop.html',
    'club.html',
    'manifest.json',
    'sw.js',
    'dexie-4.4.6.min.js',
    'icon-192.png',
    'icon-512.png',
  ];
  for (const relativePath of requiredFiles) {
    if (!existsSync(join(distDir, relativePath))) {
      throw new Error(`Vite app shell is incomplete: dist/${relativePath} is missing.`);
    }
  }

  const html = readFileSync(join(distDir, 'index.html'), 'utf8');
  const moduleTag = html.match(/<script\b[^>]*\btype=["']module["'][^>]*><\/script\s*>/i)?.[0];
  const moduleSrc = moduleTag?.match(/\bsrc\s*=\s*(["'])([^"']+)\1/i)?.[2];
  if (!moduleSrc) throw new Error('Built index.html has no Vite module entry.');

  const sw = readFileSync(join(distDir, 'sw.js'), 'utf8');
  const list = sw.match(/var APP_SHELL_PATHS = (\[[\s\S]*?\]);/);
  if (!list) throw new Error('Built sw.js has no generated APP_SHELL_PATHS array.');
  const precachePaths = JSON.parse(list[1]);
  if (!precachePaths.includes(moduleSrc)) {
    throw new Error(`Built service worker does not precache the Vite entry ${moduleSrc}.`);
  }
  for (const url of precachePaths) {
    const localPath = resolve(distDir, String(url).replace(/^\.\//, ''));
    if (!localPath.startsWith(`${distDir}/`) && !localPath.startsWith(`${distDir}\\`)) {
      throw new Error(`Unsafe app-shell precache path: ${url}`);
    }
    if (!existsSync(localPath)) throw new Error(`Precache file is missing: ${url}`);
  }
}

function runNpm(args) {
  const executable = process.platform === 'win32' ? 'npm.cmd' : 'npm';
  execFileSync(executable, args, {
    cwd: appDir,
    stdio: 'inherit',
    // npm.cmd is a Windows command script and must be launched through a shell.
    shell: process.platform === 'win32',
  });
}

if (process.env.WSM_FRONTEND_PREBUILT === '1') {
  ensureDesktopOutput();
  verifyDist();
  console.log('[frontend] reusing the already-built and verified wsm-app/dist');
} else {
  if (!existsSync(lockPath)) throw new Error(`Frontend lockfile not found: ${lockPath}`);
  const lockHash = createHash('sha256').update(readFileSync(lockPath)).digest('hex');
  const vitePackage = join(nodeModulesDir, 'vite', 'package.json');
  const installedHash = existsSync(dependencyStampPath)
    ? readFileSync(dependencyStampPath, 'utf8').trim()
    : '';

  if (!existsSync(vitePackage) || installedHash !== lockHash) {
    console.log('[frontend] installing the locked wsm-app dependencies with npm ci...');
    runNpm(['ci', '--no-audit', '--no-fund']);
    if (!existsSync(vitePackage)) throw new Error('npm ci completed but Vite is still missing.');
    mkdirSync(nodeModulesDir, { recursive: true });
    writeFileSync(dependencyStampPath, `${lockHash}\n`, 'utf8');
  }

  console.log('[frontend] building wsm-app/dist...');
  runNpm(['run', 'build']);
  ensureDesktopOutput();
  verifyDist();
  console.log('[frontend] wsm-app/dist is ready');
}
