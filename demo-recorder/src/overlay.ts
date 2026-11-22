/**
 * Injected into every page (context.addInitScript): a visible cursor dot
 * that follows the real mouse, a ring where each click lands, and the
 * caption banner. Styled through element.style only, and kept across page
 * loads with sessionStorage (caption text and last cursor position).
 */
export function overlay(): void {
  const w = window as unknown as Record<string, unknown>;
  // Top frame only: the preview overlay's PDF iframe is same-origin and
  // would otherwise draw a second, stale caption over the document.
  if (w.__demoOverlay || window.top !== window) return;
  w.__demoOverlay = true;

  const Z = '2147483647';
  let cursor: HTMLDivElement | null = null;
  let caption: HTMLDivElement | null = null;

  const read = (k: string) => { try { return sessionStorage.getItem(k); } catch { return null; } };
  const write = (k: string, v: string) => { try { sessionStorage.setItem(k, v); } catch { /* ignore */ } };

  function mount(): void {
    if (!document.body) { requestAnimationFrame(mount); return; }

    cursor = document.createElement('div');
    Object.assign(cursor.style, {
      position: 'fixed', width: '22px', height: '22px', borderRadius: '50%', zIndex: Z,
      background: 'rgba(230, 57, 70, 0.9)', border: '3px solid #ffffff', boxShadow: '0 0 0 1px rgba(0,0,0,.35), 0 2px 6px rgba(0,0,0,.35)',
      transform: 'translate(-50%, -50%)', pointerEvents: 'none', left: '-100px', top: '-100px',
    });
    const pos = read('__demoCursor');
    if (pos) { const [x, y] = pos.split(',').map(Number); cursor.style.left = `${x}px`; cursor.style.top = `${y}px`; }

    caption = document.createElement('div');
    Object.assign(caption.style, {
      position: 'fixed', left: '50%', bottom: '44px', transform: 'translateX(-50%)', zIndex: Z,
      maxWidth: '1500px', padding: '18px 38px', borderRadius: '14px', background: '#0b1f3a', color: '#ffffff',
      font: '600 30px/1.35 "DejaVu Sans", "Segoe UI", system-ui, sans-serif', textAlign: 'center',
      boxShadow: '0 10px 30px rgba(0,0,0,.35)', pointerEvents: 'none', transition: 'opacity .25s', opacity: '0',
    });
    // Room below the content, so anything can be scrolled clear of the caption.
    document.body.style.paddingBottom = '170px';
    document.documentElement.appendChild(caption);
    document.documentElement.appendChild(cursor);
    setCaption(read('__demoCaption') ?? '');
  }

  function setCaption(text: string): void {
    write('__demoCaption', text);
    if (!caption) return;
    caption.textContent = text;
    caption.style.opacity = text ? '1' : '0';
  }
  w.__demoSetCaption = setCaption;

  document.addEventListener('mousemove', (e) => {
    if (!cursor) return;
    cursor.style.left = `${e.clientX}px`;
    cursor.style.top = `${e.clientY}px`;
    write('__demoCursor', `${e.clientX},${e.clientY}`);
  }, true);

  document.addEventListener('mousedown', (e) => {
    const ring = document.createElement('div');
    Object.assign(ring.style, {
      position: 'fixed', left: `${e.clientX}px`, top: `${e.clientY}px`, width: '64px', height: '64px', borderRadius: '50%',
      border: '4px solid #ffb703', transform: 'translate(-50%, -50%)', pointerEvents: 'none', zIndex: Z,
    });
    document.documentElement.appendChild(ring);
    ring.animate(
      [{ transform: 'translate(-50%, -50%) scale(.3)', opacity: 1 }, { transform: 'translate(-50%, -50%) scale(1.3)', opacity: 0 }],
      { duration: 600, easing: 'ease-out' },
    ).onfinish = () => ring.remove();
  }, true);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
}
