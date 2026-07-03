// Small cookie helpers for UI-only flags (age gate, intro). These cookies are
// intentionally readable/writable by JS (not httpOnly) and carry no secret.
// The matching cookie names are exempt from Laravel's cookie encryption in
// bootstrap/app.php so the server can read the plaintext value we set here.

export function setCookie(name, value, days) {
    const expires = new Date()
    expires.setTime(expires.getTime() + days * 24 * 60 * 60 * 1000)
    const secure = window.location.protocol === 'https:' ? '; Secure' : ''
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; expires=${expires.toUTCString()}; SameSite=Lax${secure}`
}

export function hasCookie(name) {
    return document.cookie
        .split('; ')
        .some((entry) => entry.startsWith(`${name}=`))
}
