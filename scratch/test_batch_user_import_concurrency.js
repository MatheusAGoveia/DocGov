const { execFile, execFileSync } = require('node:child_process');
const { promisify } = require('node:util');
const path = require('node:path');
const helper = path.join(__dirname, 'test_batch_user_import.php');
const php = process.env.DOCGOV_PHP || 'php';
const run = (...args) => execFileSync(php, [helper, ...args], { encoding: 'utf8' }).trim();
const fixture = JSON.parse(run('--prepare'));
(async () => {
    try {
        const responses = await Promise.all([1, 2].map(() => promisify(execFile)(php, [helper, '--concurrent-worker', fixture.token])));
        const results = responses.map(response => JSON.parse(response.stdout));
        if (results.map(result => result.status).sort().join(',') !== 'added,already_member' || results.filter(result => result.created).length !== 1) {
            throw new Error('Importações concorrentes não foram idempotentes: ' + JSON.stringify(results));
        }
        if (JSON.parse(run('--inspect', fixture.token)).members !== 1) throw new Error('Vínculo duplicado ou ausente após concorrência.');
        console.log('PASS concorrência: duas importações simultâneas criaram um cadastro e um vínculo.');
    } finally { run('--cleanup', fixture.token); }
})().catch(error => { console.error(error); process.exitCode = 1; });
