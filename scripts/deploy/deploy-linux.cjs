/**
 * Webhook Relay - Release-Based Deployment Script (Linux/WSL)
 *
 * Linux/WSL version of the deployment script. Handles WSL-specific issues:
 * - Resolves Windows paths (C:/Users/...) to WSL paths (/mnt/c/Users/...)
 * - Copies SSH keys from /mnt/c/ to temp with proper 0600 permissions
 * - Supports both SERVER_BASE_PATH and SERVER_DEST_PATH env vars
 *
 * Features:
 * - Atomic deployments via symlink switching
 * - Instant rollback to previous releases
 * - Shared storage/config persists across releases
 * - Automatic cleanup of old releases
 * - Zero-downtime deployments
 *
 * Usage: node scripts/deploy/deploy-linux.cjs [options]
 *   --skip-build    Skip npm build step
 *   --skip-composer Skip composer install step
 *   --fresh         Clear staging directory and rebuild from scratch
 *   --migrate       Run database migrations on server
 *
 * Server Structure:
 *   /var/www/webhook-relay/
 *   ├── releases/
 *   │   ├── 20260115120000/
 *   │   └── ...
 *   ├── current -> releases/{latest}/
 *   └── shared/
 *       ├── .env
 *       └── storage/
 */

const fs = require('fs').promises;
const fsSync = require('fs');
const path = require('path');
const os = require('os');

// Load environment: prefer .env.deployment, fallback to .env
const PROJECT_ROOT_FOR_ENV = path.join(__dirname, '..', '..');
const envDeploymentPath = path.join(PROJECT_ROOT_FOR_ENV, '.env.deployment');
const envPath = path.join(PROJECT_ROOT_FOR_ENV, '.env');
const envFileUsed = fsSync.existsSync(envDeploymentPath) ? envDeploymentPath : envPath;
require('dotenv').config({ path: envFileUsed });
console.log(`[ENV] Loaded: ${path.basename(envFileUsed)}`);
const archiver = require('archiver');
const { NodeSSH } = require('node-ssh');
const { execSync } = require('child_process');

// Project root is two levels up from scripts/deploy/
const PROJECT_ROOT = path.join(__dirname, '..', '..');
const STAGING_DIR = path.join(PROJECT_ROOT, 'deploy-staging');

// ==== WSL/LINUX PATH HELPERS ====

/**
 * Convert a Windows path to a WSL-accessible path.
 * e.g. "C:/Users/me/.ssh/key" => "/mnt/c/Users/me/.ssh/key"
 *      "C:\\Users\\me\\.ssh\\key" => "/mnt/c/Users/me/.ssh/key"
 * If already a Unix path, returns as-is.
 */
function resolveKeyPath(keyPath) {
    if (!keyPath) return keyPath;

    // Normalize backslashes to forward slashes
    let normalized = keyPath.replace(/\\/g, '/');

    // Convert Windows drive letter paths: C:/... => /mnt/c/...
    const winDriveMatch = normalized.match(/^([A-Za-z]):\/(.*)/);
    if (winDriveMatch) {
        normalized = `/mnt/${winDriveMatch[1].toLowerCase()}/${winDriveMatch[2]}`;
    }

    return normalized;
}

/**
 * Prepare SSH key for use on Linux/WSL.
 * Files on /mnt/c/ have 777 permissions which SSH rejects.
 * Copies to a temp file with 0600 permissions.
 */
function prepareSSHKey(keyPath) {
    const resolved = resolveKeyPath(keyPath);

    if (!fsSync.existsSync(resolved)) {
        throw new Error(`Private key not found: ${resolved} (original: ${keyPath})`);
    }

    // Check if key is on a Windows mount (permissions can't be changed)
    if (resolved.startsWith('/mnt/')) {
        const tmpKey = path.join(os.tmpdir(), `.deploy-key-${process.pid}`);
        fsSync.copyFileSync(resolved, tmpKey);
        fsSync.chmodSync(tmpKey, 0o600);
        return { path: tmpKey, isTemp: true };
    }

    // Native Linux path — ensure proper permissions
    try {
        fsSync.chmodSync(resolved, 0o600);
    } catch {
        // May not own the file, that's ok if permissions are already correct
    }
    return { path: resolved, isTemp: false };
}

// ==== CONFIGURATION ====
const CONFIG = {
    server: {
        host: (process.env.SERVER_HOST || '').trim(),
        username: (process.env.SERVER_USERNAME || '').trim(),
        privateKey: (process.env.SERVER_PRIVATE_KEY || '').trim(),
    },
    paths: {
        // Support both SERVER_BASE_PATH and SERVER_DEST_PATH (.env.deployment uses DEST_PATH)
        basePath: (process.env.SERVER_BASE_PATH || process.env.SERVER_DEST_PATH || '/var/www/webhook-relay').trim(),
        releasesDir: 'releases',
        sharedDir: 'shared',
        currentLink: 'current',
    },
    releasesToKeep: parseInt(process.env.RELEASES_TO_KEEP || '5', 10),
};

