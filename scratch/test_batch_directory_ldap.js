// Servidor LDAP mínimo e local para testar a extensão PHP real sem consultar o AD corporativo.
const net = require('node:net');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const path = require('node:path');
const { createHash } = require('node:crypto');
const domainLoginMode = process.argv.includes('--domain-login');
const assert = (condition, message) => { if (!condition) throw new Error(message); };
function ber(tag, content) {
    content = Buffer.isBuffer(content) ? content : Buffer.concat(content);
    const count = content.length;
    const size = count < 128 ? Buffer.from([count]) : count < 256 ? Buffer.from([0x81, count]) : Buffer.from([0x82, count >> 8, count & 255]);
    return Buffer.concat([Buffer.from([tag]), size, content]);
}
const text = value => ber(4, Buffer.isBuffer(value) ? value : Buffer.from(value));
const integer = value => ber(2, Buffer.from([value]));
function read(buffer, offset = 0) {
    if (offset + 2 > buffer.length) return null;
    let length = buffer[offset + 1], start = offset + 2;
    if (length & 128) {
        const count = length & 127;
        if (start + count > buffer.length) return null;
        length = 0; for (let i = 0; i < count; i++) length = (length << 8) | buffer[start++];
    }
    if (start + length > buffer.length) return null;
    return { tag: buffer[offset], data: buffer.subarray(start, start + length), end: start + length };
}
function children(buffer) {
    const values = []; let offset = 0;
    while (offset < buffer.length) { const value = read(buffer, offset); if (!value) throw new Error('BER incompleto'); values.push(value); offset = value.end; }
    return values;
}
function filters(value) {
    if (value.tag === 0xa3) { const attrs = children(value.data); return [[attrs[0].data.toString(), attrs[1].data.toString()]]; }
    if (value.tag === 0xa0 || value.tag === 0xa1) return children(value.data).flatMap(filters);
    throw new Error('Filtro inesperado: ' + value.tag);
}
function done(messageId, tag, code) { return ber(0x30, [integer(messageId), ber(tag, [ber(0x0a, Buffer.from([code])), text(''), text(code === 49 ? 'AcceptSecurityContext error, data 52e' : '')])]); }
function entry(messageId, login, name, disabled = false, domain = '') {
    const attrs = { sAMAccountName: login, displayName: name, mail: login + '@' + (domain || 'test') + '.invalid', objectGUID: domain ? createHash('sha256').update(domain + ':' + login).digest().subarray(0, 16) : Buffer.alloc(16, 1), userAccountControl: disabled ? '514' : '512' };
    return ber(0x30, [integer(messageId), ber(0x64, [text('CN=' + name + ',DC=test,DC=invalid'), ber(0x30, Object.entries(attrs).map(([key, value]) => ber(0x30, [text(key), ber(0x31, [text(value)])])))])]);
}

(async () => {
    let binds = 0, queries = 0;
    let loginBinds = 0, loginQueries = 0;
    let verifiedEscape = false;
    const sockets = new Set();
    const server = net.createServer(socket => {
        sockets.add(socket); socket.on('close', () => sockets.delete(socket));
        let buffered = Buffer.alloc(0);
        socket.on('data', data => {
            buffered = Buffer.concat([buffered, data]);
            for (;;) {
                const packet = read(buffered); if (!packet) break;
                buffered = buffered.subarray(packet.end);
                const fields = children(packet.data);
                const messageId = fields[0].data[0], op = fields[1];
                if (op.tag === 0x60) {
                    binds++;
                    const bindFields = children(op.data);
                    const identity = bindFields[1].data.toString();
                    const password = bindFields[2].data.toString();
                    const domain = identity.split('\\')[0];
                    if (domainLoginMode && ['BETIM', 'SAUDE'].includes(domain)) loginBinds++;
                    const expected = domainLoginMode && identity.endsWith('\\form.session') ? '  Mock*' + domain + '  ' : 'mock-only';
                    const accepted = password === expected || (domainLoginMode && identity === '' && password === '');
                    socket.boundDomain = accepted && ['BETIM', 'SAUDE'].includes(domain) ? domain : null;
                    socket.write(done(messageId, 0x61, accepted ? 0 : 49));
                } else if (op.tag === 0x63) {
                    queries++;
                    const request = children(op.data);
                    if (request[6].tag === 0x87) {
                        const missing = request[0].data.toString().startsWith('DC=missing,');
                        const entries = missing ? [] : [entry(messageId, 'root', 'Base de teste')];
                        socket.write(Buffer.concat([...entries, done(messageId, 0x65, missing ? 32 : 0)]));
                        continue;
                    }
                    const all = filters(request[6]);
                    const value = all.find(([key]) => key === 'sAMAccountName')[1];
                    let entries = [], code = 0;
                    if (domainLoginMode) {
                        loginQueries++;
                        const domain = request[0].data.toString() === 'DC=betim,DC=test' ? 'betim' : 'saude';
                        assert(socket.boundDomain === domain.toUpperCase(), 'Consulta LDAP saiu do domínio autenticado.');
                        const name = value === 'form.homonym' ? 'Maria da Conceição' : domain === 'betim' ? 'José Antônio de Almeida' : 'José Roberto da Costa';
                        entries = [entry(messageId, value, name, false, domain)];
                    }
                    else if (value === 'Ana Silva' || value === 'ana') entries = [entry(messageId, 'ana', 'Ana Silva')];
                    else if (value === 'Nome Igual') entries = [entry(messageId, 'um', 'Nome Igual'), entry(messageId, 'dois', 'Nome Igual')];
                    else if (value === 'Inativo') entries = [entry(messageId, 'inativo', 'Inativo', true)];
                    else if (value === 'Erro Servidor') code = 52;
                    else if (value === 'Parcial') { entries = ['um', 'dois', 'tres'].map(login => entry(messageId, login, 'Parcial')); code = 4; }
                    else if (value === 'João Conceição') entries = [entry(messageId, 'joao', value)];
                    else if (value === '*)(sAMAccountName=*)') verifiedEscape = all.filter(([key]) => ['sAMAccountName', 'displayName', 'cn'].includes(key)).every(([, content]) => content === value);
                    socket.write(Buffer.concat([...entries, done(messageId, 0x65, code)]));
                } else if (op.tag === 0x42) socket.end();
                else throw new Error('Operação LDAP inesperada: ' + op.tag);
            }
        });
    });
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const port = server.address().port;
        const helper = domainLoginMode ? 'test_ad_domain_identity.php' : 'test_batch_directory_ldap.php';
        const result = await promisify(execFile)(process.env.DOCGOV_PHP || 'php', [path.join(__dirname, helper), String(port)], { timeout: 60000 });
        process.stdout.write(result.stdout);
        if (domainLoginMode) {
            assert(loginBinds === 14 && loginQueries === 13, `Fluxo de login inesperado: ${loginBinds} binds, ${loginQueries} consultas.`);
            console.log('PASS protocolo de login: domínio escolhido, um bind por envio e sessão válida no primeiro acesso.');
        } else {
            assert(binds === 2 && queries === 9, `Conexão não foi reutilizada: ${binds} binds, ${queries} consultas.`);
            assert(verifiedEscape, 'Caracteres do filtro LDAP não foram escapados como valor literal.');
            console.log('PASS protocolo: uma conexão por lote, filtro LDAP escapado, nenhum bind como usuários importados.');
        }
    } finally {
        for (const socket of sockets) socket.destroy();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
