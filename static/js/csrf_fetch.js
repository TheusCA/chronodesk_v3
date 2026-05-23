/**
 * [SECURED] CSRF-aware Fetch Wrapper
 * Inclua este arquivo ANTES dos outros scripts em todas as páginas.
 * 
 * Correções aplicadas:
 * - VULN-005: Inclui automaticamente token CSRF em todas as requisições POST
 * - VULN-020: Debug condicional
 * - VULN-021: Sem setInterval duplicado (controle centralizado)
 */

// [VULN-020] Logger condicional
const APP_DEBUG = document.querySelector('meta[name="app-debug"]')?.content === 'true';
function debugLog(...args) {
    if (APP_DEBUG) console.log(...args);
}

// [VULN-005] Obter token CSRF da meta tag
function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// Wrapper para fetch que inclui CSRF automaticamente
const _originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
    const requestUrl = new URL(url, window.location.href);
    const sameOrigin = requestUrl.origin === window.location.origin;

    // Apenas adicionar CSRF em requisições POST
    if (sameOrigin && options.method && options.method.toUpperCase() === 'POST') {
        if (!options.headers) {
            options.headers = {};
        }
        // Se headers é um Headers object, converter
        if (options.headers instanceof Headers) {
            const h = {};
            options.headers.forEach((v, k) => { h[k] = v; });
            options.headers = h;
        }
        // Adicionar CSRF token se não existir
        if (!options.headers['X-CSRF-Token']) {
            options.headers['X-CSRF-Token'] = getCsrfToken();
        }
        // Garantir Content-Type para JSON
        if (!options.headers['Content-Type'] && typeof options.body === 'string') {
            options.headers['Content-Type'] = 'application/json';
        }
    }
    return _originalFetch.call(this, url, options);
};

debugLog('✅ CSRF fetch wrapper carregado');