// Files and folders to sync to staging (source files, excludes vendor which is built there)
const FILES_TO_STAGE = [
    'app',
    'bootstrap',
    'config',
    'database',
    'docs',
    'public',
    'resources',
    'routes',
    'scripts',
    'storage/app',
    'storage/framework',
    'artisan',
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
    'vite.config.js',
    'tailwind.config.js',
    'postcss.config.js',
];

// Files and folders to include in deployment package (from staging)
const FILES_TO_TRANSFER = [
    'app',
    'bootstrap',
    'config',
    'database',
    'docs',
    'public',
    'resources',
    'routes',
    'scripts',
    'storage/app',
    'storage/framework',
    'vendor',
    'artisan',
    'composer.json',
    'composer.lock',
];

// ==== UTILITIES ====

/**
 * Generate release name in YYYYMMDDHHMMSS format
 */
function generateReleaseName() {
    const now = new Date();
    const pad = (n) => n.toString().padStart(2, '0');
    return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
}

/**
 * Log with timestamp and emoji
 */
function log(emoji, message) {
    const timestamp = new Date().toISOString().substring(11, 19);
    // Extra space after emoji to handle variable-width emoji rendering in terminals
    console.log(`[${timestamp}] ${emoji}  ${message}`);
}

/**
 * Format elapsed time in human-readable format
 */
function formatElapsedTime(ms) {
    const seconds = Math.floor(ms / 1000);
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    if (minutes > 0) {
        return `${minutes}m ${remainingSeconds}s`;
    }
    return `${seconds}s`;
}

/**
 * Execute SSH command and handle errors
 */
async function execSSH(ssh, command, options = {}) {
    const result = await ssh.execCommand(command, options);
    if (result.code !== 0 && !options.ignoreError) {
        throw new Error(`Command failed: ${command}\nStderr: ${result.stderr}\nStdout: ${result.stdout}`);
    }
    return result;
}

/**
 * Recursively copy a directory
 */
function copyDirSync(src, dest) {
    fsSync.mkdirSync(dest, { recursive: true });
    const entries = fsSync.readdirSync(src, { withFileTypes: true });

    for (const entry of entries) {
        const srcPath = path.join(src, entry.name);
        const destPath = path.join(dest, entry.name);

        // Skip symlinks (e.g. public/storage -> storage/app/public)
        if (entry.isSymbolicLink()) {
            continue;
        }

        if (entry.isDirectory()) {
            copyDirSync(srcPath, destPath);
        } else {
            fsSync.copyFileSync(srcPath, destPath);
        }
    }
}

/**
 * Sync files from project root to staging directory
 */
function syncToStaging() {
    log('📂', 'Syncing files to staging directory...');

    if (!fsSync.existsSync(STAGING_DIR)) {
        fsSync.mkdirSync(STAGING_DIR, { recursive: true });
    }

    for (const item of FILES_TO_STAGE) {
        const srcPath = path.join(PROJECT_ROOT, item);
        const destPath = path.join(STAGING_DIR, item);

        if (!fsSync.existsSync(srcPath)) {
            console.warn(`Warning: ${item} not found, skipping`);
            continue;
        }

        const lstat = fsSync.lstatSync(srcPath);

        // Skip symlinks at the top level (e.g. public/storage)
        if (lstat.isSymbolicLink()) {
            continue;
        }

        if (lstat.isDirectory()) {
            if (fsSync.existsSync(destPath)) {
                fsSync.rmSync(destPath, { recursive: true, force: true });
            }
            copyDirSync(srcPath, destPath);
        } else {
            const parentDir = path.dirname(destPath);
            if (!fsSync.existsSync(parentDir)) {
                fsSync.mkdirSync(parentDir, { recursive: true });
            }
            fsSync.copyFileSync(srcPath, destPath);
        }
    }

    // Clear bootstrap/cache
    const cacheDir = path.join(STAGING_DIR, 'bootstrap', 'cache');
    if (fsSync.existsSync(cacheDir)) {
        const cacheFiles = fsSync.readdirSync(cacheDir);
        for (const file of cacheFiles) {
            if (file.endsWith('.php')) {
                fsSync.unlinkSync(path.join(cacheDir, file));
            }
        }
        log('🗑️', ' Cleared bootstrap cache files');
    }

    // Remove auto-generated config files (should use dynamic generation)
    const autoGeneratedConfigs = [
        'api-endpoints.php',
    ];
    for (const configFile of autoGeneratedConfigs) {
        const configPath = path.join(STAGING_DIR, 'config', configFile);
        if (fsSync.existsSync(configPath)) {
            fsSync.unlinkSync(configPath);
            log('🗑️', ` Removed auto-generated config: ${configFile}`);
        }
    }

    log('✅', 'Files synced to staging');
}

