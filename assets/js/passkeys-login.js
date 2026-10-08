'use strict';

document.addEventListener('DOMContentLoaded', async () => {
    const loginButton = document.getElementById('loginPasskeyButton');
    const registerButton = document.getElementById('registerPasskeyButton');
    const info = document.getElementById('passkeyInfo');
    const intent = document.getElementById('registerPasskeyIntent');
    if ((!loginButton && !registerButton) || !info) return;

    let csrf = '';
    let busy = false;
    const destination = window.passkeyLoginConfig?.next || 'facturas.php';

    function encode(buffer) {
        let binary = '';
        new Uint8Array(buffer).forEach(byte => { binary += String.fromCharCode(byte); });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    }

    function decode(value) {
        const encoded = value.replace(/-/g, '+').replace(/_/g, '/');
        const binary = atob(encoded + '='.repeat((4 - encoded.length % 4) % 4));
        return Uint8Array.from(binary, character => character.charCodeAt(0));
    }

    function browserOptions(publicKey) {
        publicKey.challenge = decode(publicKey.challenge);
        if (publicKey.user) publicKey.user.id = decode(publicKey.user.id);
        for (const name of ['allowCredentials', 'excludeCredentials']) {
            publicKey[name]?.forEach(item => { item.id = decode(item.id); });
        }
        return publicKey;
    }

    function serialize(credential) {
        const response = {clientDataJSON: encode(credential.response.clientDataJSON)};
        if (credential.response.attestationObject) {
            response.attestationObject = encode(credential.response.attestationObject);
            response.transports = credential.response.getTransports?.() || [];
        } else {
            response.authenticatorData = encode(credential.response.authenticatorData);
            response.signature = encode(credential.response.signature);
            response.userHandle = credential.response.userHandle ? encode(credential.response.userHandle) : null;
        }
        return {id: credential.id, rawId: encode(credential.rawId), type: credential.type, response};
    }

    async function request(action, values = {}) {
        const response = await fetch('api/passkeys.php?accion=' + action, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({csrf, ...values}),
        });
        let data;
        try { data = await response.json(); }
        catch { throw new Error('El servidor no devolvió una respuesta válida.'); }
        if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo completar la verificación.');
        return data;
    }

    function message(error) {
        if (error.name === 'NotAllowedError') return 'La verificación se canceló o no hay una passkey disponible. Puedes usar contraseña y 2FA.';
        if (error.name === 'InvalidStateError') return 'Este dispositivo ya tiene una passkey registrada para esta cuenta.';
        if (error.name === 'SecurityError') return 'El dominio no coincide con la configuración de passkeys.';
        if (error.name === 'NotSupportedError') return 'Este dispositivo no admite la passkey solicitada.';
        return error.message || 'No fue posible usar la passkey.';
    }

    function setBusy(value, button) {
        busy = value;
        button.disabled = value;
        if (value) {
            button.dataset.originalText = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Verificando...';
        } else {
            button.innerHTML = button.dataset.originalText || button.innerHTML;
            if (window.lucide) window.lucide.createIcons();
        }
    }

    async function alertError(error) {
        const description = message(error);
        info.textContent = description;
        if (window.Swal) await Swal.fire({icon: 'error', text: description, confirmButtonText: 'Aceptar'});
    }

    loginButton?.addEventListener('click', async () => {
        if (busy || !csrf) return;
        setBusy(true, loginButton);
        try {
            const data = await request('entrar_opciones');
            const credential = await navigator.credentials.get({publicKey: browserOptions(data.publicKey)});
            if (!credential) throw new Error('El dispositivo no devolvió una credencial.');
            await request('entrar_verificar', {credential: serialize(credential)});
            window.location.assign(destination);
        } catch (error) {
            await alertError(error);
        } finally {
            setBusy(false, loginButton);
        }
    });

    registerButton?.addEventListener('click', async () => {
        if (busy || !csrf) return;
        setBusy(true, registerButton);
        try {
            const data = await request('registrar_opciones');
            const credential = await navigator.credentials.create({publicKey: browserOptions(data.publicKey)});
            if (!credential) throw new Error('El dispositivo no devolvió una credencial.');
            await request('registrar_verificar', {
                credential: serialize(credential),
                nombre: document.getElementById('passkeyName').value.trim(),
            });
            if (window.Swal) await Swal.fire({icon: 'success', text: 'Passkey registrada. Podrás usarla en tu próximo acceso.', confirmButtonText: 'Continuar'});
            window.location.assign(destination);
        } catch (error) {
            await alertError(error);
        } finally {
            setBusy(false, registerButton);
        }
    });

    try {
        const response = await fetch('api/passkeys.php?accion=bootstrap', {credentials: 'same-origin', cache: 'no-store'});
        const data = await response.json();
        if (!response.ok || !data.ok || !data.csrf) throw new Error('No se pudo preparar el acceso con passkeys.');
        csrf = data.csrf;
        let available = window.isSecureContext && !!window.PublicKeyCredential && !!navigator.credentials;
        if (available && typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable === 'function') {
            available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
        }
        const ready = available && data.disponible === true;
        if (loginButton) loginButton.disabled = !ready;
        if (registerButton) registerButton.disabled = !ready;
        if (intent) intent.disabled = !ready;
        info.textContent = ready
            ? 'El dispositivo puede pedir huella, rostro o PIN; la web no recibe esos datos.'
            : 'Las passkeys no están disponibles aquí. Puedes entrar con contraseña y 2FA.';
    } catch (error) {
        info.textContent = message(error);
        if (intent) intent.disabled = true;
    }
});
