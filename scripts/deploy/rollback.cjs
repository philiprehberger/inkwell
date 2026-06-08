/**
 * Webhook Relay - Rollback Script
 *
 * Instantly rollback to a previous release by switching the symlink.
 *
 * Usage: node scripts/rollback.js [options]
 *   --releases=N    Number of releases to roll back (default: 1)
 *   --to=TIMESTAMP  Roll back to a specific release by name
 *   --dry-run       Show what would happen without making changes
 *
 * Examples:
 *   node scripts/rollback.js                 # Roll back 1 release
 *   node scripts/rollback.js --releases=2   # Roll back 2 releases
 *   node scripts/rollback.js --to=20260115120000  # Roll back to specific release
 */

const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');
const { NodeSSH } = require('node-ssh');

// Load environment: prefer .env.deployment, fallback to .env
const PROJECT_ROOT = path.join(__dirname, '..', '..');
const envDeploymentPath = path.join(PROJECT_ROOT, '.env.deployment');
const envPath = path.join(PROJECT_ROOT, '.env');
const envFileUsed = fs.existsSync(envDeploymentPath) ? envDeploymentPath : envPath;
require('dotenv').config({ path: envFileUsed });
console.log(`[ENV] Loaded: ${path.basename(envFileUsed)}`);

// ==== CONFIGURATION ====
const CONFIG = {
    server: {
        host: (process.env.SERVER_HOST || '').trim(),
        username: (process.env.SERVER_USERNAME || '').trim(),
        privateKey: (process.env.SERVER_PRIVATE_KEY || '').trim(),
    },
    paths: {
        basePath: process.env.SERVER_BASE_PATH || '/var/www/webhook-relay',
        releasesDir: 'releases',
        currentLink: 'current',
    },
};

// ==== UTILITIES ====

function log(emoji, message) {
    const timestamp = new Date().toISOString().substring(11, 19);
    console.log(`[${timestamp}] ${emoji} ${message}`);
}

async function execSSH(ssh, command, options = {}) {
    const result = await ssh.execCommand(command, options);
    if (result.code !== 0 && !options.ignoreError) {
        throw new Error(`Command failed: ${command}\nStderr: ${result.stderr}\nStdout: ${result.stdout}`);
    }
    return result;
}

// ==== ROLLBACK FUNCTIONS ====

/**
 * Get list of releases (sorted oldest to newest)
 */
async function getReleases(ssh) {
    const releasesPath = `${CONFIG.paths.basePath}/${CONFIG.paths.releasesDir}`;
    const result = await execSSH(ssh, `ls -1 ${releasesPath} 2>/dev/null | sort`, { ignoreError: true });

    if (!result.stdout.trim()) {
        return [];
    }

    return result.stdout.trim().split('\n').filter(r => r && /^\d{14}$/.test(r));
}

/**
 * Get current release name from symlink
 */
async function getCurrentRelease(ssh) {
    const currentPath = `${CONFIG.paths.basePath}/${CONFIG.paths.currentLink}`;
    const result = await execSSH(ssh, `readlink ${currentPath} 2>/dev/null | xargs basename`, { ignoreError: true });

    if (result.code !== 0 || !result.stdout.trim()) {
        return null;
    }

    return result.stdout.trim();
}

/**
 * Switch current symlink to target release
 */
async function switchToRelease(ssh, releaseName) {
    const releasesPath = `${CONFIG.paths.basePath}/${CONFIG.paths.releasesDir}`;
    const releasePath = `${releasesPath}/${releaseName}`;
    const currentPath = `${CONFIG.paths.basePath}/${CONFIG.paths.currentLink}`;

    // Verify release exists
    const exists = await execSSH(ssh, `test -d ${releasePath} && echo "yes"`, { ignoreError: true });
    if (exists.stdout.trim() !== 'yes') {
        throw new Error(`Release ${releaseName} does not exist`);
    }

    // Switch symlink
    await execSSH(ssh, `ln -sfn ${releasePath} ${currentPath}`);
}

/**
 * Reload services after rollback
 */
