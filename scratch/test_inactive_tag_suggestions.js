const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function element() {
    return {
        value: '', textContent: '', children: [], listeners: {},
        classList: { toggle() {} },
        setAttribute() {},
        addEventListener(type, listener) { this.listeners[type] = listener; },
        appendChild(child) { this.children.push(child); },
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; }
    };
}
const nodes = new Map();
const ids = ['document-tag-input', 'document-tag-add', 'document-tag-selected', 'document-tag-options',
    'document-tag-suggestions', 'document-tag-suggestion-list', 'document-tag-count', 'document-tag-feedback',
    'document-title', 'document-description', 'text-content-input', 'code-source-input'];
ids.forEach(id => nodes.set(id, element()));
nodes.get('document-title').value = 'Tecnologia TECNOLOGIA Gestão GESTÃO Rede Kubernetes Monitoramento';
const context = {
    document: { getElementById: id => nodes.get(id), createElement: () => element() },
    documentTagCatalog: [{ id: 10, name: 'Kubernetes', type_label: 'Tecnologia', aliases: [] }],
    inactiveDocumentTagNames: ['tecnologia', 'gestao', 'rede'],
    initialDocumentTagIds: [], initialNewDocumentTagNames: []
};
const source = fs.readFileSync(path.join(__dirname, '../admin/index.php'), 'utf8');
const start = source.indexOf('function initDocumentTags()');
const end = source.indexOf('function initBatchFileUpload()', start);
vm.runInNewContext(source.slice(start, end) + '\ninitDocumentTags();', context);
const suggestions = () => nodes.get('document-tag-suggestion-list').children.map(child => child.textContent);
assert(suggestions().includes('+ Kubernetes'), 'A tag ativa deveria aparecer.');
assert(suggestions().includes('+ Monitoramento'), 'Uma tag nova permitida deveria aparecer.');
assert(!suggestions().some(name => /tecnologia|gestão|rede/i.test(name)), 'Nome ou sinônimo desativado reapareceu como sugestão.');
assert.deepEqual(nodes.get('document-tag-options').children.map(child => child.value), ['Kubernetes']);
nodes.get('document-tag-input').value = ' GESTÃO ';
nodes.get('document-tag-add').listeners.click();
assert.match(nodes.get('document-tag-feedback').textContent, /desativada/);
assert.equal(nodes.get('document-tag-selected').children.length, 0, 'Uma tag desativada foi adicionada manualmente.');
nodes.get('document-tag-input').value = 'Monitoramento';
nodes.get('document-tag-add').listeners.click();
assert(nodes.get('document-tag-selected').children.some(child => child.name === 'new_tags[]' && child.value === 'Monitoramento'));
assert(!suggestions().includes('+ Monitoramento'), 'Tag selecionada continuou nas sugestões.');
console.log('OK: sugestões excluem tags e sinônimos desativados, com acentos e maiúsculas; tags ativas e novas continuam disponíveis.');
