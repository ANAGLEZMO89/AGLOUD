# AGLOUD

Higgsfield API example: text-to-video with Seedance 2.5 (`bytedance/seedance-2.5/text-to-video`) via the official `@higgsfield/client` SDK (v2).

## Setup

```bash
npm install
cp .env.example .env.local   # then edit .env.local locally
```

`.env.local` must contain `HF_CREDENTIALS=key-id:key-secret`. It is git-ignored; never commit it.
An `HF_CREDENTIALS` variable already present in the environment also works (it takes precedence over `.env.local`).

## Run

```bash
npm start        # billable generation request
npm run typecheck
```

Prints the video URL on `completed`; exits with code 1 on `failed`, `nsfw` (moderated), `canceled`, timeouts or API errors.

Note: `@higgsfield/client` 0.2.6 maps every HTTP 403 to `NotEnoughCreditsError`, so a 403 can also mean a proxy/firewall blocked `api.higgsfield.ai`.