// ==== BUILD PHASE ====

async function runComposerInstall() {
    log('📦', 'Installing production dependencies (composer) in staging...');
    try {
        execSync(
            'composer install --no-dev --optimize-autoloader --no-interaction --no-scripts',
            {
                stdio: 'inherit',
                cwd: STAGING_DIR
            }
        );
        log('✅', 'Composer install completed');
    } catch (error) {
        throw new Error(`Composer install failed: ${error.message}`);
    }
}

async function runNpmBuild() {
    log('🔨', 'Building frontend assets (Vite)...');
    try {
        execSync('npm run build', {
            stdio: 'inherit',
            cwd: PROJECT_ROOT
        });
        log('✅', 'Frontend build completed');

        // Copy built assets to staging
        const srcBuild = path.join(PROJECT_ROOT, 'public', 'build');
        const destBuild = path.join(STAGING_DIR, 'public', 'build');

        if (fsSync.existsSync(srcBuild)) {
            if (fsSync.existsSync(destBuild)) {
                fsSync.rmSync(destBuild, { recursive: true, force: true });
            }
            copyDirSync(srcBuild, destBuild);
            log('✅', 'Built assets copied to staging');
        }

        // Remove hot file from staging
        const hotFile = path.join(STAGING_DIR, 'public', 'hot');
        if (fsSync.existsSync(hotFile)) {
            fsSync.unlinkSync(hotFile);
            log('🗑️', ' Removed Vite hot file');
        }
    } catch (error) {
        throw new Error(`npm build failed: ${error.message}`);
    }
}

// ==== PACKAGE PHASE ====

async function createDeploymentPackage(releaseName) {
    const zipPath = path.join(PROJECT_ROOT, `release-${releaseName}.zip`);

    log('📦', 'Creating deployment package from staging...');

    return new Promise((resolve, reject) => {
        const archive = archiver('zip', { zlib: { level: 6 } });
        const stream = fsSync.createWriteStream(zipPath);

        stream.on('close', () => {
            const sizeMB = (archive.pointer() / 1024 / 1024).toFixed(2);
            log('✅', `Package created: ${sizeMB} MB`);
            resolve(zipPath);
        });

        archive.on('error', (err) => reject(err));
        archive.on('warning', (err) => {
            if (err.code !== 'ENOENT') {
                console.warn('Archive warning:', err);
            }
        });

        archive.pipe(stream);

        for (const item of FILES_TO_TRANSFER) {
            const itemPath = path.join(STAGING_DIR, item);
            if (fsSync.existsSync(itemPath)) {
                const stat = fsSync.statSync(itemPath);
                if (stat.isDirectory()) {
                    archive.directory(itemPath, item);
                } else {
                    archive.file(itemPath, { name: item });
                }
            } else {
                console.warn(`Warning: ${item} not found in staging, skipping`);
            }
        }

        archive.finalize();
    });
}

// ==== SERVER SETUP ====

/**
 * Setup shared directory structure (idempotent)
 */
async function setupSharedDirectory(ssh) {
    const sharedPath = `${CONFIG.paths.basePath}/${CONFIG.paths.sharedDir}`;

    log('📁', 'Setting up shared directory structure...');

    await execSSH(ssh, `mkdir -p ${sharedPath}/storage/app/public`);
    await execSSH(ssh, `mkdir -p ${sharedPath}/storage/framework/cache/data`);
    await execSSH(ssh, `mkdir -p ${sharedPath}/storage/framework/sessions`);
    await execSSH(ssh, `mkdir -p ${sharedPath}/storage/framework/views`);
    await execSSH(ssh, `mkdir -p ${sharedPath}/storage/logs`);

    // Set permissions on shared storage
    await execSSH(ssh, `sudo chown -R ubuntu:www-data ${sharedPath}`);
    await execSSH(ssh, `sudo chmod -R 775 ${sharedPath}/storage`);

    log('✅', 'Shared directory ready');
}

/**
 * Link shared resources to release
 */