async function reloadServices(ssh) {
    log('♻️', 'Reloading Apache...');
    await execSSH(ssh, 'sudo systemctl reload apache2');

    log('♻️', 'Restarting queue workers...');
    await execSSH(ssh, 'sudo supervisorctl restart webhook-relay-horizon:*', { ignoreError: true });

    log('♻️', 'Restarting Reverb WebSocket server...');
    await execSSH(ssh, 'sudo supervisorctl restart webhook-relay-reverb', { ignoreError: true });

    log('✅', 'Services reloaded');
}

/**
 * Clear caches on the target release
 */
async function clearCaches(ssh, releasePath) {
    log('🧹', 'Clearing caches...');
    await execSSH(ssh, `cd ${releasePath} && php artisan config:clear`, { ignoreError: true });
    await execSSH(ssh, `cd ${releasePath} && php artisan config:cache`, { ignoreError: true });
    await execSSH(ssh, `cd ${releasePath} && php artisan route:cache`, { ignoreError: true });
    await execSSH(ssh, `cd ${releasePath} && php artisan view:cache`, { ignoreError: true });
    log('✅', 'Caches cleared');
}

// ==== SENTRY RELEASE TRACKING ====

/**
 * Update SENTRY_RELEASE in the shared .env on the server.
 */
async function updateSentryRelease(ssh, releaseName) {
    if (!/^\d{14}$/.test(releaseName)) {
        log('⚠️', `Skipping Sentry release update: invalid release name "${releaseName}"`);
        return;
    }

    const envFile = `${CONFIG.paths.basePath}/shared/.env`;

    log('📝', `Setting SENTRY_RELEASE=${releaseName} in shared .env...`);

    const result = await execSSH(ssh, `grep -q '^SENTRY_RELEASE=' ${envFile} && echo "exists" || echo "missing"`, { ignoreError: true });

    if (result.stdout.trim() === 'exists') {
        await execSSH(ssh, `sed -i 's/^SENTRY_RELEASE=.*/SENTRY_RELEASE=${releaseName}/' ${envFile}`);
    } else {
        await execSSH(ssh, `echo 'SENTRY_RELEASE=${releaseName}' >> ${envFile}`);
    }

    log('✅', 'SENTRY_RELEASE updated in shared .env');
}

/**
 * Register a Sentry deploy for the rolled-back release.
 * Gated behind SENTRY_AUTH_TOKEN — skips gracefully if not configured.
 */
function registerSentryDeploy(releaseName) {
    if (!/^\d{14}$/.test(releaseName)) {
        return;
    }

    if (!process.env.SENTRY_AUTH_TOKEN) {
        log('⚠️', 'Skipping Sentry deploy registration: SENTRY_AUTH_TOKEN not set');
        return;
    }

    const org = process.env.SENTRY_ORG;
    const project = process.env.SENTRY_PROJECT;

    if (!org || !project) {
        log('⚠️', 'Skipping Sentry deploy registration: SENTRY_ORG or SENTRY_PROJECT not set');
        return;
    }

    log('📡', `Registering Sentry deploy for release: ${releaseName}...`);

    const sentryCliPath = path.join(PROJECT_ROOT, 'node_modules', '.bin', 'sentry-cli');
    const env = {
        ...process.env,
        SENTRY_AUTH_TOKEN: process.env.SENTRY_AUTH_TOKEN,
        SENTRY_ORG: org,
        SENTRY_PROJECT: project,
    };

    try {
        execSync(`"${sentryCliPath}" deploys new -r ${releaseName} -e production`, { stdio: 'pipe', env });
        log('✅', 'Sentry deploy registered');
    } catch (error) {
        const stderr = error.stderr ? error.stderr.toString().trim() : error.message;
        log('⚠️', `Sentry deploy registration failed (non-fatal): ${stderr}`);
    }
}

// ==== MAIN ====

