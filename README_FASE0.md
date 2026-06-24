# Fase 0 — Como instalar (passo a passo)

Extraia este conteúdo na RAIZ do seu projeto Laravel (a pasta onde está o artisan).
Os arquivos vão se encaixar nas pastas certas (.claude, .github, bin, etc).

## Ordem dos comandos (rode no terminal Ubuntu, dentro da pasta do projeto)

1) Ajuste o .env
   - Abra o arquivo `.env` e aplique as linhas de `.env.limen-trecho.txt`
     (troca SQLite por MySQL).
   - Confira o `.gitignore` com base em `.gitignore-adicionar.txt`.

2) Suba tudo com um comando:
   bash bin/bootstrap.sh

3) Rode a aplicação:
   php artisan serve
   -> abra http://localhost:8000

## Checkpoint (me mande print/confirmação de cada um)
- [ ] `bin/bootstrap.sh` terminou com "✅ Ambiente Limen pronto."
- [ ] http://localhost:8000 abre a página do Laravel sem erro
- [ ] http://localhost:8080 (Adminer) loga no MySQL e mostra a base `limen` com as tabelas das migrations
- [ ] `CLAUDE.md` está na raiz do projeto

Quando os 4 estiverem ok, me avise "Fase 0 aprovada" e eu te entrego a Fase 1.