async function linkSharedResources(ssh, releasePath) {
    const sharedPath = `${CONFIG.paths.basePath}/${CONFIG.paths.sharedDir}`;

    log('🔗', 'Linking shared resources...');

    // Remove storage directory from release (will be symlinked)
    await execSSH(ssh, `rm -rf ${releasePath}/storage`);

    // Create symlinks
    await execSSH(ssh, `ln -sf ${sharedPath}/.env ${releasePath}/.env`);
    await execSSH(ssh, `ln -sf ${sharedPath}/storage ${releasePath}/storage`);

    // Create public/storage symlink (Laravel's storage:link equivalent)
    // This allows serving uploaded files from storage/app/public via /storage URL
    await execSSH(ssh, `ln -sf ${sharedPath}/storage/app/public ${releasePath}/public/storage`);

    log('✅', 'Shared resources linked');
}

/**
 * Switch current symlink to new release (atomic)
 */
async function switchToRelease(ssh, releasePath) {
    const currentPath = `${CONFIG.paths.basePath}/${CONFIG.paths.currentLink}`;

    log('🔗', 'Switching to new release...');

    // ln -sfn is atomic - creates new symlink and replaces old one
    await execSSH(ssh, `ln -sfn ${releasePath} ${currentPath}`);

    log('✅', 'Symlink updated');
}

/**
 * Verify release is functional
 */
async function verifyRelease(ssh, releasePath) {
    log('🔍', 'Verifying release...');

    // Check artisan works
    const artisanCheck = await execSSH(ssh, `cd ${releasePath} && php artisan --version`, { ignoreError: true });
    if (artisanCheck.code !== 0) {
        throw new Error('Artisan command failed - release may be broken');
    }

    // Check .env symlink exists
    const envCheck = await execSSH(ssh, `test -L ${releasePath}/.env && echo "ok"`, { ignoreError: true });
    if (envCheck.stdout.trim() !== 'ok') {
        throw new Error('.env symlink not found');
    }

    // Check storage symlink exists
    const storageCheck = await execSSH(ssh, `test -L ${releasePath}/storage && echo "ok"`, { ignoreError: true });
    if (storageCheck.stdout.trim() !== 'ok') {
        throw new Error('storage symlink not found');
    }

    log('✅', 'Release verified');
}

/**
 * Cleanup old releases, keeping only the most recent N
 */
async function cleanupOldReleases(ssh) {
    const releasesPath = `${CONFIG.paths.basePath}/${CONFIG.paths.releasesDir}`;

    log('🧹', `Cleaning up old releases (keeping ${CONFIG.releasesToKeep})...`);

    try {
        const result = await execSSH(ssh, `ls -1 ${releasesPath} | sort`);
        const releases = result.stdout.trim().split('\n').filter(r => r && /^\d{14}$/.test(r));

        if (releases.length <= CONFIG.releasesToKeep) {
            log('✅', `No cleanup needed (${releases.length} releases)`);
            return;
        }

        const toDelete = releases.slice(0, releases.length - CONFIG.releasesToKeep);
        for (const release of toDelete) {
            log('🗑️', ` Deleting old release: ${release}`);
            await execSSH(ssh, `rm -rf ${releasesPath}/${release}`, { ignoreError: true });
        }

        log('✅', `Cleaned up ${toDelete.length} old release(s)`);
    } catch (error) {
        log('⚠️', `Cleanup warning: ${error.message}`);
    }
}

// ==== OPTIONAL TOOLING CHECK ====

/**
 * Verify the OpenAPI Generator CLI is available on the server.
 *
 * Non-blocking: prints a warning and returns. The admin SDK generator
 * (app/Console/Commands/GenerateApiSdk.php) shells out to `npx
 * @openapitools/openapi-generator-cli`, so this check uses the same `npx
 * --no-install` path that the runtime command will take. If the binary
 * exists in a prefix that's not on the Apache user's PATH, the runtime
 * call will still fail — flagging that distinction matters.
 *
 * To install: run `sudo bash scripts/setup/install-openapi-generator.sh`
 * on the server.
 */
async function checkOpenApiGeneratorCli(ssh) {
    log('🔧', 'Checking optional tooling: OpenAPI Generator CLI...');

    const result = await execSSH(
        ssh,
        'npx --no-install @openapitools/openapi-generator-cli version 2>/dev/null',
        { ignoreError: true }
    );

    if (result.code === 0 && result.stdout.trim()) {
        log('✅', `OpenAPI Generator CLI available: ${result.stdout.trim().split('\n')[0]}`);
        return;
    }

    log('⚠️', 'OpenAPI Generator CLI not available on server (admin SDK generation will fail for languages other than --spec-only).');
    log('⚠️', '   Fix: sudo bash scripts/setup/install-openapi-generator.sh');
}

// ==== SENTRY RELEASE TRACKING ====

