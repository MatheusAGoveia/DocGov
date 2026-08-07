/**
 * assets/viewer.js — Visualizador de documentos (PDF.js + tipos auxiliares)
 * Filosofia: conteúdo protagonista, interface mínima.
 */

import {
  getDocument,
  GlobalWorkerOptions,
  version,
} from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.mjs';

GlobalWorkerOptions.workerSrc =
  'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.worker.mjs';

/* ── Utilitários de UI ── */

function qs(sel, ctx = document) {
  return ctx.querySelector(sel);
}

function qsa(sel, ctx = document) {
  return [...ctx.querySelectorAll(sel)];
}

function initMenus() {
  qsa('[data-menu-toggle]').forEach((btn) => {
    const wrap = btn.closest('.viewer-menu-wrap');
    const menu = wrap?.querySelector('.viewer-menu');
    if (!menu) return;

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = menu.classList.contains('is-open');
      qsa('.viewer-menu.is-open').forEach((m) => m.classList.remove('is-open'));
      if (!isOpen) menu.classList.add('is-open');
    });
  });

  document.addEventListener('click', () => {
    qsa('.viewer-menu.is-open').forEach((m) => m.classList.remove('is-open'));
  });

  qsa('.viewer-menu').forEach((menu) => {
    menu.addEventListener('click', (e) => e.stopPropagation());
  });

  qsa('[data-details-toggle]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const panel = qs('#viewer-details-panel');
      if (panel) panel.classList.toggle('is-open');
    });
  });
}

function initFavorite() {
  const btn = qs('#btn-favorito');
  if (!btn) return;
  const docId = btn.dataset.docId;
  btn.addEventListener('click', () => {
    fetch(`api_user.php?action=toggle_favorito&doc_id=${docId}`)
      .then((r) => r.json())
      .then((data) => {
        if (!data.success) return;
        btn.classList.toggle('is-active', data.is_favorite);
        btn.title = data.is_favorite ? 'Favoritado' : 'Favoritar';
        btn.setAttribute('aria-label', btn.title);
      })
      .catch(() => {});
  });
}

/* ── PDF Viewer ── */

class PdfViewer {
  constructor(root) {
    this.root = root;
    this.url = root.dataset.url;
    this.downloadUrl = root.dataset.download || this.url.replace('&inline=1', '');
    this.toolbar = qs('.pdf-toolbar', root);
    this.pagesEl = qs('.pdf-pages', root);
    this.pageIndicator = qs('.pdf-page-indicator', root);
    this.zoomLabel = qs('.pdf-zoom-label', root);

    this.pdfDoc = null;
    this.totalPages = 0;
    this.currentPage = 1;
    this.scale = 1;
    this.baseScale = 1;
    this.fitWidthMode = true;
    this.rendered = new Set();
    this.rendering = new Map();
    this.pageElements = [];
    this.observer = null;
    this.pageObserver = null;
    this.containerWidth = 0;
  }

  async init() {
    try {
      const loadingTask = getDocument({ url: this.url, withCredentials: true });
      this.pdfDoc = await loadingTask.promise;
      this.totalPages = this.pdfDoc.numPages;
      this.pageIndicator.textContent = `1 / ${this.totalPages}`;

      const metaPages = document.getElementById('meta-page-count');
      if (metaPages) {
        metaPages.textContent = this.totalPages === 1 ? '1 página' : `${this.totalPages} páginas`;
      }

      this.buildPagePlaceholders();
      this.bindToolbar();
      this.setupLazyObserver();
      this.setupPageTracker();

      await this.updateContainerWidth();
      this.fitWidth();
    } catch (err) {
      this.pagesEl.innerHTML = `<div class="pdf-error">Não foi possível carregar o PDF. Verifique se o arquivo existe e tente novamente.</div>`;
      console.error('PDF load error:', err);
    }
  }

  buildPagePlaceholders() {
    this.pagesEl.innerHTML = '';
    this.pageElements = [];

    for (let i = 1; i <= this.totalPages; i++) {
      const pageDiv = document.createElement('div');
      pageDiv.className = 'pdf-page';
      pageDiv.dataset.pageNum = String(i);
      pageDiv.dataset.rendered = '0';

      const aspectPlaceholder = document.createElement('div');
      aspectPlaceholder.className = 'pdf-page-placeholder';
      aspectPlaceholder.style.minHeight = '400px';
      aspectPlaceholder.textContent = `Página ${i}`;
      pageDiv.appendChild(aspectPlaceholder);

      this.pagesEl.appendChild(pageDiv);
      this.pageElements.push(pageDiv);
    }
  }

