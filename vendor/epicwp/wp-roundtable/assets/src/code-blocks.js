/** Wire Copy buttons on dark code cards inside #roundtable-app. */
export function initCodeCopyDelegation() {
  if (typeof document === 'undefined') return;
  document.addEventListener('click', (e) => {
    const btn = e.target.closest?.('.rt-ccode-copy');
    if (!btn || !btn.closest('#roundtable-app')) return;
    e.preventDefault();
    const code = btn.closest('.rt-ccode')?.querySelector('code')?.textContent;
    if (!code) return;
    const write = navigator.clipboard?.writeText?.(code);
    if (write && typeof write.then === 'function') {
      write.then(() => flashCopied(btn)).catch(() => flashCopied(btn));
    } else {
      flashCopied(btn);
    }
  });
}

/** @param {HTMLElement} btn */
function flashCopied(btn) {
  const prev = btn.textContent;
  btn.textContent = 'Copied';
  btn.classList.add('on');
  setTimeout(() => {
    btn.textContent = prev;
    btn.classList.remove('on');
  }, 1400);
}