/**
 * Update SENTRY_RELEASE in the shared .env on the server.
 * This ensures config('sentry.release') returns the active release name.
 */
async function updateSentryRelease(ssh, releaseName) {
    if (!/^\d{14}$/.test(releaseName)) {
        log('⚠️', `Skipping Sentry release update: invalid release name "${releaseName}"`);
        return;
    }

    const envFile = `${CONFIG.paths.basePath}/${CONFIG.paths.sharedDir}/.env`;

    log('📝', `Setting SENTRY_RELEASE=${releaseName} in shared .env...`);

    // Update existing SENTRY_RELEASE or append it
    const result = await execSSH(ssh, `grep -q '^SENTRY_RELEASE=' ${envFile} && echo "exists" || echo "missing"`, { ignoreError: true });

    if (result.stdout.trim() === 'exists') {
        await execSSH(ssh, `sed -i 's/^SENTRY_RELEASE=.*/SENTRY_RELEASE=${releaseName}/' ${envFile}`);
    } else {
        await execSSH(ssh, `echo 'SENTRY_RELEASE=${releaseName}' >> ${envFile}`);
    }

    log('✅', 'SENTRY_RELEASE updated in shared .env');
}

/**
 * Create a Sentry release and register a deploy via sentry-cli.
 * Gated behind SENTRY_AUTH_TOKEN — skips gracefully if not configured.
 */
function createSentryRelease(releaseName) {
    if (!/^\d{14}$/.test(releaseName)) {
        log('⚠️', `Skipping Sentry release creation: invalid release name "${releaseName}"`);
        return;
    }

    if (!process.env.SENTRY_AUTH_TOKEN) {
        log('⚠️', 'Skipping Sentry release creation: SENTRY_AUTH_TOKEN not set');
        return;
    }

    const org = process.env.SENTRY_ORG;
    const project = process.env.SENTRY_PROJECT;

    if (!org || !project) {
        log('⚠️', 'Skipping Sentry release creation: SENTRY_ORG or SENTRY_PROJECT not set');
        return;
    }

    log('📡', `Creating Sentry release: ${releaseName}...`);

    const sentryCliPath = path.join(PROJECT_ROOT, 'node_modules', '.bin', 'sentry-cli');
    const env = {
        ...process.env,
        SENTRY_AUTH_TOKEN: process.env.SENTRY_AUTH_TOKEN,
        SENTRY_ORG: org,
        SENTRY_PROJECT: project,
    };
    const execOpts = { stdio: 'pipe', env };

    try {
        // Create the release
        execSync(`"${sentryCliPath}" releases new ${releaseName}`, execOpts);

        // Set commits (auto-detect from git)
        execSync(`"${sentryCliPath}" releases set-commits ${releaseName} --auto`, execOpts);

        // Finalize the release
        execSync(`"${sentryCliPath}" releases finalize ${releaseName}`, execOpts);

        // Register the deploy
        execSync(`"${sentryCliPath}" deploys new -r ${releaseName} -e production`, execOpts);

        log('✅', 'Sentry release created and deploy registered');
    } catch (error) {
        const stderr = error.stderr ? error.stderr.toString().trim() : error.message;
        log('⚠️', `Sentry release creation failed (non-fatal): ${stderr}`);
    }
}

// ==== DEPLOYMENT PHASE ====

