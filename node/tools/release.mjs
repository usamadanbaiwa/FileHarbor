import { spawn } from 'node:child_process';
import { mkdir, readFile, readdir, lstat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const metadata = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'));
const version = process.argv[2] || metadata.version;

if (version !== metadata.version) {
    throw new Error('Set the package version before building the archive.');
}

if (!/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/.test(version)) {
    throw new Error('Use a release version such as 0.1.0.');
}

const entries = [
    'src',
    'public',
    'docs',
    'tests',
    'tools',
    'README.md',
    'CONTRIBUTING.md',
    'SECURITY.md',
    'package.json',
    'package-lock.json',
    '.env.example',
    '.prettierrc.json',
    'eslint.config.mjs',
    '.editorconfig'
];

async function checkEntry(relative) {
    if (relative === 'tests/.state') {
        return;
    }

    const filename = path.join(root, relative);
    const entry = await lstat(filename);

    if (entry.isSymbolicLink()) {
        throw new Error('Release sources must not contain symbolic links: ' + relative);
    }

    if (entry.isDirectory()) {
        for (const name of await readdir(filename)) {
            await checkEntry(relative + '/' + name);
        }
    }
}

for (const entry of entries) {
    await checkEntry(entry);
}

let licenseDirectory = root;

try {
    await lstat(path.join(licenseDirectory, 'LICENSE'));
} catch (error) {
    if (error.code !== 'ENOENT') {
        throw error;
    }

    licenseDirectory = path.dirname(root);
}

const license = await lstat(path.join(licenseDirectory, 'LICENSE'));

if (!license.isFile() || license.isSymbolicLink()) {
    throw new Error('The release requires a regular LICENSE file.');
}

const directory = path.join(root, 'dist');

await mkdir(directory, {
    recursive: true
});

const archive = path.join(directory, 'fileharbor-node-' + version + '.tar.gz');

const argumentsList = [
    '-czf',
    archive,
    '--exclude=tests/.state',
    '-C',
    root,
    ...entries,
    '-C',
    licenseDirectory,
    'LICENSE'
];

const child = spawn('tar', argumentsList, {
    stdio: 'inherit',
    windowsHide: true
});

child.once('error', function reportError(error) {
    console.error(error.message);
    process.exitCode = 1;
});

child.once('exit', function reportExit(code) {
    if (code !== 0) {
        process.exitCode = 1;
        return;
    }

    console.log('Created ' + archive);
});
