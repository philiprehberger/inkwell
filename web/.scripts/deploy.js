// Build locally + rsync to /var/www/inkwell-web + pm2 reload.
import 'dotenv/config';
import { execSync } from 'node:child_process';
import { existsSync, cpSync, mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { NodeSSH } from 'node-ssh';

const HOST = process.env.SERVER_HOST;
const USER = process.env.SERVER_USERNAME;
const KEY = process.env.SERVER_PRIVATE_KEY;
const DEST = process.env.SERVER_DEST_PATH || '/var/www/inkwell-web';
const PM2 = process.env.SERVER_PM2_PROCESS || 'inkwell-web';

if (!HOST || !USER || !KEY) {
  console.error('Missing SERVER_HOST / SERVER_USERNAME / SERVER_PRIVATE_KEY in web/.env');
  process.exit(1);
}

console.log('1/5  Copying latest OpenAPI spec into public/...');
cpSync(resolve('../openapi/spec.yaml'), resolve('public/openapi.yaml'));

console.log('2/5  next build...');
execSync('next build', { stdio: 'inherit' });

console.log('3/5  Preparing standalone bundle...');
const standalone = resolve('.next/standalone');
if (!existsSync(standalone)) {
  console.error('.next/standalone not found — is `output: "standalone"` set in next.config.ts?');
  process.exit(1);
}
const standaloneAppRoot = join(standalone, 'web');
mkdirSync(join(standaloneAppRoot, 'public'), { recursive: true });
mkdirSync(join(standaloneAppRoot, '.next/static'), { recursive: true });
cpSync(resolve('public'), join(standaloneAppRoot, 'public'), { recursive: true });
cpSync(resolve('.next/static'), join(standaloneAppRoot, '.next/static'), { recursive: true });
if (existsSync(join(standalone, 'node_modules'))) {
  cpSync(join(standalone, 'node_modules'), join(standaloneAppRoot, 'node_modules'), {
    recursive: true,
    force: false,
  });
}

console.log('4/5  rsync to server...');
execSync(
  `rsync -az --delete --exclude=.env -e "ssh -i ${KEY} -o StrictHostKeyChecking=accept-new" ${standaloneAppRoot}/ ${USER}@${HOST}:${DEST}/`,
  { stdio: 'inherit' },
);

console.log('5/5  pm2 reload (via login shell)...');
const ssh = new NodeSSH();
await ssh.connect({ host: HOST, username: USER, privateKeyPath: KEY });
const cmd = `bash -lc "command -v pm2 || export PATH=\\"\\$(ls -d /home/ubuntu/.nvm/versions/node/*/bin | head -1):\\$PATH\\"; pm2 reload ${PM2} || pm2 start /var/www/ecosystem.config.js --only ${PM2}; pm2 save"`;
const result = await ssh.execCommand(cmd);
if (result.stdout) console.log(result.stdout);
if (result.stderr) console.error(result.stderr);
ssh.dispose();
console.log('Deployed.');
