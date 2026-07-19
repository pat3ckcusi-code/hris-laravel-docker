/**
 * push-notifications.js — subscribe/unsubscribe wiring for the sidebar
 * "Enable notifications" toggle. Requires the service worker (registered in
 * partials/pwa-head.blade.php) and the VAPID public key meta tag it exposes.
 */

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function sendSubscription(subscription) {
    await fetch('/push-subscriptions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'Accept': 'application/json',
        },
        body: JSON.stringify(subscription.toJSON()),
    });
}

async function removeSubscription(subscription) {
    await fetch('/push-subscriptions', {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'Accept': 'application/json',
        },
        body: JSON.stringify({ endpoint: subscription.endpoint }),
    });
}

async function subscribe(registration) {
    const vapidKey = document.querySelector('meta[name="vapid-public-key"]')?.content;
    if (!vapidKey) {
        return null;
    }

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey),
    });

    await sendSubscription(subscription);
    return subscription;
}

async function unsubscribe(registration) {
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
        return;
    }

    await subscription.unsubscribe();
    await removeSubscription(subscription);
}

function setToggleState(toggle, enabled) {
    toggle.classList.toggle('is-enabled', enabled);
    toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    toggle.title = enabled ? 'Notifications enabled — click to disable' : 'Enable notifications';
}

async function initPushToggle() {
    const toggle = document.getElementById('push-notification-toggle');
    if (!toggle || !('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }

    const registration = await navigator.serviceWorker.ready;
    const existing = await registration.pushManager.getSubscription();
    setToggleState(toggle, !!existing);

    toggle.addEventListener('click', async () => {
        toggle.disabled = true;

        try {
            const current = await registration.pushManager.getSubscription();

            if (current) {
                await unsubscribe(registration);
                setToggleState(toggle, false);
            } else {
                if (Notification.permission === 'denied') {
                    window.Swal?.fire({
                        icon: 'warning',
                        title: 'Notifications blocked',
                        text: 'Enable notifications for this site in your browser settings, then try again.',
                    });
                    return;
                }

                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    return;
                }

                await subscribe(registration);
                setToggleState(toggle, true);
            }
        } catch (error) {
            console.error('Push subscription toggle failed', error);
        } finally {
            toggle.disabled = false;
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPushToggle);
} else {
    initPushToggle();
}
