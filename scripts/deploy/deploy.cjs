/**
 * Webhook Relay - Deployment Router
 *
 * Detects the current platform and delegates to the appropriate
 * platform-specific deployment script, passing all flags through.
 *
 * Usage: node scripts/deploy/deploy.cjs [options]
 *
 * Platform detection:
 *   - Windows (incl. PowerShell, cmd) => scripts/deploy/deploy-windows.cjs
 *   - Linux / WSL / macOS             => scripts/deploy/deploy-linux.cjs
 */

const { execSync } = require('child_process');
const path = require('path');
const os = require('os');

const platform = os.platform();
const args = process.argv.slice(2).join(' ');

let script;
if (platform === 'win32') {
    script = 'deploy-windows.cjs';
} else {
    script = 'deploy-linux.cjs';
}

const scriptPath = path.join(__dirname, script);

console.log(`[deploy] Platform: ${platform} => ${script}`);

try {
    execSync(`node "${scriptPath}" ${args}`, {
        stdio: 'inherit',
        cwd: path.join(__dirname, '..', '..'),
    });
} catch (error) {
    // The child script handles its own error output and exit codes
    process.exit(error.status || 1);
}