async function rollback(options = {}) {
    const ssh = new NodeSSH();

    try {
        // Validate configuration
        if (!CONFIG.server.host || !CONFIG.server.username || !CONFIG.server.privateKey) {
            throw new Error('Missing required environment variables: SERVER_HOST, SERVER_USERNAME, SERVER_PRIVATE_KEY');
        }

        if (!fs.existsSync(CONFIG.server.privateKey)) {
            throw new Error(`Private key file not found: ${CONFIG.server.privateKey}`);
        }

        // Connect to server
        log('🔌', 'Connecting to server...');
        const privateKeyContent = fs.readFileSync(CONFIG.server.privateKey, 'utf8');
        await ssh.connect({
            host: CONFIG.server.host,
            username: CONFIG.server.username,
            privateKey: privateKeyContent,
        });
        log('✅', 'Connected to server');

        // Get releases and current release
        const releases = await getReleases(ssh);
        const currentRelease = await getCurrentRelease(ssh);

        if (releases.length === 0) {
            throw new Error('No releases found on server');
        }

        log('📋', `Found ${releases.length} release(s)`);
        log('📍', `Current release: ${currentRelease || 'none'}`);

        // Determine target release
        let targetRelease;

        if (options.toRelease) {
            // Roll back to specific release
            targetRelease = options.toRelease;
            if (!releases.includes(targetRelease)) {
                throw new Error(`Release ${targetRelease} not found. Available: ${releases.join(', ')}`);
            }
        } else {
            // Roll back N releases
            const currentIndex = releases.indexOf(currentRelease);
            if (currentIndex === -1) {
                throw new Error('Current release not found in releases list');
            }

            const targetIndex = currentIndex - options.releases;
            if (targetIndex < 0) {
                throw new Error(`Cannot roll back ${options.releases} release(s). Only ${currentIndex} previous release(s) available.`);
            }

            targetRelease = releases[targetIndex];
        }

        log('🎯', `Target release: ${targetRelease}`);

        // Dry run check
        if (options.dryRun) {
            log('📝', 'DRY RUN - Would perform:');
            console.log(`   Switch symlink: current -> releases/${targetRelease}`);
            console.log('   Clear caches');
            console.log('   Reload Apache');
            console.log('   Restart queue workers');
            console.log('   Restart Reverb');
            process.exit(0);
        }

        // Confirm rollback
        if (targetRelease === currentRelease) {
            log('⚠️', 'Target release is same as current release. Nothing to do.');
            process.exit(0);
        }

        // Perform rollback
        log('🔄', 'Rolling back...');
        await switchToRelease(ssh, targetRelease);
        log('✅', `Symlink switched to ${targetRelease}`);

        // Update SENTRY_RELEASE in shared .env
        await updateSentryRelease(ssh, targetRelease);

        // Clear caches on the target release
        const releasesPath = `${CONFIG.paths.basePath}/${CONFIG.paths.releasesDir}`;
        await clearCaches(ssh, `${releasesPath}/${targetRelease}`);

        // Reload services
        await reloadServices(ssh);

        // Register Sentry deploy for the rolled-back release
        registerSentryDeploy(targetRelease);

        // Done
        log('🎉', `Rollback complete! Now running: ${targetRelease}`);
        log('🌐', 'Site: https://api.webhook-relay.dcsuniverse.com');

    } catch (error) {
        log('❌', `Rollback failed: ${error.message}`);
        process.exit(1);
    } finally {
        ssh.dispose();
    }
}

// ==== PARSE ARGUMENTS ====

const args = process.argv.slice(2);

// Show help
if (args.includes('--help') || args.includes('-h')) {
    console.log(`
Webhook Relay - Rollback Script

Usage: node scripts/rollback.js [options]

Options:
  --releases=N    Number of releases to roll back (default: 1)
  --to=TIMESTAMP  Roll back to a specific release by name
  --dry-run       Show what would happen without making changes
  --help, -h      Show this help message

Examples:
  node scripts/rollback.js                     Roll back 1 release
  node scripts/rollback.js --releases=2        Roll back 2 releases
  node scripts/rollback.js --to=20260115120000 Roll back to specific release
  node scripts/rollback.js --dry-run           Preview rollback
`);
    process.exit(0);
}

// Parse options
const options = {
    releases: 1,
    toRelease: null,
    dryRun: args.includes('--dry-run'),
};

for (const arg of args) {
    if (arg.startsWith('--releases=')) {
        options.releases = parseInt(arg.split('=')[1], 10);
        if (isNaN(options.releases) || options.releases < 1) {
            console.error('❌ --releases must be a positive integer');
            process.exit(1);
        }
    }
    if (arg.startsWith('--to=')) {
        options.toRelease = arg.split('=')[1];
        if (!/^\d{14}$/.test(options.toRelease)) {
            console.error('❌ --to must be a valid release name (YYYYMMDDHHMMSS)');
            process.exit(1);
        }
    }
}

rollback(options);
