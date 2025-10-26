(function(){
    var key='vl_las_cookie_consent';
    function setPosition(){
        var pos = (window.VLLAS_COOKIE && window.VLLAS_COOKIE.position) || 'bottom-right';
        var el = document.getElementById('vl-las-cookie-banner');
        if(!el) return;
        el.classList.remove('bottom-right','bottom-left');
        el.classList.add(pos);
    }
    function maybeShow(){
        try {
            var el = document.getElementById('vl-las-cookie-banner');
            if(!el){ console.warn('[VL-LAS] Cookie banner element not found (check wp_footer/wp_body_open).'); return; }
            var vis = (window.VLLAS_COOKIE && window.VLLAS_COOKIE.visibility) || 'show';
            var force = window.VLLAS_COOKIE && window.VLLAS_COOKIE.forceShow;
            if(vis === 'hide' && !force){ console.info('[VL-LAS] Banner hidden by settings.'); return; }
            if(!force && localStorage.getItem(key)){ console.info('[VL-LAS] Consent already recorded:', localStorage.getItem(key)); return; }
            setPosition();
            el.classList.remove('hidden');
            el.querySelector('.accept').addEventListener('click', function(){
                localStorage.setItem(key,'accepted');
                el.classList.add('hidden');
            });
            el.querySelector('.reject').addEventListener('click', function(){
                localStorage.setItem(key,'rejected');
                el.classList.add('hidden');
            });
        } catch(e){
            console.error('[VL-LAS] Cookie banner error:', e);
        }
    }
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', maybeShow);
    } else {
        maybeShow();
    }
})();