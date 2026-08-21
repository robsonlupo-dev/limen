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

    // Um endpoint JSON NUNCA responde com redirect. Quando responde (validação de
    // rota web sem FailsValidationAsJson, sessão/CSRF expirada → volta pra tela de
    // login), o fetch SEGUE o 302 até uma página 200 e `response.ok` fica true — o
    // chamador leria como SUCESSO uma ação que na verdade falhou (o sucesso falso da
    // gorjeta na sala ao vivo). Trata redirect como falha, sempre, com motivo real.
    if (response.redirected) {
        const error = new Error('Request redirected')
        error.status = response.status
        error.redirected = true
        error.data = { message: 'Não foi possível confirmar a ação. Recarregue a página e tente novamente.' }
        throw error
    }

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

    // Mesma guarda do request() JSON: um redirect seguido (sessão/validação de rota
    // web) não é sucesso, mesmo que o fetch termine em 200.
    if (response.redirected) {
        const error = new Error('Request redirected')
        error.status = response.status
        error.redirected = true
        error.data = { message: 'Não foi possível confirmar a ação. Recarregue a página e tente novamente.' }
        throw error
    }

    const data = await response.json().catch(() => null)

    if (!response.ok) {
        const error = new Error('Request failed')
        error.status = response.status
        error.data = data
        throw error
    }

    return data
}