  async updateContainerWidth() {
    this.containerWidth = this.pagesEl.clientWidth || this.root.clientWidth || 800;
  }

  getScale() {
    if (this.fitWidthMode) return this.baseScale;
    return this.scale;
  }

  async computeFitWidthScale(pageNum = 1) {
    const page = await this.pdfDoc.getPage(pageNum);
    const viewport = page.getViewport({ scale: 1 });
    const padding = 0;
    this.baseScale = (this.containerWidth - padding) / viewport.width;
    return this.baseScale;
  }

  async fitWidth() {
    this.fitWidthMode = true;
    await this.updateContainerWidth();
    await this.computeFitWidthScale(1);
    this.scale = this.baseScale;
    this.updateZoomLabel();
    this.markAllForRerender();
    this.renderVisiblePages();
    qs('[data-action="fit-width"]', this.toolbar)?.classList.add('is-active');
    qs('[data-action="zoom-in"]', this.toolbar)?.classList.remove('is-active');
    qs('[data-action="zoom-out"]', this.toolbar)?.classList.remove('is-active');
  }

  zoomIn() {
    this.fitWidthMode = false;
    this.scale = Math.min(this.scale * 1.2, 4);
    this.updateZoomLabel();
    this.markAllForRerender();
    this.renderVisiblePages();
    qs('[data-action="fit-width"]', this.toolbar)?.classList.remove('is-active');
  }

  zoomOut() {
    this.fitWidthMode = false;
    this.scale = Math.max(this.scale / 1.2, 0.3);
    this.updateZoomLabel();
    this.markAllForRerender();
    this.renderVisiblePages();
    qs('[data-action="fit-width"]', this.toolbar)?.classList.remove('is-active');
  }

  updateZoomLabel() {
    const pct = Math.round(this.getScale() * 100);
    if (this.zoomLabel) this.zoomLabel.textContent = `${pct}%`;
  }

  markAllForRerender() {
    this.rendered.clear();
    this.pageElements.forEach((el) => {
      el.dataset.rendered = '0';
      const existing = el.querySelector('.pdf-page-inner');
      if (existing) existing.remove();
      let ph = el.querySelector('.pdf-page-placeholder');
      if (!ph) {
        ph = document.createElement('div');
        ph.className = 'pdf-page-placeholder';
        ph.style.minHeight = '400px';
        ph.textContent = `Página ${el.dataset.pageNum}`;
        el.appendChild(ph);
      }
    });
  }

