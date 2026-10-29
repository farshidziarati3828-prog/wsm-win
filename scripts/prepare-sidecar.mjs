#!/usr/bin/env node
/** Build the local Python bridge and place it at Tauri's target-triple sidecar path. */
import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import {
  chmodSync,
  existsSync,
  mkdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const bridgeDir = join(root, 'bridge');
const bridgeEntry = join(bridgeDir, 'wsm_local_bridge.py');
const requirementsPath = join(bridgeDir, 'requirements.txt');
const binariesDir = join(root, 'src-tauri', 'binaries');
const pyInstallerVersion = '6.22.3';
const forceBuild = process.argv.includes('--force');
const prebuiltByCi = process.env.WSM_SIDECAR_PREBUILT === '1';

function rustHostTriple() {
  try {
    const triple = execFileSync('rustc', ['--print', 'host-tuple'], {
      cwd: root,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
    if (triple) return triple;
  } catch {
    // Rust is required for a local Tauri build; report a direct setup error below.
  }
  throw new Error('rustc was not found. Install Rust stable before building the Tauri app.');
}

const targetTriple =
  process.env.TAURI_ENV_TARGET_TRIPLE || process.env.WSM_TARGET_TRIPLE || rustHostTriple();
if (!/^[A-Za-z0-9][A-Za-z0-9_.-]*$/.test(targetTriple) || targetTriple.includes('..')) {
  throw new Error(`Invalid Tauri target triple: ${targetTriple}`);
}
const isWindowsTarget = targetTriple.includes('windows');
const extension = isWindowsTarget ? '.exe' : '';
const binaryName = `wsm-local-bridge-${targetTriple}`;
const destination = join(binariesDir, `${binaryName}${extension}`);

function requireExistingSidecar() {
  if (!existsSync(destination) || !statSync(destination).isFile() || statSync(destination).size === 0) {
    throw new Error(
      `WSM_SIDECAR_PREBUILT=1, but the expected sidecar is missing: ${destination}`,
    );
  }
  console.log(`[sidecar] using the CI-built sidecar for ${targetTriple}`);
}

if (prebuiltByCi) {
  requireExistingSidecar();
} else {
  const hostTriple = rustHostTriple();
  if (targetTriple !== hostTriple) {
    throw new Error(
      `PyInstaller cannot cross-build the bridge (${hostTriple} host, ${targetTriple} target). ` +
        'Build on the target OS/architecture or provide its prebuilt sidecar.',
    );
  }
  if (!existsSync(bridgeEntry) || !existsSync(requirementsPath)) {
    throw new Error('The bridge source or bridge/requirements.txt is missing.');
  }

  const fingerprintHash = createHash('sha256')
    .update(`target=${targetTriple}\nPyInstaller=${pyInstallerVersion}\n`);
  for (const filePath of [bridgeEntry, requirementsPath, fileURLToPath(import.meta.url)]) {
    fingerprintHash.update('\0').update(filePath).update('\0').update(readFileSync(filePath));
  }
  const fingerprint = fingerprintHash.digest('hex');
  const stampPath = join(binariesDir, `.wsm-local-bridge-${targetTriple}.sha256`);
  const recordedFingerprint = existsSync(stampPath) ? readFileSync(stampPath, 'utf8').trim() : '';

  if (
    !forceBuild &&
    existsSync(destination) &&
    statSync(destination).isFile() &&
    statSync(destination).size > 0 &&
    recordedFingerprint === fingerprint
  ) {
    console.log(`[sidecar] reusing the up-to-date sidecar for ${targetTriple}`);
  } else {
    const virtualEnvDir = join(bridgeDir, '.venv-tauri');
    const virtualEnvPython = process.platform === 'win32'
      ? join(virtualEnvDir, 'Scripts', 'python.exe')
      : join(virtualEnvDir, 'bin', 'python');
    const dependencyStampPath = join(virtualEnvDir, '.wsm-dependencies.sha256');
    const dependencyHash = createHash('sha256')
      .update(readFileSync(requirementsPath))
      .update(`\nPyInstaller=${pyInstallerVersion}\n`)
      .digest('hex');

    function probe(command, args) {
      const result = spawnSync(command, args, { cwd: bridgeDir, stdio: 'ignore' });
      return !result.error && result.status === 0;
    }

    function findSystemPython() {
      const configured = process.env.WSM_SIDECAR_PYTHON || process.env.PYTHON;
      const candidates = configured
        ? [{ command: configured, prefix: [] }]
        : process.platform === 'win32'
          ? [
              { command: 'python', prefix: [] },
              { command: 'py', prefix: ['-3'] },
            ]
          : [
              { command: 'python3', prefix: [] },
              { command: 'python', prefix: [] },
            ];

      for (const candidate of candidates) {
        if (probe(candidate.command, [...candidate.prefix, '--version'])) return candidate;
      }
      throw new Error(
        'Python 3 is required to build the local bridge. Install Python and retry, ' +
          'or set WSM_SIDECAR_PYTHON to its executable.',
      );
    }

    if (!existsSync(virtualEnvPython)) {
      if (existsSync(virtualEnvDir)) rmSync(virtualEnvDir, { recursive: true, force: true });
      const systemPython = findSystemPython();
      console.log('[sidecar] creating the isolated bridge build environment...');
      execFileSync(
        systemPython.command,
        [...systemPython.prefix, '-m', 'venv', virtualEnvDir],
        { cwd: bridgeDir, stdio: 'inherit' },
      );
    }
    if (!existsSync(virtualEnvPython)) {
      throw new Error(`Could not create the bridge Python environment at ${virtualEnvDir}.`);
    }

    function dependenciesImportable() {
      return probe(virtualEnvPython, [
        '-c',
        'import PyInstaller, zk, pymodbus, serial, usb, tzdata',
      ]);
    }

    const installedDependencyHash = existsSync(dependencyStampPath)
      ? readFileSync(dependencyStampPath, 'utf8').trim()
      : '';
    if (
      installedDependencyHash !== dependencyHash ||
      !dependenciesImportable() ||
      process.argv.includes('--reinstall-deps')
    ) {
      console.log('[sidecar] installing bridge requirements and PyInstaller into its venv...');
      execFileSync(
        virtualEnvPython,
        [
          '-m', 'pip', 'install', '--disable-pip-version-check',
          '-r', requirementsPath,
          `pyinstaller==${pyInstallerVersion}`,
        ],
        { cwd: bridgeDir, stdio: 'inherit' },
      );
      if (!dependenciesImportable()) {
        throw new Error('Bridge dependencies were installed but could not be imported.');
      }
      writeFileSync(dependencyStampPath, `${dependencyHash}\n`, 'utf8');
    }

    const optionalLibusbPackage = probe(virtualEnvPython, [
      '-c',
      'import importlib.util,sys; sys.exit(0 if importlib.util.find_spec(sys.argv[1]) else 1)',
      'libusb_package',
    ]);
    const collectModules = ['zk', 'pymodbus', 'serial', 'usb', 'tzdata'];
    const metadataPackages = ['pyzk', 'pymodbus', 'pyserial', 'pyusb', 'tzdata'];
    if (optionalLibusbPackage) {
      collectModules.push('libusb_package');
      metadataPackages.push('libusb-package');
    }

    const workDir = join(bridgeDir, 'build', 'pyinstaller-work');
    const specDir = join(bridgeDir, 'build', 'pyinstaller-spec');
    mkdirSync(binariesDir, { recursive: true });
    mkdirSync(workDir, { recursive: true });
    mkdirSync(specDir, { recursive: true });

    const pyInstallerArgs = [
      '--onefile', '--noconfirm', '--clean', '--console',
      '--name', binaryName,
      '--distpath', binariesDir,
      '--workpath', workDir,
      '--specpath', specDir,
    ];
    for (const moduleName of collectModules) pyInstallerArgs.push('--collect-all', moduleName);
    for (const packageName of metadataPackages) pyInstallerArgs.push('--copy-metadata', packageName);
    pyInstallerArgs.push('wsm_local_bridge.py');

    console.log(`[sidecar] building ${binaryName}${extension} with PyInstaller...`);
    execFileSync(virtualEnvPython, ['-m', 'PyInstaller', ...pyInstallerArgs], {
      cwd: bridgeDir,
      stdio: 'inherit',
    });

    if (!existsSync(destination) || !statSync(destination).isFile() || statSync(destination).size === 0) {
      throw new Error(`PyInstaller did not create the expected Tauri sidecar: ${destination}`);
    }
    if (process.platform !== 'win32') chmodSync(destination, 0o755);
    writeFileSync(stampPath, `${fingerprint}\n`, 'utf8');
    console.log(`[sidecar] created ${destination}`);
  }
}