async function deploy(options = {}) {
    const ssh = new NodeSSH();
    const releaseName = generateReleaseName();
    const releasePath = `${CONFIG.paths.basePath}/${CONFIG.paths.releasesDir}/${releaseName}`;
    const releasesPath = `${CONFIG.paths.basePath}/${CONFIG.paths.releasesDir}`;
    let releaseCreated = false;
    let zipPath = null;
    let sshKey = null;
    const startTime = Date.now();

    log('🚀', `Starting deployment: ${releaseName}`);
    log('📍', `Target: ${CONFIG.paths.basePath}`);

    try {
        // ==== PREPARE SSH KEY ====
        sshKey = prepareSSHKey(CONFIG.server.privateKey);
        log('🔑', `SSH key ready: ${sshKey.path}${sshKey.isTemp ? ' (temp copy for correct permissions)' : ''}`);

        // ==== LOCAL BUILD ====
        syncToStaging();

        if (!options.skipBuild) {
            await runNpmBuild();
        } else {
            log('⏭️', 'Skipping npm build (--skip-build flag)');
            // Copy existing build assets to staging if skipping build
            const srcBuild = path.join(PROJECT_ROOT, 'public', 'build');
            const destBuild = path.join(STAGING_DIR, 'public', 'build');
            if (fsSync.existsSync(srcBuild)) {
                if (fsSync.existsSync(destBuild)) {
                    fsSync.rmSync(destBuild, { recursive: true, force: true });
                }
                copyDirSync(srcBuild, destBuild);
            }
        }

        // Always remove Vite hot file from staging (prevents dev server usage in production)
        const hotFile = path.join(STAGING_DIR, 'public', 'hot');
        if (fsSync.existsSync(hotFile)) {
            fsSync.unlinkSync(hotFile);
            log('🗑️', ' Removed Vite hot file from staging');
        }

        if (!options.skipComposer) {
            await runComposerInstall();
        } else {
            log('⏭️', 'Skipping composer install (--skip-composer flag)');
        }

        // ==== CREATE PACKAGE ====
        zipPath = await createDeploymentPackage(releaseName);

        // ==== UPLOAD PACKAGE ====
        const remoteTmpZip = `/tmp/release-${releaseName}.zip`;
        log('📤', 'Uploading package to server...');

        const scpCommand = `scp -i "${sshKey.path}" -o StrictHostKeyChecking=no "${zipPath}" ${CONFIG.server.username}@${CONFIG.server.host}:${remoteTmpZip}`;

        try {
            execSync(scpCommand, { stdio: 'inherit' });
        } catch (scpError) {
            throw new Error(`SCP upload failed: ${scpError.message}`);
        }
        log('✅', 'Package uploaded');

        // ==== CONNECT TO SERVER ====
        log('🔌', 'Connecting to server...');
        const privateKeyContent = fsSync.readFileSync(sshKey.path, 'utf8');
        await ssh.connect({
            host: CONFIG.server.host,
            username: CONFIG.server.username,
            privateKey: privateKeyContent,
        });
        log('✅', 'Connected to server');

        // Delete local zip after upload
        await fs.unlink(zipPath);
        zipPath = null;
        log('🗑️', ' Local package cleaned up');

        // ==== SETUP DIRECTORIES ====
        await execSSH(ssh, `mkdir -p ${releasesPath}`);
        await setupSharedDirectory(ssh);

        // ==== CREATE RELEASE DIRECTORY ====
        log('📁', `Creating release directory: ${releaseName}`);
        await execSSH(ssh, `mkdir -p ${releasePath}`);
        releaseCreated = true;

        // ==== EXTRACT PACKAGE ====
        log('📦', 'Extracting package on server...');
        await execSSH(ssh, `unzip -q -o ${remoteTmpZip} -d ${releasePath}`);
        await execSSH(ssh, `rm -f ${remoteTmpZip}`);
        log('✅', 'Package extracted');

        // ==== LINK SHARED RESOURCES ====
        await linkSharedResources(ssh, releasePath);

        // ==== UPDATE SENTRY RELEASE IN .ENV ====
        await updateSentryRelease(ssh, releaseName);

        // ==== SET PERMISSIONS ====
        log('🔧', 'Setting permissions...');
        await execSSH(ssh, `sudo chown -R ubuntu:www-data ${releasePath}`);
        await execSSH(ssh, `sudo chmod -R 755 ${releasePath}`);
        await execSSH(ssh, `sudo chmod -R 775 ${releasePath}/bootstrap/cache`);
        log('✅', 'Permissions set');

        // ==== RUN ARTISAN COMMANDS ====
        log('⚙️', ' Running artisan commands...');

        // Clear bootstrap cache files
        await execSSH(ssh, `rm -f ${releasePath}/bootstrap/cache/*.php`, { ignoreError: true });

        // Remove auto-generated config files that should use dynamic generation
        log('🗑️', ' Removing auto-generated config files...');
        await execSSH(ssh, `rm -f ${releasePath}/config/api-endpoints.php`, { ignoreError: true });

        // Clear all caches first (important: view cache is in shared storage!)
        await execSSH(ssh, `cd ${releasePath} && php artisan config:clear`);
        await execSSH(ssh, `cd ${releasePath} && php artisan route:clear`);
        await execSSH(ssh, `cd ${releasePath} && php artisan view:clear`);
        await execSSH(ssh, `cd ${releasePath} && php artisan cache:clear`);

        // Rebuild caches with new release code
        await execSSH(ssh, `cd ${releasePath} && php artisan config:cache`);
        await execSSH(ssh, `cd ${releasePath} && php artisan route:cache`);
        await execSSH(ssh, `cd ${releasePath} && php artisan view:cache`);
        log('✅', 'Artisan optimization completed');

        // ==== RUN MIGRATIONS ====
        if (options.migrate) {
            log('🗃️', 'Running database migrations...');
            await execSSH(ssh, `cd ${releasePath} && php artisan migrate --force`);
            log('✅', 'Migrations completed');
        }

        // ==== VERIFY RELEASE ====
        await verifyRelease(ssh, releasePath);

        // ==== SWITCH SYMLINK (ATOMIC!) ====
        await switchToRelease(ssh, releasePath);

        // ==== RELOAD SERVICES ====
        log('♻️', ' Reloading Apache...');
        await execSSH(ssh, 'sudo systemctl reload apache2');
        log('✅', 'Apache reloaded');

        log('♻️', ' Restarting queue workers...');
        await execSSH(ssh, 'sudo supervisorctl restart webhook-relay-horizon:*', { ignoreError: true });
        log('✅', 'Queue workers restarted');

        log('♻️', ' Restarting Reverb WebSocket server...');
        await execSSH(ssh, 'sudo supervisorctl restart webhook-relay-reverb', { ignoreError: true });
        log('✅', 'Reverb restarted');

        // ==== CREATE SENTRY RELEASE ====
        createSentryRelease(releaseName);

        // ==== POST-DEPLOY HEALTH CHECK ====
        if (!options.skipVerify) {
            log('🏥', 'Running post-deploy health check...');
            let healthOk = false;
            const healthUrl = `https://${process.env.MARKETING_DOMAIN || 'api.webhook-relay.dcsuniverse.com'}/health`;
            for (let attempt = 1; attempt <= 3; attempt++) {
                try {
                    const result = await execSSH(ssh, `curl -sf --max-time 30 ${healthUrl}`, { ignoreError: true });
                    if (result && result.stdout && result.stdout.includes('"healthy"')) {
                        healthOk = true;
                        break;
                    }
                } catch {
                    // Retry
                }
                if (attempt < 3) {
                    log('⏳', `Health check attempt ${attempt}/3 failed, retrying in 5s...`);
                    await new Promise(resolve => setTimeout(resolve, 5000));
                }
            }
            if (healthOk) {
                log('✅', 'Application healthy');
            } else {
                log('⚠️', `Health check failed. Verify manually: ${healthUrl}`);
                log('⚠️', 'To rollback, re-deploy the previous release.');
            }
        } else {
            log('⏭️', ' Skipping health check (--skip-verify)');
        }

        // ==== CLEANUP OLD RELEASES ====
        await cleanupOldReleases(ssh);

        // ==== OPTIONAL TOOLING CHECK ====
        await checkOpenApiGeneratorCli(ssh);

        // ==== NOTIFY ADMINS ====
        log('📢', 'Sending deployment notification to admins...');
        await execSSH(ssh, `cd ${CONFIG.paths.basePath}/${CONFIG.paths.currentLink} && php artisan notify:deployment ${releaseName}`, { ignoreError: true });
        log('✅', 'Deployment notification sent');

        // ==== DONE ====
        const elapsed = formatElapsedTime(Date.now() - startTime);
        log('🎉', `Deployment successful! Release: ${releaseName}`);
        log('⏱️', ` Total time: ${elapsed}`);
        log('🌐', 'Site: https://api.webhook-relay.dcsuniverse.com');

    } catch (error) {
        const elapsed = formatElapsedTime(Date.now() - startTime);
        log('❌', `Deployment failed: ${error.message}`);
        log('⏱️', `Failed after: ${elapsed}`);

        // Cleanup failed release if it was created
        if (releaseCreated) {
            log('🧹', 'Cleaning up failed release...');
            try {
                await execSSH(ssh, `rm -rf ${releasePath}`, { ignoreError: true });
                log('✅', 'Failed release cleaned up');
            } catch (cleanupError) {
                log('⚠️', `Failed to cleanup release: ${cleanupError.message}`);
            }
        }

        // Cleanup local zip if it exists
        if (zipPath && fsSync.existsSync(zipPath)) {
            try {
                await fs.unlink(zipPath);
                log('🗑️', ' Local package cleaned up');
            } catch {
                // Ignore cleanup errors
            }
        }

        process.exit(1);
    } finally {
        ssh.dispose();
        // Cleanup temp SSH key
        if (sshKey && sshKey.isTemp) {
            try {
                fsSync.unlinkSync(sshKey.path);
            } catch {
                // Ignore cleanup errors
            }
        }
    }
}

