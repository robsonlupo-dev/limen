function csrfToken() {
    return document.head.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

async function request(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    })

    const data = await response.json().catch(() => null)

    if (!response.ok) {
        const error = new Error('Request failed')
        error.status = response.status
        error.data = data
        throw error
    }

    return data
}

export function postJson(url, body) {
    return request('POST', url, body)
}

export function patchJson(url, body) {
    return request('PATCH', url, body)
}

export function putJson(url, body) {
    return request('PUT', url, body)
}

export function getJson(url) {
    return request('GET', url)
}

export function deleteJson(url) {
    return request('DELETE', url)
}

/**
 * Upload multipart. Não passa pelo request() acima de propósito: aquele fixa
 * `Content-Type: application/json`, e num FormData o boundary tem de ser
 * gerado pelo navegador — definir o header à mão quebra o parse no servidor.
 */
export async function postForm(url, formData) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: formData,
    })

    const data = await response.json().catch(() => null)

    if (!response.ok) {
        const error = new Error('Request failed')
        error.status = response.status
        error.data = data
        throw error
    }

    return data
}
