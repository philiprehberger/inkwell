/**
 * Webhook Relay - List Releases Script
 *
 * Shows all releases on the server with timestamps and which is current.
 *
 * Usage: node scripts/releases.js [options]
 *   --json          Output as JSON
 *   --verbose       Show additional details about each release
 */

require('dotenv').config();

const fs = require('fs');
const { NodeSSH } = require('node-ssh');

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
        sharedDir: 'shared',
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
        throw new Error(`Command failed: ${command}\nStderr: ${result.stderr}`);
    }
    return result;
}

/**
 * Parse release name into readable date
 */
function parseReleaseName(name) {
    if (!/^\d{14}$/.test(name)) {
        return null;
    }

    const year = name.substring(0, 4);
    const month = name.substring(4, 6);
    const day = name.substring(6, 8);
    const hour = name.substring(8, 10);
    const minute = name.substring(10, 12);
    const second = name.substring(12, 14);

    return new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}`);
}

/**
 * Format date for display
 */
function formatDate(date) {
    return date.toLocaleString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    });
}

/**
 * Format relative time (e.g., "2 hours ago")
 */
function formatRelative(date) {
    const now = new Date();
    const diffMs = now - date;
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHour = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHour / 24);

    if (diffDay > 0) {
        return `${diffDay} day${diffDay > 1 ? 's' : ''} ago`;
    }
    if (diffHour > 0) {
        return `${diffHour} hour${diffHour > 1 ? 's' : ''} ago`;
    }
    if (diffMin > 0) {
        return `${diffMin} minute${diffMin > 1 ? 's' : ''} ago`;
    }
    return 'just now';
}

// ==== MAIN ====

async function listReleases(options = {}) {
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
        if (!options.json) {
            log('🔌', 'Connecting to server...');
        }

        const privateKeyContent = fs.readFileSync(CONFIG.server.privateKey, 'utf8');
        await ssh.connect({
            host: CONFIG.server.host,
            username: CONFIG.server.username,
            privateKey: privateKeyContent,
        });

        if (!options.json) {
            log('✅', 'Connected');
        }

        // Get releases
        const releasesPath = `${CONFIG.paths.basePath}/${CONFIG.paths.releasesDir}`;
        const result = await execSSH(ssh, `ls -1 ${releasesPath} 2>/dev/null | sort -r`, { ignoreError: true });

        const releases = result.stdout.trim()
            ? result.stdout.trim().split('\n').filter(r => r && /^\d{14}$/.test(r))
            : [];

        // Get current release
        const currentPath = `${CONFIG.paths.basePath}/${CONFIG.paths.currentLink}`;
        const currentResult = await execSSH(ssh, `readlink ${currentPath} 2>/dev/null | xargs basename`, { ignoreError: true });
        const currentRelease = currentResult.stdout.trim() || null;

        // Get release sizes if verbose
        let releaseSizes = {};
        if (options.verbose && releases.length > 0) {
            for (const release of releases) {
                const sizeResult = await execSSH(ssh, `du -sh ${releasesPath}/${release} 2>/dev/null | cut -f1`, { ignoreError: true });
                releaseSizes[release] = sizeResult.stdout.trim() || 'unknown';
            }
        }

        // Get shared storage info
        let sharedInfo = null;
        if (options.verbose) {
            const sharedPath = `${CONFIG.paths.basePath}/${CONFIG.paths.sharedDir}`;
            const sharedSizeResult = await execSSH(ssh, `du -sh ${sharedPath} 2>/dev/null | cut -f1`, { ignoreError: true });
            const envExists = await execSSH(ssh, `test -f ${sharedPath}/.env && echo "yes" || echo "no"`, { ignoreError: true });
            sharedInfo = {
                size: sharedSizeResult.stdout.trim() || 'unknown',
                envExists: envExists.stdout.trim() === 'yes',
            };
        }

        // Output
        if (options.json) {
            const output = {
                server: CONFIG.server.host,
                basePath: CONFIG.paths.basePath,
                currentRelease,
                releases: releases.map(name => ({
                    name,
                    date: parseReleaseName(name)?.toISOString() || null,
                    isCurrent: name === currentRelease,
                    size: releaseSizes[name] || null,
                })),
                shared: sharedInfo,
            };
            console.log(JSON.stringify(output, null, 2));
        } else {
            console.log('');
            console.log('╔════════════════════════════════════════════════════════════════╗');
            console.log('║                    RELEASE INFORMATION                         ║');
            console.log('╠════════════════════════════════════════════════════════════════╣');
            console.log(`║  Server: ${CONFIG.server.host.padEnd(52)}║`);
            console.log(`║  Path:   ${CONFIG.paths.basePath.padEnd(52)}║`);
            console.log('╚════════════════════════════════════════════════════════════════╝');
            console.log('');

            if (releases.length === 0) {
                console.log('  No releases found.');
                console.log('');
                return;
            }

            console.log(`  Found ${releases.length} release(s):`);
            console.log('');
            console.log('  ┌────────────────┬───────────────────────────────────┬─────────────────┐');
            console.log('  │ Release        │ Date                              │ Status          │');
            console.log('  ├────────────────┼───────────────────────────────────┼─────────────────┤');

            for (const release of releases) {
                const date = parseReleaseName(release);
                const dateStr = date ? formatDate(date) : 'Unknown';
                const isCurrent = release === currentRelease;
                const status = isCurrent ? '✓ CURRENT' : '';
                const size = releaseSizes[release] ? ` (${releaseSizes[release]})` : '';

                console.log(`  │ ${release} │ ${dateStr.padEnd(33)} │ ${(status + size).padEnd(15)} │`);
            }

            console.log('  └────────────────┴───────────────────────────────────┴─────────────────┘');

            if (currentRelease) {
                const currentDate = parseReleaseName(currentRelease);
                if (currentDate) {
                    console.log('');
                    console.log(`  Current deployment: ${formatRelative(currentDate)}`);
                }
            }

            if (sharedInfo) {
                console.log('');
                console.log('  Shared Directory:');
                console.log(`    Size: ${sharedInfo.size}`);
                console.log(`    .env: ${sharedInfo.envExists ? 'exists' : 'MISSING!'}`);
            }

            console.log('');
        }

    } catch (error) {
        if (options.json) {
            console.log(JSON.stringify({ error: error.message }));
        } else {
            log('❌', `Error: ${error.message}`);
        }
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
Webhook Relay - List Releases

Usage: node scripts/releases.js [options]

Options:
  --json          Output as JSON (for scripting)
  --verbose       Show additional details (size, shared info)
  --help, -h      Show this help message

Output:
  Lists all releases on the server with their deployment dates.
  The current active release is marked with ✓ CURRENT.
`);
    process.exit(0);
}

const options = {
    json: args.includes('--json'),
    verbose: args.includes('--verbose') || args.includes('-v'),
};

listReleases(options);
