/**
 * Redução de imagem NO NAVEGADOR antes do upload (achado do UAT: foto de celular
 * de 10+ MB levava ~1 min pra subir; o servidor reduz para 1200px de qualquer
 * jeito, então mandar 4000px é só custo de upload).
 *
 * Fail-open deliberado: QUALQUER problema (tipo não suportado, canvas bloqueado,
 * navegador antigo) devolve o arquivo ORIGINAL — o servidor continua sendo quem
 * valida e re-encoda (EXIF strip, anti-polyglot, CSAM). Isto aqui é só economia
 * de banda, nunca uma etapa de segurança.
 *
 * `imageOrientation: 'from-image'` aplica a rotação EXIF no decode — o arquivo
 * reduzido já sai "de pé", igual ao preview, então as coordenadas do oval de
 * rosto batem com o que a performer vê.
 */
export async function downscaleImage(file, maxSide = 2048, quality = 0.9) {
    try {
        if (!file || !/^image\/(jpeg|png)$/.test(file.type)) return file

        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
            .catch(() => createImageBitmap(file))

        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height))
        if (scale >= 1) {
            bitmap.close?.()
            return file // já é pequena — não re-encodar à toa
        }

        const w = Math.max(1, Math.round(bitmap.width * scale))
        const h = Math.max(1, Math.round(bitmap.height * scale))
        const canvas = document.createElement('canvas')
        canvas.width = w
        canvas.height = h
        const ctx = canvas.getContext('2d')
        // PNG com transparência viraria fundo PRETO no JPEG; branco é o neutro.
        ctx.fillStyle = '#fff'
        ctx.fillRect(0, 0, w, h)
        ctx.drawImage(bitmap, 0, 0, w, h)
        bitmap.close?.()

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality))
        if (!blob || blob.size === 0) return file

        const name = String(file.name || 'foto').replace(/\.\w+$/, '') + '.jpg'
        return new File([blob], name, { type: 'image/jpeg' })
    } catch {
        return file
    }
}