  setupLazyObserver() {
    this.observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const pageNum = parseInt(entry.target.dataset.pageNum, 10);
            this.renderPage(pageNum);
          }
        });
      },
      { root: null, rootMargin: '600px 0px', threshold: 0 }
    );

    this.pageElements.forEach((el) => this.observer.observe(el));
  }

  setupPageTracker() {
    this.pageObserver = new IntersectionObserver(
      (entries) => {
        let best = { page: this.currentPage, ratio: 0 };
        entries.forEach((entry) => {
          if (entry.intersectionRatio > best.ratio) {
            best = {
              page: parseInt(entry.target.dataset.pageNum, 10),
              ratio: entry.intersectionRatio,
            };
          }
        });
        if (best.ratio > 0.1 && best.page !== this.currentPage) {
          this.currentPage = best.page;
          this.pageIndicator.textContent = `${this.currentPage} / ${this.totalPages}`;
        }
      },
      { root: null, rootMargin: '-20% 0px -60% 0px', threshold: [0, 0.1, 0.25, 0.5, 0.75, 1] }
    );

    this.pageElements.forEach((el) => this.pageObserver.observe(el));
  }

  renderVisiblePages() {
    this.pageElements.forEach((el) => {
      const rect = el.getBoundingClientRect();
      if (rect.top < window.innerHeight + 600 && rect.bottom > -600) {
        this.renderPage(parseInt(el.dataset.pageNum, 10));
      }
    });
  }

  async renderPage(pageNum) {
    if (this.rendered.has(pageNum) || this.rendering.has(pageNum)) return;

    const pageEl = this.pageElements[pageNum - 1];
    if (!pageEl) return;

    this.rendering.set(pageNum, true);

    try {
      const page = await this.pdfDoc.getPage(pageNum);
      const scale = this.getScale();
      const viewport = page.getViewport({ scale });

      const inner = document.createElement('div');
      inner.className = 'pdf-page-inner';
      inner.style.width = `${viewport.width}px`;
      inner.style.height = `${viewport.height}px`;
      inner.style.position = 'relative';

      const canvas = document.createElement('canvas');
      canvas.width = viewport.width * 2;
      canvas.height = viewport.height * 2;
      canvas.style.width = `${viewport.width}px`;
      canvas.style.height = `${viewport.height}px`;

      const ctx = canvas.getContext('2d');
      ctx.scale(2, 2);
      await page.render({ canvasContext: ctx, viewport }).promise;

      const placeholder = pageEl.querySelector('.pdf-page-placeholder');
      if (placeholder) placeholder.remove();
      const oldInner = pageEl.querySelector('.pdf-page-inner');
      if (oldInner) oldInner.remove();

      pageEl.style.width = `${viewport.width}px`;
      pageEl.appendChild(inner);

      try {
        const textLayerDiv = document.createElement('div');
        textLayerDiv.className = 'textLayer';
        inner.appendChild(textLayerDiv);

        const textContentSource = await page.getTextContent();
        const textLayer = await import('https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.mjs').then((mod) => new mod.TextLayer({
          textContentSource,
          container: textLayerDiv,
          viewport,
        }));
        await textLayer.render();
      } catch {
        /* text layer opcional */
      }

      this.rendered.add(pageNum);
      pageEl.dataset.rendered = '1';
    } catch (err) {
      console.error(`Erro ao renderizar página ${pageNum}:`, err);
    } finally {
      this.rendering.delete(pageNum);
    }
  }

  bindToolbar() {
    qs('[data-action="zoom-out"]', this.toolbar)?.addEventListener('click', () => this.zoomOut());
    qs('[data-action="zoom-in"]', this.toolbar)?.addEventListener('click', () => this.zoomIn());
    qs('[data-action="fit-width"]', this.toolbar)?.addEventListener('click', () => this.fitWidth());

    qs('[data-action="fullscreen"]', this.toolbar)?.addEventListener('click', () => {
      const el = this.root;
      if (!document.fullscreenElement) {
        el.requestFullscreen?.().catch(() => {});
      } else {
        document.exitFullscreen?.();
      }
    });

    qs('[data-action="download"]', this.toolbar)?.addEventListener('click', () => {
      window.location.href = this.downloadUrl;
    });

    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(async () => {
        if (this.fitWidthMode) {
          await this.updateContainerWidth();
          await this.fitWidth();
        }
      }, 200);
    });
  }
}

/* ── Image Viewer ── */

function initImageViewer() {
  const wrap = qs('[data-viewer="image"]');
  if (!wrap) return;

  const img = qs('img', wrap);
  const inner = qs('.image-viewer-inner', wrap);
  let scale = 1;

  qs('[data-action="img-zoom-in"]')?.addEventListener('click', () => {
    scale = Math.min(scale * 1.25, 4);
    img.style.transform = `scale(${scale})`;
    img.style.transformOrigin = 'top center';
  });

  qs('[data-action="img-zoom-out"]')?.addEventListener('click', () => {
    scale = Math.max(scale / 1.25, 0.5);
    img.style.transform = `scale(${scale})`;
  });

  qs('[data-action="img-fit"]')?.addEventListener('click', () => {
    scale = 1;
    img.style.transform = '';
  });

  qs('[data-action="img-download"]')?.addEventListener('click', () => {
    const url = wrap.dataset.download;
    if (url) window.location.href = url;
  });
}

/* ── TXT loader ── */

async function initTxtViewer() {
  const el = qs('[data-viewer="txt"]');
  if (!el) return;

  const url = el.dataset.url;
  const pre = qs('.viewer-txt', el);
  if (!url || !pre) return;

  try {
    const res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) throw new Error('fetch failed');
    pre.textContent = await res.text();
  } catch {
    pre.textContent = 'Não foi possível carregar o conteúdo do arquivo.';
  }
}

/* ── Boot ── */

document.addEventListener('DOMContentLoaded', () => {
  initMenus();
  initFavorite();
  initImageViewer();
  initTxtViewer();

  const pdfRoot = qs('[data-viewer="pdf"]');
  if (pdfRoot) {
    const viewer = new PdfViewer(pdfRoot);
    viewer.init();
  }
});