// ==== MAIN ====

const args = process.argv.slice(2);
const options = {
    skipBuild: args.includes('--skip-build'),
    skipComposer: args.includes('--skip-composer'),
    fresh: args.includes('--fresh'),
    migrate: args.includes('--migrate'),
    clearCached: args.includes('--clear-cached'),
    skipVerify: args.includes('--skip-verify'),
};

// Show help
if (args.includes('--help') || args.includes('-h')) {
    console.log(`
Webhook Relay - Release-Based Deployment (Linux/WSL)

Usage: node scripts/deploy/deploy-linux.cjs [options]

Options:
  --skip-build     Skip npm build step
  --skip-composer  Skip composer install step
  --fresh          Clear staging directory and rebuild from scratch
  --migrate        Run database migrations on server
  --clear-cached   Clear all auto-generated config files on server (current release)
  --skip-verify    Skip post-deploy health check
  --help, -h       Show this help message

Server Structure:
  ${CONFIG.paths.basePath}/
  ├── releases/           Release directories
  ├── current -> releases/{latest}
  └── shared/             Shared .env and storage
`);
    process.exit(0);
}

// Clear staging if --fresh flag
if (options.fresh && fsSync.existsSync(STAGING_DIR)) {
    log('🗑️', ' Clearing staging directory (--fresh flag)...');
    fsSync.rmSync(STAGING_DIR, { recursive: true, force: true });
}

