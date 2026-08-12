(function () {
    'use strict';

    const LABELS = {
        plaintext: 'Texto simples',
        javascript: 'JavaScript',
        typescript: 'TypeScript',
        xml: 'HTML / XML',
        css: 'CSS',
        php: 'PHP',
        python: 'Python',
        sql: 'SQL',
        bash: 'Shell / Bash',
        json: 'JSON',
        java: 'Java',
        csharp: 'C#',
        cpp: 'C / C++',
        go: 'Go',
        yaml: 'YAML',
        markdown: 'Markdown'
    };

    function languageLabel(language) {
        return LABELS[language] || (language ? language.toUpperCase() : 'Texto simples');
    }

    function inferLanguage(source) {
        const value = source.trim();
        if (!value) return 'plaintext';
        if (/^<\?php\b/i.test(value)) return 'php';
        if (/^#!.*\b(?:bash|sh)\b/m.test(value)) return 'bash';

        if (/^[\[{]/.test(value)) {
            try {
                JSON.parse(value);
                return 'json';
            } catch (error) {
                // Continua com as demais heurísticas.
            }
        }

        if (/<(?:!doctype\s+html|html|head|body|script|style|[a-z][\w-]*\s+[^>]*)[\s>]/i.test(value)) return 'xml';
        if (/\b(?:SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE)\b/i.test(value)) return 'sql';
        if (/(?:^|\n)\s*(?:def|class)\s+\w+|(?:^|\n)\s*(?:from\s+\w+\s+)?import\s+\w+|\bprint\s*\(/m.test(value)) return 'python';
        if (/\b(?:const|let|var)\s+\w+|\bfunction\s+\w+\s*\(|=>|\b(?:console|document|window|navigator)\./.test(value)) return 'javascript';
        if (/^[.#]?[\w-]+(?:\s+[.#]?[\w-]+)*\s*\{[^}]*[\w-]+\s*:/s.test(value)) return 'css';
        return '';
    }

    function highlightSnippet(snippet) {
        const code = snippet.querySelector('[data-code-source]');
        const label = snippet.querySelector('[data-code-language-label]');
        if (!code) return;

        const source = code.textContent || '';
        const requested = snippet.dataset.codeLanguage || 'auto';
        let detected = requested === 'auto' ? '' : requested;

        code.textContent = source;
        code.className = '';

        if (window.hljs) {
            try {
                const inferred = requested === 'auto' ? inferLanguage(source) : requested;
                const result = inferred && window.hljs.getLanguage(inferred)
                    ? window.hljs.highlight(source, { language: inferred, ignoreIllegals: true })
                    : window.hljs.highlightAuto(source);

                code.innerHTML = result.value;
                detected = result.language || detected || 'plaintext';
                code.classList.add('hljs');
                if (detected) code.classList.add('language-' + detected);
            } catch (error) {
                code.textContent = source;
                detected = detected || 'plaintext';
            }
        } else {
            detected = detected || 'plaintext';
        }

        if (label) {
            label.textContent = languageLabel(detected);
            label.title = requested === 'auto'
                ? 'Linguagem reconhecida automaticamente'
                : 'Linguagem selecionada no cadastro';
        }
    }

    async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);
                return;
            } catch (error) {
                // Alguns navegadores bloqueiam a Clipboard API mesmo em contexto
                // seguro. Nesse caso, continua para o fallback compatível.
            }
        }

        const fallback = document.createElement('textarea');
        fallback.value = text;
        fallback.setAttribute('readonly', '');
        fallback.style.position = 'fixed';
        fallback.style.opacity = '0';
        document.body.appendChild(fallback);
        fallback.select();
        const copied = document.execCommand('copy');
        fallback.remove();
        if (!copied) throw new Error('A cópia não foi autorizada pelo navegador.');
    }

    function bindCopyButton(snippet) {
        const button = snippet.querySelector('[data-copy-code]');
        const code = snippet.querySelector('[data-code-source]');
        if (!button || !code || button.dataset.copyBound === '1') return;

        button.dataset.copyBound = '1';
        button.addEventListener('click', async function () {
            const originalLabel = button.querySelector('[data-copy-label]');
            try {
                await copyText(code.textContent || '');
                if (originalLabel) originalLabel.textContent = 'Copiado';
                button.setAttribute('aria-label', 'Código copiado');
            } catch (error) {
                if (originalLabel) originalLabel.textContent = 'Falhou';
            }

            window.setTimeout(function () {
                if (originalLabel) originalLabel.textContent = 'Copiar';
                button.setAttribute('aria-label', 'Copiar código');
            }, 1600);
        });
    }

    function refresh(target) {
        const snippets = target && target.matches && target.matches('[data-code-snippet]')
            ? [target]
            : Array.from((target || document).querySelectorAll('[data-code-snippet]'));

        snippets.forEach(function (snippet) {
            highlightSnippet(snippet);
            bindCopyButton(snippet);
        });
    }

    window.DocGovCodeSnippets = { refresh: refresh };
    document.addEventListener('DOMContentLoaded', function () { refresh(document); });
})();
