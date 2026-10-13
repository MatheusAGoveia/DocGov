const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {
    File, TextEncoder, window: {},
    document: { getElementById: () => ({ dataset: { limits: '{"file":52428800,"post":536870912}' } }), addEventListener() {} }
};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/upload-validation.js'), 'utf8'), context);
const validate = context.window.DocGovUploads.validate;
const mb = 1048576;
const file = (name, bytes) => {
    const value = new File([], name);
    Object.defineProperty(value, 'size', { value: bytes });
    return value;
};
const limits = { file: 50 * mb, post: 512 * mb };
assert.equal(validate([['video_file', file('video.mp4', 16862239)]], limits), '');
assert.equal(validate([['video_file', file('video.mp4', 50 * mb)]], limits), '');
assert.match(validate([['video_file', file('video.mp4', 50 * mb + 1)]], limits), /50 MB/);
assert.match(validate([['video_file', file('video.mp4', 51 * mb)]], { file: 250 * mb, post: 512 * mb }), /50 MB/);
assert.match(validate([['arquivo_file', file('manual.pdf', 26 * mb)]], limits), /25 MB/);
assert.equal(validate([['arquivo_file[]', file('video.mp4', 50 * mb)]], limits), '');
assert.match(validate([['arquivo_file[]', file('video.mp4', 50 * mb + 1)]], limits), /50 MB/);
assert.match(validate(Array.from({ length: 11 }, () => ['arquivo_file[]', file('video.mp4', 50 * mb)]), limits), /500 MB/);
assert.match(validate([['video_file', file('video.mp4', 16 * mb)]], { file: 50 * mb, post: 8 * mb }), /limite total/);
assert.match(validate([['video_file', file('video.mp4', 16 * mb)]], { file: 2 * mb, post: 512 * mb }), /2 MB/);
console.log('OK: validação de vídeo, arquivos, lotes e limites reais do PHP.');