// Validate configuration
if (!CONFIG.server.host || !CONFIG.server.username || !CONFIG.server.privateKey) {
    console.error('❌ Missing required environment variables:');
    console.error('   SERVER_HOST, SERVER_USERNAME, SERVER_PRIVATE_KEY');
    process.exit(1);
}

// Resolve the key path for Linux/WSL and validate
const resolvedKeyPath = resolveKeyPath(CONFIG.server.privateKey);
if (!fsSync.existsSync(resolvedKeyPath)) {
    console.error(`❌ Private key file not found: ${resolvedKeyPath}`);
    if (resolvedKeyPath !== CONFIG.server.privateKey) {
        console.error(`   (resolved from: ${CONFIG.server.privateKey})`);
    }
    process.exit(1);
}

// Handle --clear-cached as standalone operation (no deployment)
if (options.clearCached) {
    clearCachedConfigs().then(() => process.exit(0)).catch(() => process.exit(1));
} else {
    // Normal deployment
    deploy(options);
}

/**
 * Clear all auto-generated config files on the server (standalone operation)
 */
async function clearCachedConfigs() {
    const ssh = new NodeSSH();
    const currentPath = `${CONFIG.paths.basePath}/${CONFIG.paths.currentLink}`;
    const startTime = Date.now();
    let sshKey = null;

    log('🧹', 'Clearing cached configs on server...');

    try {
        // Prepare SSH key
        sshKey = prepareSSHKey(CONFIG.server.privateKey);

        // Connect to server
        log('🔌', 'Connecting to server...');
        const privateKeyContent = fsSync.readFileSync(sshKey.path, 'utf8');
        await ssh.connect({
            host: CONFIG.server.host,
            username: CONFIG.server.username,
            privateKey: privateKeyContent,
        });
        log('✅', 'Connected to server');

        // Auto-generated config files to remove
        const cachedConfigs = [
            'config/api-endpoints.php',
        ];

        for (const configFile of cachedConfigs) {
            const filePath = `${currentPath}/${configFile}`;
            await execSSH(ssh, `rm -f ${filePath}`, { ignoreError: true });
            log('🗑️', ` Removed: ${configFile}`);
        }

        // Clear Laravel caches
        log('⚙️', ' Clearing Laravel caches...');
        await execSSH(ssh, `cd ${currentPath} && php artisan config:clear`);
        await execSSH(ssh, `cd ${currentPath} && php artisan route:clear`);
        await execSSH(ssh, `cd ${currentPath} && php artisan view:clear`);
        await execSSH(ssh, `cd ${currentPath} && php artisan cache:clear`);

        // Rebuild caches
        log('⚙️', ' Rebuilding Laravel caches...');
        await execSSH(ssh, `cd ${currentPath} && php artisan config:cache`);
        await execSSH(ssh, `cd ${currentPath} && php artisan route:cache`);
        await execSSH(ssh, `cd ${currentPath} && php artisan view:cache`);

        // Reload Apache
        log('♻️', ' Reloading Apache...');
        await execSSH(ssh, 'sudo systemctl reload apache2');

        const elapsed = formatElapsedTime(Date.now() - startTime);
        log('🎉', 'Cached configs cleared successfully!');
        log('⏱️', `Total deployment time: ${elapsed}`);

    } catch (error) {
        const elapsed = formatElapsedTime(Date.now() - startTime);
        log('❌', `Failed to clear cached configs: ${error.message}`);
        log('⏱️', `Failed after: ${elapsed}`);
        throw error;
    } finally {
        ssh.dispose();
        if (sshKey && sshKey.isTemp) {
            try {
                fsSync.unlinkSync(sshKey.path);
            } catch {
                // Ignore cleanup errors
            }
        }
    }
}
