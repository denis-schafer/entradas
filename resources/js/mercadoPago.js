/**
 * Abre el checkout de MercadoPago.
 *
 * En Android intenta abrir directamente la app de MercadoPago con un intent://
 * apuntando a la URL del checkout. Si la app no esta instalada (o no maneja esa
 * URL), el navegador usa el fallback y a los 1.8s se cae al checkout web.
 *
 * En iOS el init_point es un universal link: el sistema se encarga de abrir la
 * app si esta instalada; si no, sigue en el navegador. En desktop va directo al
 * checkout web. El sandbox no se fuerza a la app porque la app productiva no
 * maneja esa URL.
 */
export function redirectToMercadoPago(url) {
    if (!url) {
        return;
    }

    const isAndroid = /Android/i.test(navigator.userAgent || '');
    const isSandbox = /sandbox\.mercadopago/i.test(url);

    if (!isAndroid || isSandbox) {
        window.location.href = url;

        return;
    }

    try {
        const parsed = new URL(url);
        const fallback = encodeURIComponent(url);
        const intent = `intent://${parsed.host}${parsed.pathname}${parsed.search}`
            + `#Intent;scheme=https;package=com.mercadopago.wallet;S.browser_fallback_url=${fallback};end`;
        const started = Date.now();

        window.location.href = intent;

        setTimeout(() => {
            // Si la app se abrio, la pagina queda oculta y no hay que pisar el
            // checkout. Si no se abrio, seguimos por la web.
            if (document.visibilityState === 'visible' && Date.now() - started < 5000) {
                window.location.href = url;
            }
        }, 1800);
    } catch {
        window.location.href = url;
    }
}
