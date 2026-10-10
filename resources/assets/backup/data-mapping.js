const dataMapping = (function(window, document) {
    'use strict';
    
    function handleWriterClick(e, el) {
        // Find the closest mapping table
        const container = el.closest('[data-mapping-table]');

        if (!container) return;

        const writer = el.getAttribute('data-writer');

        // Search only inside this mapping table
        const readerEl = container.querySelector('[data-reader="'+writer+'"]');

        if (readerEl) {
            if (el.checked) {
                readerEl.removeAttribute('disabled');
            } else {
                readerEl.setAttribute('disabled', 'disabled');
            }
        }
        
        const modeEl = container.querySelector('[data-mode="'+writer+'"]');

        if (modeEl) {
            if (el.checked) {
                modeEl.removeAttribute('disabled');
            } else {
                modeEl.setAttribute('disabled', 'disabled');
            }
        }
    }
    
    document.addEventListener('DOMContentLoaded', (e) => {
        // we add click event globally as not to loose listeners
        // on update DOM e.g. index action.
        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-writer]');
            
            if (el) {
                handleWriterClick(e, el);
            }
        });
    });
    
    return null;
    
})(window, document);

export default dataMapping;