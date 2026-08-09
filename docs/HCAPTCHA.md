# hCaptcha → ver docs/CAPTCHA.md

**Movido.** Desde o Sprint 16 o captcha é um **driver abstrato** com dois
provedores (hCaptcha e Cloudflare Turnstile), selecionados por `CAPTCHA_PROVIDER`.
A documentação canônica — arquitetura, como alternar os provedores, bloqueadores
de conformidade e passos para ativar — vive em **[docs/CAPTCHA.md](CAPTCHA.md)**.

O hCaptcha continua sendo um dos provedores (`CAPTCHA_PROVIDER=hcaptcha`,
`HCAPTCHA_SITEKEY`/`HCAPTCHA_SECRET`); nada do que valia para ele mudou, só deixou
de ser o único.
